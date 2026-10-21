<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Plugin\views\field;

use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\x402_pay\Networks;

/**
 * A transaction hash, shortened, linked to its network's explorer.
 */
#[ViewsField('x402_transaction')]
class X402Transaction extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $this->ensureMyTable();
    $this->addAdditionalFields(['network']);
    parent::query();
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $tx = (string) $this->getValue($values);
    if ($tx === '') {
      return '';
    }
    $explorer = Networks::ALL[$this->getValue($values, 'network')]['explorer'] ?? NULL;
    $short = substr($tx, 0, 10) . '…';
    if (!$explorer) {
      return ['#plain_text' => $short];
    }
    return [
      '#type' => 'link',
      '#title' => $short,
      '#url' => Url::fromUri($explorer . $tx),
      '#attributes' => ['title' => $tx],
    ];
  }

}
