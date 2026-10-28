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
use Drupal\Core\Url;
use Quebracho\X402\Networks;
use Quebracho\X402\Signer;

/**
 * Makes any fieldable entity payable over x402 by adding the field.
 *
 * Each field is one product: a view mode at a price. Its settings hold the
 * price, the network and who is paid: the seller key, whose own address
 * gets the money, or, with a Circle API key, just an address (a cold
 * wallet), so no key that holds money lives on the site. Each entity can
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
      'network' => 'eip155:5042002',
      'key_id' => '',
      'pay_to' => '',
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
   * NULL when the field sells to an address alone.
   *
   * @throws \RuntimeException
   *   A key named but missing, or not an EVM private key.
   */
  public function seller(): ?Signer {
    $id = (string) $this->getSetting('key_id');
    if ($id === '') {
      return NULL;
    }
    $key = \Drupal::service('key.repository')->getKey($id);
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
   * The address paid: the key's own, else the field's address.
   *
   * @throws \RuntimeException
   *   Neither a usable key nor an address.
   */
  public function payTo(): string {
    if ($seller = $this->seller()) {
      return $seller->address();
    }
    $address = (string) $this->getSetting('pay_to');
    return match (TRUE) {
      (bool) preg_match('/^0x[0-9a-fA-F]{40}$/', $address) => Signer::checksum($address),
      self::isSolanaAddress($address) => $address,
      default => throw new \RuntimeException('The x402 price field has no seller key nor address.'),
    };
  }

  /**
   * Whether it looks like a Solana address: 32 to 44 base58 characters.
   *
   * The shape only (ponytail): the facilitator refuses a wrong key.
   */
  public static function isSolanaAddress(string $address): bool {
    return (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address);
  }

  /**
   * What the buyer must pay, as one x402 PaymentRequirements.
   *
   * @param string|null $usd
   *   The price to charge, e.g. after the price event; the field's when NULL.
   */
  public function requirements(?string $usd = NULL): array {
    return Networks::requirements((string) $this->getSetting('network'), $usd ?? $this->price(), $this->payTo());
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
        '#options' => array_map(fn($n) => $n['label'], Networks::all()),
        '#default_value' => $this->getSetting('network'),
        '#required' => TRUE,
      ],
      'key_id' => [
        '#type' => 'key_select',
        '#title' => $this->t('Seller key'),
        '#description' => $this->t("The seller wallet's private key, kept in the Key module (an environment variable, never exportable config). Payments go to this key's address. Leave empty to sell to an address alone."),
        '#empty_option' => $this->t('- None: an address alone -'),
        '#default_value' => $this->getSetting('key_id'),
        '#element_validate' => [[static::class, 'validateKey']],
      ],
      'pay_to' => [
        '#type' => 'textfield',
        '#title' => $this->t('Seller address'),
        '#description' => $this->t('Without a key: the wallet paid (EVM, or Solana in base58), e.g. a cold wallet whose key is not on this site. Settled by Circle, it needs a Circle API key in the x402 settings, since the keyless trial signs every sale with the seller key.'),
        '#default_value' => $this->getSetting('pay_to'),
        '#maxlength' => 44,
        '#element_validate' => [[static::class, 'validatePayTo']],
        '#states' => ['visible' => [':input[name="settings[key_id]"]' => ['value' => '']]],
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
    $networks = Networks::all();
    $network = $networks[$form_state->getValue(['settings', 'network'])] ?? reset($networks);
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
   * Form validation: no key, or one that holds a usable EVM private key.
   */
  public static function validateKey(array $element, FormStateInterface $form_state): void {
    if ((string) $element['#value'] === '') {
      return;
    }
    $key = \Drupal::service('key.repository')->getKey((string) $element['#value']);
    try {
      new Signer(trim((string) $key?->getKeyValue()));
    }
    catch (\InvalidArgumentException) {
      $form_state->setError($element, t('The key must hold an EVM private key: 0x followed by 64 hex characters.'));
    }
  }

  /**
   * Form validation: without a key, an address and a Circle API key.
   */
  public static function validatePayTo(array $element, FormStateInterface $form_state): void {
    $network = (string) $form_state->getValue(['settings', 'network']);
    $key = (string) $form_state->getValue(['settings', 'key_id']);
    $address = trim((string) $element['#value']);
    // Seller keys are EVM keys: Solana sells to an address alone.
    if (str_starts_with($network, 'solana:')) {
      if ($key !== '' || !self::isSolanaAddress($address)) {
        $form_state->setError($element, t('On Solana, no seller key: give the Solana address paid, in base58.'));
      }
      return;
    }
    if ($key !== '') {
      return;
    }
    if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
      $form_state->setError($element, t('Choose a seller key, or give the address paid: 0x followed by 40 hex characters.'));
    }
    // Circle needs its API key to settle for an address alone; ours or
    // another facilitator do not.
    elseif (!\Drupal::service('x402_pay.facilitators')->settlesItself($network)
      && !\Drupal::service('x402_pay.facilitators')->hasRemote($network)
      && \Drupal::config('x402_pay.settings')->get('facilitator.auth') !== 'api_key') {
      $form_state->setError($element, t('Selling to an address alone needs a Circle API key: set it in the <a href=":url">x402 settings</a>, or choose a seller key.', [':url' => Url::fromRoute('x402_pay.settings')->toString()]));
    }
  }

}
