<?php

namespace Drupal\mlp_members\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects anonymous users from member-only pages to the login page.
 */
class MemberAccessSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

  /**
   * Redirects anonymous users away from protected paths.
   */
  public function onRequest(RequestEvent $event): void {
    if (!\Drupal::currentUser()->isAnonymous()) {
      return;
    }

    $path = $event->getRequest()->getPathInfo();

    $protected = ['/member', '/announcements', '/events', '/history'];
    foreach ($protected as $prefix) {
      if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
        $destination = urlencode($path);
        $event->setResponse(new RedirectResponse('/user/login?destination=' . $destination));
        return;
      }
    }
  }

}
