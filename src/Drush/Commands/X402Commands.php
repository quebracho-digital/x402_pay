<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Drush\Commands;

use Drupal\x402_pay\Sweeper;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for x402 Pay.
 */
final class X402Commands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'x402_pay.sweeper')]
    private readonly Sweeper $sweeper,
  ) {
    parent::__construct();
  }

  /**
   * Moves what the seller wallets hold to the cold wallet, now.
   */
  #[CLI\Command(name: 'x402:sweep')]
  #[CLI\Option(name: 'dry-run', description: 'Only say what would move.')]
  #[CLI\Usage(name: 'drush x402:sweep --dry-run', description: 'Balances and what each sweep would move.')]
  public function sweep(array $options = ['dry-run' => FALSE]): void {
    $rows = $this->sweeper->sweep(!$options['dry-run']);
    foreach ($rows as $row) {
      $this->io()->writeln(json_encode($row, JSON_UNESCAPED_SLASHES));
    }
    if (!$rows) {
      $this->logger()->success('Every seller wallet is empty.');
    }
  }

}
