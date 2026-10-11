<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A checkbox to charge for this entity, and its own price when allowed.
 */
#[FieldWidget(
  id: 'x402_price_default',
  label: new TranslatableMarkup('Charge over x402'),
  field_types: ['x402_price'],
)]
class X402PriceWidget extends WidgetBase {

  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $element += ['#type' => 'fieldset'];
    $element['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Charge AI agents for this (USD @price by default)', ['@price' => $this->getFieldSetting('amount')]),
      // New entities are charged: the field exists to sell them.
      '#default_value' => $items[$delta]->enabled ?? TRUE,
    ];
    if ($this->getFieldSetting('allow_override')) {
      $element['amount'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Own price in USD'),
        '#description' => $this->t('Empty: the default price.'),
        '#default_value' => $items[$delta]->amount,
        '#size' => 12,
      ];
    }
    return $element;
  }

}
