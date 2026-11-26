<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\x402_pay\PathProcessor\AgentHomePathProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The language a home path asks for, and no other path.
 */
#[Group('x402_pay')]
final class AgentHomePathProcessorTest extends UnitTestCase {

  /**
   * English, Spanish and Portuguese, as language.negotiation keeps them.
   */
  private const PREFIXES = ['en' => '', 'es' => 'es', 'pt-br' => 'pt'];

  /**
   * Each path an agent may ask for, and what it asks for.
   */
  public static function providerLangcode(): array {
    return [
      'the site, without a prefix' => ['/index.md', ''],
      'Spanish, by its prefix' => ['/es.md', 'es'],
      'Portuguese, by the prefix of its langcode' => ['/pt.md', 'pt-br'],
      'a dot is no language' => ['/.md', NULL],
      'English has no prefix' => ['/en.md', NULL],
      'a language this site does not have' => ['/xx.md', NULL],
      'not Markdown' => ['/es', NULL],
      'a page in a language' => ['/es/x.md', NULL],
      'the index of a language' => ['/es/index.md', NULL],
      'not a path' => ['/index.md/x', NULL],
      'not one leading slash' => ['//es.md', NULL],
    ];
  }

  /**
   * The langcode of a home path: '' for /index.md, NULL if not one.
   */
  #[DataProvider('providerLangcode')]
  public function testLangcode(string $path, ?string $expected): void {
    $this->assertSame($expected, AgentHomePathProcessor::langcode($path, self::PREFIXES));
  }

  /**
   * Without the language module there are no prefixes: only /index.md.
   */
  public function testNoLanguageModule(): void {
    $this->assertSame('', AgentHomePathProcessor::langcode('/index.md', []));
    $this->assertNull(AgentHomePathProcessor::langcode('/es.md', []));
  }

}
