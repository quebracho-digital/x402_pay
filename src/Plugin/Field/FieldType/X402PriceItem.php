<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Plugin\Field\FieldType;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\x402_pay\Networks;
use Drupal\x402_pay\Signer;

/**
 * Makes any fieldable entity payable over x402 by adding the field.
 *
 * The field's settings hold the price, the network and the seller key; the
 * money goes to the key's own address, so it cannot go to a wallet that
 * does not sign. Each entity can switch charging off and, when the field
 * allows it, set its own price.
 *
 * @property bool|null $enabled
 * @property string|null $amount
 */
#[FieldType(
  id: 'x402_price',
  label: new TranslatableMarkup('x402 price'),
  description: new TranslatableMarkup('Sells the entity to AI agents over x402, in USDC.'),
  default_widget: 'x402_price_default',
  default_formatter: 'x402_price_default',
  cardinality: 1,
)]
class X402PriceItem extends FieldItemBase {

  public static function defaultFieldSettings(): array {
    return [
      'enabled' => TRUE,
      'amount' => '0.01',
      'network' => array_key_first(Networks::ALL),
      'key_id' => '',
      'allow_override' => FALSE,
    ] + parent::defaultFieldSettings();
  }

  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'enabled' => DataDefinition::create('boolean')->setLabel(new TranslatableMarkup('Charge for this')),
      'amount' => DataDefinition::create('string')->setLabel(new TranslatableMarkup('Own price in USD'))
        ->addConstraint('Regex', ['pattern' => '/^\d+(\.\d{1,6})?$/']),
    ];
  }

  public static function mainPropertyName(): string {
    return 'enabled';
  }

  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'enabled' => ['type' => 'int', 'size' => 'tiny'],
        'amount' => ['type' => 'varchar_ascii', 'length' => 32],
      ],
    ];
  }

  public function isEmpty(): bool {
    return $this->enabled === NULL && ($this->amount ?? '') === '';
  }

  /**
   * The entity's payable price item, or NULL when it is free.
   */
  public static function find(EntityInterface $entity): ?self {
    if (!$entity instanceof FieldableEntityInterface) {
      return NULL;
    }
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() === 'x402_price') {
        $item = $entity->get($name)->first();
        if ($item instanceof self && $item->isPayable()) {
          return $item;
        }
      }
    }
    return NULL;
  }

  public function isPayable(): bool {
    return $this->getSetting('enabled') && $this->enabled;
  }

  /**
   * The price in dollars: the entity's own when allowed and set.
   */
  public function price(): string {
    $own = $this->getSetting('allow_override') ? (string) $this->amount : '';
    return $own !== '' ? $own : (string) $this->getSetting('amount');
  }

  /**
   * The seller's key, from the Key module.
   *
   * @throws \RuntimeException
   *   No key configured, or not an EVM private key.
   */
  public function seller(): Signer {
    $key = \Drupal::service('key.repository')->getKey((string) $this->getSetting('key_id'));
    if (!$key) {
      throw new \RuntimeException('The x402 price field has no seller key.');
    }
    try {
      return new Signer(trim((string) $key->getKeyValue()));
    }
    catch (\InvalidArgumentException $e) {
      throw new \RuntimeException('The seller key is not an EVM private key.', 0, $e);
    }
  }

  /**
   * What the buyer must pay, as one x402 PaymentRequirements.
   */
  public function requirements(): array {
    return Networks::requirements((string) $this->getSetting('network'), $this->price(), $this->seller()->address());
  }

  public function fieldSettingsForm(array $form, FormStateInterface $form_state): array {
    return [
      'enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Charge AI agents for entities with this field'),
        '#description' => $this->t('Off: every entity is free, whatever its own setting.'),
        '#default_value' => $this->getSetting('enabled'),
      ],
      'amount' => [
        '#type' => 'textfield',
        '#title' => $this->t('Price in USD'),
        '#default_value' => $this->getSetting('amount'),
        '#size' => 12,
        '#required' => TRUE,
        '#element_validate' => [[static::class, 'validateAmount']],
      ],
      'network' => [
        '#type' => 'select',
        '#title' => $this->t('Network'),
        '#options' => array_map(fn($n) => $n['label'], Networks::ALL),
        '#default_value' => $this->getSetting('network'),
        '#required' => TRUE,
      ],
      'key_id' => [
        '#type' => 'key_select',
        '#title' => $this->t('Seller key'),
        '#description' => $this->t("The seller wallet's private key, kept in the Key module (an environment variable, never exportable config). Payments go to this key's address."),
        '#default_value' => $this->getSetting('key_id'),
        '#required' => TRUE,
        '#element_validate' => [[static::class, 'validateKey']],
      ],
      'allow_override' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Each entity can set its own price'),
        '#default_value' => $this->getSetting('allow_override'),
      ],
    ];
  }

  public static function validateAmount(array $element, FormStateInterface $form_state): void {
    $network = Networks::ALL[$form_state->getValue(['settings', 'network'])] ?? reset(Networks::ALL);
    try {
      Networks::atomic(trim((string) $element['#value']), $network['decimals']);
    }
    catch (\InvalidArgumentException) {
      $form_state->setError($element, t('A price above zero, with a dot and at most @n decimals.', ['@n' => $network['decimals']]));
    }
  }

  public static function validateKey(array $element, FormStateInterface $form_state): void {
    $key = \Drupal::service('key.repository')->getKey((string) $element['#value']);
    try {
      new Signer(trim((string) $key?->getKeyValue()));
    }
    catch (\InvalidArgumentException) {
      $form_state->setError($element, t('The key must hold an EVM private key: 0x followed by 64 hex characters.'));
    }
  }

}
