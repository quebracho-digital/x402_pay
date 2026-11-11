<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The stop switch was used: payments were paused, or resumed.
 *
 * React to it: tell whoever is on call (mail, chat, ECA), open an incident,
 * put a notice on the site. Dispatched after both switches were tried and
 * the log line was written, whether or not each could be set or cleared.
 */
final class StopEvent extends Event {

  /**
   * What happened, and how the switches stand now.
   *
   * @param bool $pausing
   *   TRUE for a pause, FALSE for a resume: what was asked.
   * @param bool $engaged
   *   Whether payments are paused now. After a pause that could set neither
   *   switch it is FALSE; after a resume that could not clear one, TRUE.
   * @param array{library: bool, module: bool} $layers
   *   Each switch as it stands now.
   * @param array{library: ?string, module: ?string} $failed
   *   Per switch, why it could not be set or cleared, or NULL.
   * @param string $why
   *   The reason given for a pause; empty for a resume.
   * @param int $uid
   *   The user who did it; 0 from Drush or cron.
   */
  public function __construct(
    public readonly bool $pausing,
    public readonly bool $engaged,
    public readonly array $layers,
    public readonly array $failed,
    public readonly string $why,
    public readonly int $uid,
  ) {}

}
