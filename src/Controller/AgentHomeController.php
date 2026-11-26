<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Controller;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\x402_pay\AgentHome;
use Drupal\x402_pay\PathProcessor\AgentHomePathProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * The home page for agents at /index.md, and /es.md in each language.
 */
final class AgentHomeController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'x402_pay.agent_home')]
    private readonly AgentHome $home,
    #[Autowire(service: 'x402_pay.agent_home_path_processor')]
    private readonly AgentHomePathProcessor $paths,
  ) {}

  /**
   * The home page, in the language the path names.
   *
   * Read from the path the agent sent: "es.md" is no prefix to core's
   * language negotiation.
   */
  public function __invoke(Request $request): CacheableResponse {
    return $this->home->response($request, AgentHomePathProcessor::langcode($request->getPathInfo(), $this->paths->prefixes()) ?: NULL);
  }

}
