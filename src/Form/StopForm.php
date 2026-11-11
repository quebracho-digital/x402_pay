<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\x402_pay\Stop;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pauses and resumes every x402 payment of the site.
 *
 * Its own permission (pause x402 payments), apart from the settings': whoever
 * is on call can stop the money without being able to change where it goes.
 */
final class StopForm extends FormBase {

  public function __construct(
    protected Stop $stop,
    protected DateFormatterInterface $dates,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('x402_pay.stop'), $container->get('date.formatter'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'x402_pay_stop';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $layers = $this->stop->layers();
    $paused = in_array(TRUE, $layers, TRUE);
    $note = $this->stop->note();
    $on = fn(bool $engaged) => $engaged ? $this->t('engaged') : $this->t('clear');

    $form['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Payments'),
      '#markup' => $paused ? $this->t('<strong>PAUSED.</strong> Nothing is sold or bought.') : $this->t('Running.'),
    ];
    $form['layers'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t("The library's switch (a flag file): @state", ['@state' => $on($layers['library'])]),
        $this->t("The module's switch (the database): @state", ['@state' => $on($layers['module'])]),
      ],
    ];
    if ($note !== NULL) {
      $form['note'] = [
        '#type' => 'item',
        '#title' => $this->t('Paused'),
        '#plain_text' => $this->t('@date, by user @uid: @why', [
          '@date' => $this->dates->format($note['time'], 'short'),
          '@uid' => $note['uid'],
          '@why' => $note['why'] === '' ? $this->t('no reason given') : $note['why'],
        ]),
      ];
    }
    $form['help'] = [
      '#type' => 'item',
      '#markup' => $this->t('While paused, a paid request is answered 503 <code>payments_paused</code> before anything is verified, claimed or charged, and this site buys nothing. Free responses, the sweep and the reconciliation go on. A payment already signed or a settlement already sent cannot be recalled.'),
    ];
    $form['why'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Why'),
      '#description' => $this->t('For whoever reads this page or the log; never sent to buyers.'),
      '#maxlength' => 255,
      '#access' => !$paused,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['pause'] = [
      '#type' => 'submit',
      '#value' => $this->t('Pause payments'),
      '#button_type' => 'danger',
      '#submit' => ['::pause'],
      '#access' => !$paused,
    ];
    $form['actions']['resume'] = [
      '#type' => 'submit',
      '#value' => $this->t('Resume payments'),
      '#button_type' => 'primary',
      '#submit' => ['::resume'],
      '#access' => $paused,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Each button has its own handler.
  }

  /**
   * Pauses: both switches, and says which one could not be set.
   */
  public function pause(array &$form, FormStateInterface $form_state): void {
    $failed = array_filter($this->stop->engage(trim((string) $form_state->getValue('why'))));
    foreach ($failed as $layer => $error) {
      $this->messenger()->addWarning($this->t('The @layer switch could not be set: @error', [
        '@layer' => $layer,
        '@error' => $error,
      ]));
    }
    $this->stop->engaged()
      ? $this->messenger()->addStatus($this->t('Payments are paused.'))
      : $this->messenger()->addError($this->t('Payments are NOT paused: neither switch could be set.'));
  }

  /**
   * Resumes: both switches, and says which one is still engaged.
   */
  public function resume(array &$form, FormStateInterface $form_state): void {
    $failed = array_filter($this->stop->release());
    foreach ($failed as $layer => $error) {
      $this->messenger()->addError($this->t('The @layer switch is still engaged: @error', [
        '@layer' => $layer,
        '@error' => $error,
      ]));
    }
    $this->stop->engaged()
      ? $this->messenger()->addError($this->t('Payments are still paused.'))
      : $this->messenger()->addStatus($this->t('Payments resumed.'));
  }

}
