<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Drush\Commands;

use Drupal\x402_pay\Sweeper;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Quebracho\X402\Budget;
use Quebracho\X402\EvmExact;
use Quebracho\X402\Networks;
use Quebracho\X402\PayingHttpClient;
use Quebracho\X402\Signer;
use Quebracho\X402\SolanaExact;
use Quebracho\X402\SolanaKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for x402 Pay.
 */
final class X402Commands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'x402_pay.sweeper')]
    private readonly Sweeper $sweeper,
    #[Autowire(service: 'x402_pay.psr17')]
    private readonly HttpFactory $psr17,
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
    $key = trim($key);
    // One wallet, one family: an EVM key pays on EVM, a Solana key on Solana.
    $evm = str_starts_with($key, '0x');
    $wallet = $evm ? new Signer($key) : new SolanaKey($key);
    $schemes = [$evm ? new EvmExact($wallet) : new SolanaExact($wallet)];
    // The same maximum, in dollars, on every network the library knows.
    $budget = new Budget(array_map(fn(array $n) => Networks::atomic((string) $options['max'], $n['decimals']), Networks::all()));
    $paid = NULL;
    // The paid retry waits as long as the payment can settle: a buyer that
    // hangs up first may be charged for an answer it never reads.
    // LIBRARY-FEEDBACK G9: PayingHttpClient has no timeout of its own.
    $buyer = new PayingHttpClient(new Client(['timeout' => 120]), $schemes, $budget, function (array $req, array $receipt) use (&$paid) {
      $paid = [$req, $receipt];
      $this->io()->writeln(sprintf('402 → %s atomic units to %s on %s', $req['amount'], $req['payTo'], $req['network']));
    });
    $request = $this->psr17->createRequest($input === NULL ? 'GET' : 'POST', $url)->withHeader('Accept', '*/*');
    if ($input !== NULL) {
      $request = $request->withHeader('Content-Type', 'application/json')
        ->withBody($this->psr17->createStream(json_encode($input ?: new \stdClass(), JSON_UNESCAPED_SLASHES)));
    }
    $response = $buyer->sendRequest($request);
    $contentType = $response->getHeaderLine('Content-Type');
    $this->io()->writeln(sprintf('buyer %s → HTTP %d %s', $wallet->address(), $response->getStatusCode(), $contentType));
    $this->io()->writeln($paid ? 'tx ' . (Networks::get($paid[0]['network'])['explorer'] ?? '') . $paid[1]['transaction'] : 'not charged (nothing to pay, or over the maximum)');
    $body = (string) $response->getBody();
    $this->io()->writeln("---\n" . $body);
  }

}
