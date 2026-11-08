<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Quebracho\X402\ListSync;
use Quebracho\X402\OfacSdn;

/**
 * OFAC's SDN list, kept fresh by the site.
 *
 * The quebracho/x402 library screens every payer against the SDN list that
 * ships with the release, which is as old as the release. Keeping a list
 * fresh is the site's job: cron asks OFAC once a day and writes the newest
 * copy to a file, and every sale reads the newer of that file and the shipped
 * list.
 * The file lives in the private files directory when the site has one, in
 * the public one otherwise (OFAC publishes the list, so nothing in it is a
 * secret).
 *
 * A download that fails changes nothing: the file or the shipped list stays
 * in use, the failure is logged, and cron asks again within the hour.
 */
final class Ofac {

  /**
   * Seconds between downloads that worked.
   */
  public const INTERVAL = 86400;

  /**
   * Seconds before a failed download is tried again.
   */
  public const RETRY = 3600;

  private const STATE = 'x402_pay.ofac_next_sync';

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly ListSync $sync,
    private readonly Settings $settings,
    private readonly string $sitePath,
  ) {}

  /**
   * The file cron writes.
   */
  public function snapshot(): string {
    $private = $this->settings->get('file_private_path');
    $base = $private ?: (defined('DRUPAL_ROOT') ? DRUPAL_ROOT . '/' : '') . $this->sitePath . '/files';
    return rtrim((string) $base, '/') . '/x402_pay/ofac-sdn.json';
  }

  /**
   * The list a sale checks: the newer of this site's file and the shipped one.
   */
  public function list(): OfacSdn {
    return new OfacSdn($this->snapshot());
  }

  /**
   * Downloads the list when it is time. Never throws: cron goes on.
   */
  public function refresh(): void {
    $now = $this->time->getRequestTime();
    if ($now < (int) $this->state->get(self::STATE, 0)) {
      return;
    }
    $file = $this->snapshot();
    try {
      if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0750, TRUE) && !is_dir(dirname($file))) {
        throw new \RuntimeException('cannot create ' . dirname($file));
      }
      $result = $this->sync->sync($file);
    }
    catch (\Throwable $e) {
      $this->state->set(self::STATE, $now + self::RETRY);
      $this->logger->warning('OFAC list not updated, the one in use stays: @error', ['@error' => $e->getMessage()]);
      return;
    }
    $this->state->set(self::STATE, $now + self::INTERVAL);
    $this->logger->info('OFAC list: @count addresses, published @date@kept.', [
      '@count' => $result->count,
      '@date' => $result->updated,
      '@kept' => $result->replaced ? '' : ' (the file already held a younger list)',
    ]);
  }

}
