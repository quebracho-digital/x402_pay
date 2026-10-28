<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Plugin\views\field;

use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Quebracho\X402\Networks;

/**
 * A logged amount in its token (atomic units by the row's network).
 */
#[ViewsField('x402_amount')]
class X402Amount extends FieldPluginBase {

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
    $atomic = (string) $this->getValue($values);
    $decimals = Networks::all()[$this->getValue($values, 'network')]['decimals'] ?? 6;
    $padded = str_pad(ltrim($atomic, '0'), $decimals + 1, '0', STR_PAD_LEFT);
    $fraction = rtrim(substr($padded, -$decimals), '0');
    return substr($padded, 0, -$decimals) . ($fraction === '' ? '' : ".$fraction");
  }

}
