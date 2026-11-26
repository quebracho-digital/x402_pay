<?php

declare(strict_types=1);

namespace Drupal\x402_pay\PathProcessor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The home page for agents: /index.md, and /es.md in each language.
 *
 * Not /.md: web servers refuse paths starting with a dot (Drupal's
 * .htaccess and its nginx recipes), so it would never reach Drupal.
 *
 * A language's prefix plus ".md" is not a prefix to core ("es.md" is not
 * "es"), nor a page to Markdownify, which has no front page: both pass it.
 * The controller reads the language from the path the agent sent, since
 * routing caches this result per path.
 */
final class AgentHomePathProcessor implements InboundPathProcessorInterface {

  /**
   * The route's path.
   */
  public const PATH = '/x402/agent-home';

  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request): string {
    return self::langcode($path, $this->prefixes()) !== NULL ? self::PATH : $path;
  }

  /**
   * The language a home path asks for: '' for /index.md, NULL if not one.
   *
   * @param string $path
   *   A path, after the language prefix was looked for.
   * @param array<string, string> $prefixes
   *   URL prefixes by langcode, as language.negotiation keeps them.
   *
   * @return string|null
   *   The langcode, '' for /index.md, or NULL.
   */
  public static function langcode(string $path, array $prefixes): ?string {
    if ($path === '/index.md') {
      return '';
    }
    if (!str_ends_with($path, '.md') || substr_count($path, '/') !== 1) {
      return NULL;
    }
    $langcode = array_search(substr($path, 1, -3), $prefixes, TRUE);
    return is_string($langcode) && $prefixes[$langcode] !== '' ? $langcode : NULL;
  }

  /**
   * The site's URL prefixes by langcode; none without the language module.
   *
   * @return array<string, string>
   *   URL prefixes by langcode.
   */
  public function prefixes(): array {
    return (array) ($this->configFactory->get('language.negotiation')->get('url.prefixes') ?? []);
  }

}
