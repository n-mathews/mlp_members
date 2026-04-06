<?php

namespace Drupal\mlp_members\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\mlp_members\Service\GoogleDriveService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for the Meadow Lane Park member portal.
 */
class MemberPortalController extends ControllerBase {

  public function __construct(
    protected GoogleDriveService $driveService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('mlp_members.google_drive'),
    );
  }

  /**
   * Main member portal dashboard.
   */
  public function portal(): array {
    if (!\Drupal::currentUser()->isAuthenticated()) {
      return $this->accessDenied();
    }

    // Load featured announcement (if any).
    $featured = $this->loadFeaturedAnnouncement();

    // Load upcoming events (next 5).
    $events = $this->loadUpcomingEvents(8);

    // Load recent announcements (not featured, last 5).
    $announcements = $this->loadRecentAnnouncements(8);

    return [
      '#theme'         => 'mlp_member_portal',
      '#featured'      => $featured,
      '#announcements' => $announcements,
      '#events'        => $events,
      '#cache'         => ['max-age' => 300],
    ];
  }

  /**
   * Documents & Forms page — fetches from Google Drive.
   */
  public function documents(): array {
    if (!\Drupal::currentUser()->isAuthenticated()) {
      return $this->accessDenied();
    }

    $config = $this->config('mlp_members.settings');
    $folders = $config->get('drive_folders') ?? [];
    $drive_enabled = $config->get('drive_enabled') ?? FALSE;

    $sections = [];
    if ($drive_enabled && !empty($folders)) {
      foreach ($folders as $folder) {
        $files = $this->driveService->getFolderContents($folder['id']);
        $sections[] = [
          'label' => $folder['label'],
          'files' => $files,
        ];
      }
    }

    return [
      '#theme'        => 'mlp_member_documents',
      '#sections'     => $sections,
      '#drive_enabled'=> $drive_enabled,
      '#cache'        => ['max-age' => 900],
    ];
  }

  /**
   * Refreshes the Google Drive document cache (admin only).
   */
  public function refreshDocuments(): RedirectResponse {
    $this->driveService->clearCache();
    $this->messenger()->addStatus($this->t('Document cache cleared.'));
    return new RedirectResponse(Url::fromRoute('mlp_members.documents')->toString());
  }

  /**
   * Access denied / not-yet-a-member page (public).
   */
  public function accessDenied(): array {
    return [
      '#theme'  => 'mlp_member_access_denied',
      '#cache'  => ['max-age' => 3600],
    ];
  }

  /**
   * Returns events as JSON for FullCalendar.
   */
  public function eventsFeed(): JsonResponse {
    if (!\Drupal::currentUser()->isAuthenticated()) {
      return new JsonResponse([], 403);
    }
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'event')
      ->condition('status', 1)
      ->sort('field_event_date', 'ASC')
      ->range(0, 200)
      ->accessCheck(TRUE);
    $ids = $query->execute();
    $events = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $date = $node->get('field_event_date')->value;
      $end  = $node->hasField('field_event_end_date') ? $node->get('field_event_end_date')->value : NULL;
      $all_day = $node->hasField('field_all_day') ? (bool) $node->get('field_all_day')->value : FALSE;
      // FullCalendar expects ISO 8601 with timezone
      $event = [
        'id'      => (string) $node->id(),
        'title'   => $node->getTitle(),
        'start'   => $all_day ? substr($date, 0, 10) : str_replace('T', 'T', $date),
        'allDay'  => $all_day,
        'url'     => '',
      ];
      if ($end) {
        $event['end'] = $all_day ? substr($end, 0, 10) : $end;
      }
      // Add Google Meet link as extendedProp
      if ($node->hasField('field_event_link') && !$node->get('field_event_link')->isEmpty()) {
        $event['extendedProps']['meetUrl'] = $node->get('field_event_link')->uri;
      }
      $events[] = $event;
    }
    return new JsonResponse($events);
  }

  // ── Private helpers ──────────────────────────────────────

  private function loadFeaturedAnnouncement(): ?array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'announcement')
      ->condition('status', 1)
      ->condition('field_featured', 1)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(TRUE);
    $ids = $query->execute();
    if (empty($ids)) {
      return NULL;
    }
    $node = $storage->load(reset($ids));
    return [
      'title'   => $node->getTitle(),
      'body'    => $node->hasField('field_announcement_body') ? $node->get('field_announcement_body')->value : '',
      'created' => $node->getCreatedTime(),
      'id'      => $node->id(),
    ];
  }

  private function loadRecentAnnouncements(int $limit): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'announcement')
      ->condition('status', 1)
      ->sort('sticky', 'DESC')
      ->sort('created', 'DESC')
      ->range(0, $limit)
      ->accessCheck(TRUE);
    $ids = $query->execute();
    $items = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $items[] = [
        'title'   => $node->getTitle(),
        'body'    => $node->hasField('field_announcement_body') ? $node->get('field_announcement_body')->value : '',
        'created' => $node->getCreatedTime(),
        'sticky'  => $node->isSticky(),
        'id'      => $node->id(),
      ];
    }
    return $items;
  }

  private function loadUpcomingEvents(int $limit): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $now = (new \DateTime())->format('Y-m-d\TH:i:s');
    $query = $storage->getQuery()
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('field_event_date', $now, '>=')
      ->sort('field_event_date', 'ASC')
      ->range(0, $limit)
      ->accessCheck(TRUE);
    $ids = $query->execute();
    $items = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $link = NULL;
      if ($node->hasField('field_event_link') && !$node->get('field_event_link')->isEmpty()) {
        $link = [
          'url'   => $node->get('field_event_link')->uri,
          'title' => $node->get('field_event_link')->title ?: 'Join meeting',
        ];
      }
      $items[] = [
        'title'    => $node->getTitle(),
        'date'     => $node->get('field_event_date')->value,
        'end_date' => $node->hasField('field_event_end_date') ? $node->get('field_event_end_date')->value : NULL,
        'location' => $node->hasField('field_event_location') ? $node->get('field_event_location')->value : NULL,
        'body'     => $node->hasField('field_event_body') ? $node->get('field_event_body')->value : '',
        'all_day'  => $node->hasField('field_all_day') ? (bool) $node->get('field_all_day')->value : FALSE,
        'link'     => $link,
        'id'       => $node->id(),
      ];
    }
    return $items;
  }

}
