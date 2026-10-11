<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Plugin\Field\FieldType;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
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
 * Each field is one product: a view mode at a price. Its settings hold the
 * price, the network and the seller key; the money goes to the key's own
 * address, so it cannot go to a wallet that does not sign. Each entity can
 * switch charging off and, when the field allows it, set its own price; a
 * translatable field gives each translation its own. A bundle can have
 * several fields, each with its own view mode, and at most one of them
 * charges on the entity's own URL.
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

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings(): array {
    return [
      'enabled' => TRUE,
      'amount' => '0.01',
      'network' => array_key_first(Networks::ALL),
      'key_id' => '',
      'allow_override' => FALSE,
      'view_mode' => 'full',
      'canonical' => TRUE,
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'enabled' => DataDefinition::create('boolean')->setLabel(new TranslatableMarkup('Charge for this')),
      'amount' => DataDefinition::create('string')->setLabel(new TranslatableMarkup('Own price in USD'))
        ->addConstraint('Regex', ['pattern' => '/^\d+(\.\d{1,6})?$/']),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): string {
    return 'enabled';
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'enabled' => ['type' => 'int', 'size' => 'tiny'],
        'amount' => ['type' => 'varchar_ascii', 'length' => 32],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    return $this->enabled === NULL && ($this->amount ?? '') === '';
  }

  /**
   * The payable item that sells a view mode of the entity, if any.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity sold.
   * @param string|null $viewMode
   *   NULL: the one sold on the entity's own URL.
   */
  public static function find(EntityInterface $entity, ?string $viewMode = NULL): ?self {
    foreach (self::all($entity) as $item) {
      if ($viewMode === NULL ? $item->getSetting('canonical') : $item->getSetting('view_mode') === $viewMode) {
        return $item;
      }
    }
    return NULL;
  }

  /**
   * Every payable item of the entity: everything it sells right now.
   *
   * @return self[]
   *   The items, one per field.
   */
  public static function all(EntityInterface $entity): array {
    $items = [];
    if ($entity instanceof FieldableEntityInterface) {
      foreach ($entity->getFieldDefinitions() as $name => $definition) {
        $item = $definition->getType() === 'x402_price' ? $entity->get($name)->first() : NULL;
        if ($item instanceof self && $item->isPayable()) {
          $items[] = $item;
        }
      }
    }
    return $items;
  }

  /**
   * Why this field cannot sit next to the others on its bundle, if it can't.
   *
   * Two fields selling the same view mode would be one thing at two prices;
   * two on the canonical URL, two prices for one URL.
   */
  public static function conflict(FieldDefinitionInterface $field): ?TranslatableMarkup {
    $others = \Drupal::service('entity_field.manager')->getFieldDefinitions($field->getTargetEntityTypeId(), $field->getTargetBundle());
    foreach ($others as $other) {
      if ($other->getType() !== 'x402_price' || $other->getName() === $field->getName()) {
        continue;
      }
      if ($other->getSetting('view_mode') === $field->getSetting('view_mode')) {
        return t('The field %other already sells the %mode view mode.', [
          '%other' => $other->getLabel(),
          '%mode' => $field->getSetting('view_mode'),
        ]);
      }
      if ($other->getSetting('canonical') && $field->getSetting('canonical')) {
        return t("The field %other already charges on the entity's own URL.", ['%other' => $other->getLabel()]);
      }
    }
    return NULL;
  }

  /**
   * The view mode this field sells.
   */
  public function viewMode(): string {
    return (string) $this->getSetting('view_mode');
  }

  /**
   * Whether this field charges on the entity's own URL.
   */
  public function isCanonical(): bool {
    return (bool) $this->getSetting('canonical');
  }

  /**
   * Whether this entity is charged at all: field and entity on.
   */
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
   *
   * @param string|null $usd
   *   The price to charge, e.g. after the price event; the field's when NULL.
   */
  public function requirements(?string $usd = NULL): array {
    return Networks::requirements((string) $this->getSetting('network'), $usd ?? $this->price(), $this->seller()->address());
  }

  /**
   * {@inheritdoc}
   */
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
        '#description' => $this->t('Make the field translatable to price each translation on its own.'),
        '#default_value' => $this->getSetting('allow_override'),
      ],
      'view_mode' => [
        '#type' => 'select',
        '#title' => $this->t('What is sold'),
        '#description' => $this->t('The view mode delivered as markdown. Only its fields are sent, never the page around them.'),
        '#options' => \Drupal::service('entity_display.repository')->getViewModeOptions($this->getFieldDefinition()->getTargetEntityTypeId()) + ['full' => $this->t('Full content')],
        '#default_value' => $this->getSetting('view_mode'),
        '#required' => TRUE,
        '#element_validate' => [[static::class, 'validateConflict']],
      ],
      'canonical' => [
        '#type' => 'checkbox',
        '#title' => $this->t("Charge agents on the entity's own URL"),
        '#description' => $this->t('Browsers still see the page there. Every field is also sold at /x402/{entity type}/{id}/{view mode}.'),
        '#default_value' => $this->getSetting('canonical'),
      ],
    ];
  }

  /**
   * Form validation: a price the network can charge exactly.
   */
  public static function validateAmount(array $element, FormStateInterface $form_state): void {
    $network = Networks::ALL[$form_state->getValue(['settings', 'network'])] ?? reset(Networks::ALL);
    try {
      Networks::atomic(trim((string) $element['#value']), $network['decimals']);
    }
    catch (\InvalidArgumentException) {
      $form_state->setError($element, t('A price above zero, with a dot and at most @n decimals.', ['@n' => $network['decimals']]));
    }
  }

  /**
   * Form validation: no other field sells this view mode.
   */
  public static function validateConflict(array $element, FormStateInterface $form_state): void {
    $field = clone $form_state->getFormObject()->getEntity();
    $field->setSetting('view_mode', $form_state->getValue(['settings', 'view_mode']));
    $field->setSetting('canonical', (bool) $form_state->getValue(['settings', 'canonical']));
    if ($conflict = static::conflict($field)) {
      $form_state->setError($element, $conflict);
    }
  }

  /**
   * Form validation: the key holds a usable EVM private key.
   */
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
