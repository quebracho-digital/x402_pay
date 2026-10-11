<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\x402_pay\Networks;

/**
 * The price agents pay, e.g. "USD 0.01 · Arc testnet (USDC)".
 */
#[FieldFormatter(
  id: 'x402_price_default',
  label: new TranslatableMarkup('Price for agents'),
  field_types: ['x402_price'],
)]
class X402PriceFormatter extends FormatterBase {

  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    foreach ($items as $delta => $item) {
      if ($item->isPayable()) {
        $network = Networks::ALL[$this->getFieldSetting('network')]['label'] ?? $this->getFieldSetting('network');
        $elements[$delta] = ['#plain_text' => "USD {$item->price()} · $network"];
      }
    }
    return $elements;
  }

}
