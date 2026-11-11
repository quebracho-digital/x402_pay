<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Drush\Commands;

use Drupal\x402_pay\Reconciler;
use Drupal\x402_pay\Stop;
use Drupal\x402_pay\Sweeper;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Quebracho\X402\Budget;
use Quebracho\X402\Buyer;
use Quebracho\X402\EvmExact;
use Quebracho\X402\Networks;
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
    #[Autowire(service: 'x402_pay.reconciler')]
    private readonly Reconciler $reconciler,
    #[Autowire(service: 'x402_pay.stop')]
    private readonly Stop $stop,
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
   * Checks the payment log against the chain: pending and settled sales.
   */
  #[CLI\Command(name: 'x402:reconcile')]
  #[CLI\Option(name: 'days', description: 'How far back to check settled sales.')]
  #[CLI\Usage(name: 'drush x402:reconcile --days=8', description: 'Settles pending rows, then checks a week of sales.')]
  public function reconcile(array $options = ['days' => 8]): int {
    $rows = [...$this->reconciler->pending(), ...$this->reconciler->settled((int) $options['days'])];
    foreach ($rows as $row) {
      $this->io()->writeln(json_encode($row, JSON_UNESCAPED_SLASHES));
    }
    $open = ['authorization_used', 'no_answer'];
    $problems = array_filter($rows, fn($row) => !empty($row['problem']) || in_array($row['outcome'] ?? '', $open, TRUE));
    // Rows already waiting for a person (a refund owed, an authorization
    // spent elsewhere) count on every run, not only the one that found them.
    $standing = $this->reconciler->standing((int) $options['days']);
    if ($standing) {
      $this->logger()->error($standing . ' sales wait for a person (a refund owed, or an authorization spent by another transaction).');
    }
    $problems = $problems ?: ($standing ? [TRUE] : []);
    $problems ? $this->logger()->error(count($problems) . ' sales disagree with the chain.') : $this->logger()->success(count($rows) . ' rows checked.');
    // Non-zero when the chain disagrees, so a cron job or CI notices.
    return $problems ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Pauses or resumes every x402 payment of the site, or says where it is.
   *
   * Two switches, either of which pauses: the library's flag file first, then
   * the module's value in the database. Run it as the system user PHP runs
   * as on the web (e.g. sudo -u www-data): a flag file set by another user
   * cannot be cleared by this one, and the command says so.
   */
  #[CLI\Command(name: 'x402:stop')]
  #[CLI\Argument(name: 'action', description: 'on, off or status.')]
  #[CLI\Argument(name: 'why', description: 'Why (kept for whoever reads the status; never sent to buyers).')]
  #[CLI\Usage(name: 'drush x402:stop on "facilitator incident"', description: 'Pauses: nothing is sold or bought.')]
  #[CLI\Usage(name: 'drush x402:stop off', description: 'Resumes.')]
  #[CLI\Usage(name: 'drush x402:stop status', description: 'Exits 1 while paused, 0 while running: for a monitor.')]
  public function stop(string $action = 'status', string $why = ''): int {
    $say = function (array $failed, string $verb): void {
      foreach (array_filter($failed) as $layer => $error) {
        $this->logger()->error("The $layer switch $verb: $error");
      }
    };
    switch ($action) {
      case 'on':
        $say($this->stop->engage($why), 'could not be set');
        if (!$this->stop->engaged()) {
          $this->logger()->error('Payments are NOT paused: neither switch could be set.');
          return self::EXIT_FAILURE;
        }
        $this->logger()->warning('Payments are paused: nothing is sold or bought until "drush x402:stop off".');
        return self::EXIT_SUCCESS;

      case 'off':
        $say($this->stop->release(), 'is still engaged');
        if ($this->stop->engaged()) {
          $this->logger()->error('Payments are still paused.');
          return self::EXIT_FAILURE;
        }
        $this->logger()->success('Payments resumed.');
        return self::EXIT_SUCCESS;

      case 'status':
        $layers = $this->stop->layers();
        $note = $this->stop->note();
        $this->io()->writeln(json_encode($layers + [
          'since' => $note ? date('c', $note['time']) : NULL,
          'why' => $note['why'] ?? NULL,
          'uid' => $note['uid'] ?? NULL,
        ], JSON_UNESCAPED_SLASHES));
        if (!in_array(TRUE, $layers, TRUE)) {
          $this->io()->writeln('Payments are running.');
          return self::EXIT_SUCCESS;
        }
        $this->logger()->warning('Payments are PAUSED.');
        // Not an error of the command: the answer, for a script that asks.
        return self::EXIT_FAILURE;
    }
    $this->logger()->error("Unknown action \"$action\": on, off or status.");
    return self::EXIT_FAILURE;
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
    // Either switch: the library's client below only knows its own.
    if ($this->stop->engaged()) {
      throw new \RuntimeException('Payments are paused (drush x402:stop status): nothing was asked or signed.');
    }
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
    // The library's paying client has no timeout of its own.
    $buyer = Buyer::with($schemes, $budget)
      ->onPaid(function (array $req, array $receipt) use (&$paid) {
        $paid = [$req, $receipt];
        $this->io()->writeln(sprintf('402 → %s atomic units to %s on %s', $req['amount'], $req['payTo'], $req['network']));
      })
      ->onRefused(fn(array $req, string $reason) => $this->io()->writeln("not paid: $reason"))
      // Paused while the seller answered: the library signs nothing.
      ->stop($this->stop->library())
      ->http(new Client(['timeout' => 120]));
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
