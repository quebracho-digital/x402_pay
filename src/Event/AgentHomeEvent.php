<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Event;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * The home page for agents (/index.md), section by section.
 *
 * Add what an agent should read first: how to book, an MCP endpoint, today's
 * opening hours. Each section is markdown, ordered by weight (the page
 * itself is 0, what is sold 10). What a section depends on goes to
 * $cacheability, or the page keeps a stale copy.
 */
final class AgentHomeEvent extends Event {

  /**
   * Markdown sections, by weight.
   *
   * @var array<int, string[]>
   */
  private array $sections = [];

  public function __construct(
    public readonly Request $request,
    public readonly string $langcode,
    public readonly ?FieldableEntityInterface $entity,
    public readonly CacheableMetadata $cacheability,
  ) {}

  /**
   * Adds a section of markdown; lighter weights go first.
   */
  public function addSection(string $markdown, int $weight = 0): void {
    $this->sections[$weight][] = trim($markdown);
  }

  /**
   * The sections in order.
   *
   * @return string[]
   *   Markdown, lighter weights first.
   */
  public function sections(): array {
    ksort($this->sections);
    return array_merge(...array_values($this->sections ?: [[]]));
  }

}
