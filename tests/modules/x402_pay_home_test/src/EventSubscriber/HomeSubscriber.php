<?php

declare(strict_types=1);

namespace Drupal\x402_pay_home_test\EventSubscriber;

use Drupal\x402_pay\Event\AgentHomeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds two sections to the home page for agents, lighter weights first.
 */
final class HomeSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [AgentHomeEvent::class => 'onHome'];
  }

  /**
   * Adds how to book a room (after the page) and one section before all.
   */
  public function onHome(AgentHomeEvent $event): void {
    $event->addSection("## Book a room\n\nAsk /mcp for availability (langcode: " . $event->langcode . ').', 5);
    $event->addSection('## Before everything', -5);
  }

}
