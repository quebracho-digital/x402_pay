<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Drush\Commands;

use Drupal\x402_pay\Buyer;
use Quebracho\X402\Networks;
use Quebracho\X402\Signer;
use Drupal\x402_pay\SolanaKey;
use Drupal\x402_pay\Sweeper;
use GuzzleHttp\ClientInterface;
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
    #[Autowire(service: 'http_client')]
    private readonly ClientInterface $httpClient,
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

  /**
   * Buys an x402 call: the buyer's key from BUYER_PRIVATE_KEY.
   *
   * An EVM key (0x and 64 hex) pays on EVM, a Solana key (base58 or a JSON
   * array of bytes) on Solana.
   */
  #[CLI\Command(name: 'x402:buy')]
  #[CLI\Argument(name: 'url', description: 'The paid resource.')]
  #[CLI\Option(name: 'input', description: 'A JSON body: the call becomes a POST (a service).')]
  #[CLI\Option(name: 'max', description: 'The most it may pay, in USDC.')]
  #[CLI\Usage(name: 'BUYER_PRIVATE_KEY=0x… drush x402:buy https://example.com/x402/node/2/full --max=0.05', description: 'Pays a page, if it costs at most 5 cents.')]
  public function buy(string $url, array $options = ['input' => NULL, 'max' => '0.05']): void {
    $key = getenv('BUYER_PRIVATE_KEY') ?: throw new \RuntimeException('Set BUYER_PRIVATE_KEY.');
    $input = $options['input'] === NULL ? NULL : (json_decode($options['input'], TRUE) ?? throw new \InvalidArgumentException('--input is not JSON.'));
    $max = (int) round((float) $options['max'] * 1e6);
    $wallet = str_starts_with(trim($key), '0x') ? new Signer(trim($key)) : new SolanaKey($key);
    $buyer = new Buyer($this->httpClient, $wallet);
    $paid = $buyer->pay($url, $input, function (array $req) use ($max) {
      $this->io()->writeln(sprintf('402 → %s atomic units to %s on %s', $req['amount'], $req['payTo'], $req['network']));
      if ((int) $req['amount'] > $max) {
        throw new \RuntimeException(sprintf('Over the maximum: %s > %s atomic units. Not paid.', $req['amount'], $max));
      }
    });
    $this->io()->writeln(sprintf('buyer %s → HTTP %d %s', $buyer->address(), $paid['status'], $paid['contentType']));
    $this->io()->writeln($paid['tx'] ? 'tx ' . (Networks::all()[$paid['network']]['explorer'] ?? '') . $paid['tx'] : 'not charged');
    $this->io()->writeln("---\n" . $paid['body']);
  }

}
