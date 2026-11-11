<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\x402_pay\Event\StopEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Quebracho\X402\Stop as LibraryStop;

/**
 * The stop switch: pauses every x402 payment of the site, by hand.
 *
 * Two switches, either of which pauses, so that one failing does not leave
 * the site selling:
 *
 * - the library's (Quebracho\X402\Stop), a flag file. The sale middleware and
 *   the paying client look at it themselves, again just before they settle or
 *   sign. It needs no database and no Drupal: `touch` on the file is enough;
 * - the module's, a value in Drupal's state. Every web head that shares the
 *   database sees it, where a file on one host would stop that host only, and
 *   it works when the files directory cannot be written to.
 *
 * Pausing sets the library's first, then the module's; resuming clears both.
 * Each reports on its own, so one that could not be set or cleared (a file
 * that belongs to another system user, say) is said and not hidden by the
 * other. Every pause and resume is a line in the x402_pay log channel (who,
 * why, what failed) and a StopEvent for whoever must be told.
 *
 * While paused: a paid request is answered 503 payments_paused before the
 * facilitator is asked or anything is claimed, and `drush x402:buy` and the
 * deprecated Buyer sign nothing. Not paused: free responses, the sweep to the
 * cold wallet and the reconciliation of the log, which move nothing a buyer
 * pays. It cannot recall a payment already signed or a settlement already
 * sent.
 */
class Stop {

  /**
   * The reason a paused sale answers with, as the library's.
   */
  public const REASON = LibraryStop::REASON;

  private const STATE = 'x402_pay.stopped';

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly Settings $settings,
    private readonly string $sitePath,
    private readonly ?AccountInterface $currentUser = NULL,
    private readonly ?EventDispatcherInterface $eventDispatcher = NULL,
  ) {}

  /**
   * The library's flag file.
   *
   * $settings['x402_pay_stop_file'] in settings.php, an absolute path; else
   * under the private files directory when the site has one, the public one
   * otherwise (a relative one is taken from Drupal's root). The file holds a
   * date and nothing else (the reason stays in the database), so nothing is
   * lost if the web server can serve it.
   */
  public function file(): string {
    $set = $this->settings->get('x402_pay_stop_file');
    if (is_string($set) && $set !== '') {
      return $set;
    }
    $base = (string) ($this->settings->get('file_private_path') ?: $this->sitePath . '/files');
    // Drupal reads a relative files path from its root; the library takes an
    // absolute one only, so that the web and Drush look at the same place.
    if (!str_starts_with($base, '/') && defined('DRUPAL_ROOT')) {
      $base = DRUPAL_ROOT . '/' . $base;
    }
    return rtrim($base, '/') . '/x402_pay/stop/paused';
  }

  /**
   * The library's switch, for its middleware and its paying clients.
   */
  public function library(): LibraryStop {
    return new LibraryStop($this->file());
  }

  /**
   * Whether payments are paused: by either switch.
   *
   * Never throws. A switch that cannot be read counts as engaged: unsure, the
   * site does not sell.
   */
  public function engaged(): bool {
    return in_array(TRUE, $this->layers(), TRUE);
  }

  /**
   * Each switch on its own.
   *
   * @return array{library: bool, module: bool}
   *   TRUE where it is engaged, or cannot be read.
   */
  public function layers(): array {
    try {
      $library = $this->library()->engaged();
    }
    catch (\Throwable) {
      $library = TRUE;
    }
    try {
      $module = $this->state->get(self::STATE) !== NULL;
    }
    catch (\Throwable) {
      $module = TRUE;
    }
    return ['library' => $library, 'module' => $module];
  }

  /**
   * Pauses payments: the library's switch first, then the module's.
   *
   * Both are tried whatever the first one does.
   *
   * @param string $why
   *   For whoever reads the status; never sent to buyers.
   *
   * @return array{library: ?string, module: ?string}
   *   Per switch, why it could not be set, or NULL when it is.
   */
  public function engage(string $why = ''): array {
    $failed = ['library' => NULL, 'module' => NULL];
    try {
      $this->library()->engage();
    }
    catch (\Throwable $e) {
      $failed['library'] = $e->getMessage();
    }
    try {
      $this->state->set(self::STATE, [
        'time' => $this->time->getCurrentTime(),
        'why' => $why,
        'uid' => (int) ($this->currentUser?->id() ?? 0),
      ]);
    }
    catch (\Throwable $e) {
      $failed['module'] = $e->getMessage();
    }
    $this->logger->warning('x402 payments paused by user @uid: @why@failed', [
      '@uid' => (int) ($this->currentUser?->id() ?? 0),
      '@why' => $why === '' ? '(no reason given)' : $why,
      '@failed' => $this->failures($failed),
    ]);
    $this->tell(TRUE, $failed, $why);
    return $failed;
  }

  /**
   * Resumes payments: clears both switches.
   *
   * @return array{library: ?string, module: ?string}
   *   Per switch, why it is still engaged, or NULL when it is clear.
   */
  public function release(): array {
    $failed = ['library' => NULL, 'module' => NULL];
    try {
      $library = $this->library();
      $library->release();
      // The directory goes with the flag: left behind, another system user
      // (cron's, PHP-FPM's) could not look into it, and the library reads a
      // directory it cannot search as engaged.
      @rmdir(dirname($this->file()));
      if ($library->engaged()) {
        $failed['library'] = 'the flag ' . $this->file() . ' is still there, or its directory cannot be read: clear it as the system user that set it';
      }
    }
    catch (\Throwable $e) {
      $failed['library'] = $e->getMessage();
    }
    try {
      $this->state->delete(self::STATE);
    }
    catch (\Throwable $e) {
      $failed['module'] = $e->getMessage();
    }
    $this->logger->notice('x402 payments resumed by user @uid@failed', [
      '@uid' => (int) ($this->currentUser?->id() ?? 0),
      '@failed' => $this->failures($failed),
    ]);
    $this->tell(FALSE, $failed, '');
    return $failed;
  }

  /**
   * When the module's switch was set, why, and by which user.
   *
   * @return array{time: int, why: string, uid: int}|null
   *   NULL when it is not set, or was set by something else than engage().
   */
  public function note(): ?array {
    try {
      $note = $this->state->get(self::STATE);
    }
    catch (\Throwable) {
      return NULL;
    }
    return is_array($note) ? [
      'time' => (int) ($note['time'] ?? 0),
      'why' => (string) ($note['why'] ?? ''),
      'uid' => (int) ($note['uid'] ?? 0),
    ] : NULL;
  }

  /**
   * Dispatches the StopEvent. A listener that throws changes nothing.
   *
   * The switches are already set or cleared: a notice that cannot be sent
   * must not turn a pause into an error.
   */
  private function tell(bool $pausing, array $failed, string $why): void {
    try {
      $layers = $this->layers();
      $this->eventDispatcher?->dispatch(new StopEvent($pausing, in_array(TRUE, $layers, TRUE), $layers, $failed, $why, (int) ($this->currentUser?->id() ?? 0)));
    }
    catch (\Throwable $e) {
      $this->logger->error('A listener of the x402 stop switch failed: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * The switches that failed, for the log line.
   */
  private function failures(array $failed): string {
    $lines = [];
    foreach (array_filter($failed) as $layer => $error) {
      $lines[] = "$layer: $error";
    }
    return $lines ? ' (' . implode('; ', $lines) . ')' : '';
  }

}
