<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\x402_pay\Networks;
use Drupal\x402_pay\Signer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Which facilitator settles each network, and where seller wallets are swept.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    protected KeyRepositoryInterface $keys,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('config.factory'), $container->get('config.typed'), $container->get('key.repository'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'x402_pay_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['x402_pay.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('x402_pay.settings');
    $form['facilitator'] = [
      '#type' => 'details',
      '#title' => $this->t('Facilitator'),
      '#open' => TRUE,
      '#tree' => TRUE,
      'auth' => [
        '#type' => 'radios',
        '#title' => $this->t('How the site reaches Circle'),
        '#options' => [
          'keyless' => $this->t('Keyless trial: each sale is signed by the seller key, so that key lives on this site. Limited allowance per seller and network.'),
          'api_key' => $this->t('Circle API key: sellers can be cold wallets, with no key on this site. The first sale binds each seller address to the Circle account.'),
        ],
        '#default_value' => $config->get('facilitator.auth') ?? 'keyless',
      ],
      'api_key_id' => [
        '#type' => 'key_select',
        '#title' => $this->t('Circle API key'),
        '#description' => $this->t('From the Key module, e.g. an environment variable. Testnet and mainnet use different keys.'),
        '#empty_option' => $this->t('- Choose a key -'),
        '#default_value' => $config->get('facilitator.api_key_id'),
        '#states' => ['visible' => [':input[name="facilitator[auth]"]' => ['value' => 'api_key']]],
      ],
      'own_networks' => [
        '#type' => 'checkboxes',
        '#title' => $this->t('Networks this site settles itself'),
        '#description' => $this->t("Our own facilitator instead of Circle's: the site sends each buyer's signed transfer on chain. Sellers can be addresses alone."),
        '#options' => array_map(fn($n) => $n['label'], array_filter(Networks::ALL, fn($id) => str_starts_with($id, 'eip155:'), ARRAY_FILTER_USE_KEY)),
        '#default_value' => $config->get('facilitator.own_networks') ?? [],
      ],
      'own_key_id' => [
        '#type' => 'select',
        '#title' => $this->t('Gas wallet'),
        '#description' => $this->t('Pays the gas of each sale, in USDC on Arc, and nothing else: sales go from the buyer to the seller. Keep a few dollars in it.'),
        '#options' => $this->keyOptions(),
        '#empty_option' => $this->t('- Choose a key -'),
        '#default_value' => $config->get('facilitator.own_key_id'),
      ],
      'remote' => [
        '#type' => 'details',
        '#title' => $this->t('Another facilitator, per network'),
        '#description' => $this->t("Any x402 facilitator, by its base URL (before /verify, /settle and /supported), e.g. https://x402.org/facilitator for Solana devnet. It settles the network unless this site does; Circle's settles the rest. On Solana it also pays the fees."),
        '#open' => (bool) $config->get('facilitator.remote'),
      ],
    ];
    foreach (Networks::ALL as $network => $info) {
      $form['facilitator']['remote'][$network] = [
        '#type' => 'fieldset',
        '#title' => $info['label'],
        'url' => [
          '#type' => 'url',
          '#title' => $this->t('URL'),
          '#default_value' => $config->get('facilitator.remote')[$network]['url'] ?? '',
        ],
        'key_id' => [
          '#type' => 'key_select',
          '#title' => $this->t('Bearer token'),
          '#description' => $this->t('From the Key module, if the facilitator asks for one.'),
          '#empty_option' => $this->t('- None -'),
          '#default_value' => $config->get('facilitator.remote')[$network]['key_id'] ?? '',
        ],
      ];
    }

    $sweep = $config->get('sweep') ?? [];
    $form['sweep'] = [
      '#type' => 'details',
      '#title' => $this->t('Sweep seller wallets'),
      '#description' => $this->t('A seller key on this site holds what it collects. Sweeping moves it to a wallet whose key is not here, so a compromised site loses at most what arrived since the last sweep. Each wallet pays its own gas, in USDC on Arc. Run it now with <code>drush x402:sweep</code>.'),
      '#open' => TRUE,
      '#tree' => TRUE,
      'enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Sweep on cron'),
        '#default_value' => $sweep['enabled'] ?? FALSE,
      ],
      'to' => [
        '#type' => 'textfield',
        '#title' => $this->t('Cold wallet address'),
        '#description' => $this->t('A hardware wallet or a multisig. Never one whose key is on this site.'),
        '#default_value' => $sweep['to'] ?? '',
        '#maxlength' => 42,
      ],
      'keys' => [
        '#type' => 'checkboxes',
        '#title' => $this->t('Seller keys to sweep'),
        '#options' => $this->keyOptions(),
        '#default_value' => $sweep['keys'] ?? [],
      ],
      'min' => [
        '#type' => 'textfield',
        '#title' => $this->t('Smallest sweep, in USDC'),
        '#description' => $this->t('Below this a wallet waits: each sweep pays gas.'),
        '#default_value' => $sweep['min'] ?? '1',
        '#size' => 10,
      ],
      'interval' => [
        '#type' => 'select',
        '#title' => $this->t('How often'),
        '#options' => [
          3600 => $this->t('Every hour'),
          21600 => $this->t('Every 6 hours'),
          86400 => $this->t('Every day'),
        ],
        '#default_value' => $sweep['interval'] ?? 21600,
      ],
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $facilitator = $form_state->getValue('facilitator');
    if ($facilitator['auth'] === 'api_key' && trim((string) $this->key($facilitator['api_key_id'])) === '') {
      $form_state->setErrorByName('facilitator][api_key_id', $this->t('Choose a key that holds the Circle API key.'));
    }
    if (array_filter($facilitator['own_networks']) && !$facilitator['own_key_id']) {
      $form_state->setErrorByName('facilitator][own_key_id', $this->t('Settling on chain needs a gas wallet.'));
    }
    foreach (array_filter($facilitator['own_networks']) as $network) {
      if (trim((string) $facilitator['remote'][$network]['url']) !== '') {
        $form_state->setErrorByName("facilitator][remote][$network][url", $this->t('%network is settled by this site: leave its other facilitator empty.', ['%network' => Networks::ALL[$network]['label']]));
      }
    }

    $sweep = $form_state->getValue('sweep');
    $keys = array_values(array_filter($sweep['keys']));
    $addresses = [];
    foreach ($keys as $id) {
      try {
        $addresses[] = strtolower((new Signer(trim((string) $this->key($id))))->address());
      }
      catch (\InvalidArgumentException) {
        $form_state->setErrorByName('sweep][keys', $this->t('%key is not an EVM private key.', ['%key' => $id]));
      }
    }
    try {
      Networks::atomic(trim((string) $sweep['min']), 6);
    }
    catch (\InvalidArgumentException) {
      $form_state->setErrorByName('sweep][min', $this->t('An amount above zero, with a dot and at most 6 decimals.'));
    }
    if ($sweep['to'] !== '' && !preg_match('/^0x[0-9a-fA-F]{40}$/', trim($sweep['to']))) {
      $form_state->setErrorByName('sweep][to', $this->t('An address: 0x followed by 40 hex characters.'));
    }
    elseif (in_array(strtolower(trim($sweep['to'])), $addresses, TRUE)) {
      $form_state->setErrorByName('sweep][to', $this->t('The cold wallet cannot be one of the wallets swept: its key is on this site.'));
    }
    if ($sweep['enabled'] && ($sweep['to'] === '' || !$keys)) {
      $form_state->setErrorByName('sweep][enabled', $this->t('Sweeping needs a cold wallet and at least one key.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $facilitator = $form_state->getValue('facilitator');
    $sweep = $form_state->getValue('sweep');
    $this->config('x402_pay.settings')
      ->set('facilitator', [
        'auth' => $facilitator['auth'],
        'api_key_id' => $facilitator['auth'] === 'api_key' ? (string) $facilitator['api_key_id'] : '',
        'own_networks' => array_values(array_filter($facilitator['own_networks'])),
        'own_key_id' => (string) $facilitator['own_key_id'],
        'remote' => array_map(
          fn($site) => ['url' => trim((string) $site['url']), 'key_id' => (string) $site['key_id']],
          array_filter($facilitator['remote'], fn($site) => trim((string) $site['url']) !== ''),
        ),
      ])
      ->set('sweep', [
        'enabled' => (bool) $sweep['enabled'],
        'to' => $sweep['to'] === '' ? '' : Signer::checksum(trim($sweep['to'])),
        'keys' => array_values(array_filter($sweep['keys'])),
        'min' => trim((string) $sweep['min']),
        'interval' => (int) $sweep['interval'],
      ])
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Every key, by label, with its address when it is an EVM private key.
   */
  private function keyOptions(): array {
    $options = [];
    foreach ($this->keys->getKeys() as $id => $key) {
      try {
        $options[$id] = $key->label() . ' — ' . (new Signer(trim((string) $key->getKeyValue())))->address();
      }
      catch (\InvalidArgumentException) {
        // Not a wallet key (an API key, a password): not sweepable.
      }
    }
    return $options;
  }

  /**
   * A key's value, or NULL.
   */
  private function key(?string $id): ?string {
    return $id ? $this->keys->getKey($id)?->getKeyValue() : NULL;
  }

}
