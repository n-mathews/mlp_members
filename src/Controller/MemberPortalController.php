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
    $featured      = $this->loadFeaturedAnnouncement();
    $events        = $this->loadUpcomingEvents(8);
    $announcements = $this->loadRecentAnnouncements(8);

    return [
      '#theme'         => 'mlp_member_portal',
      '#featured'      => $featured,
      '#announcements' => $announcements,
      '#events'        => $events,
      '#cache'         => [
        'max-age' => 300,
        'tags'    => ['node_list:announcement', 'node_list:event'],
      ],
    ];
  }

  /**
   * Documents & Forms page.
   */
  public function documents(): array {
    $config       = $this->config('mlp_members.settings');
    $folders      = $config->get('drive_folders') ?? [];
    $drive_enabled = $config->get('drive_enabled') ?? FALSE;

    $sections = [];
    if ($drive_enabled && !empty($folders)) {
      foreach ($folders as $folder) {
        if (empty($folder['id'])) {
          continue;
        }
        $files = $this->driveService->getFolderContents($folder['id']);
        $sections[] = [
          'label' => $folder['label'] ?? '',
          'files' => $files,
        ];
      }
    }

    return [
      '#theme'         => 'mlp_member_documents',
      '#sections'      => $sections,
      '#drive_enabled' => $drive_enabled,
      '#cache'         => ['max-age' => 900],
    ];
  }

  /**
   * Clears the Google Drive document cache (admin only).
   */
  public function refreshDocuments(): RedirectResponse {
    $this->driveService->clearCache();
    $this->messenger()->addStatus($this->t('Document cache cleared.'));
    return new RedirectResponse(Url::fromRoute('mlp_members.documents')->toString());
  }

  /**
   * Public access-denied page shown to anonymous visitors.
   */
  public function accessDenied(): array {
    return [
      '#theme' => 'mlp_member_access_denied',
      '#cache' => ['max-age' => 3600],
    ];
  }

  /**
   * Full announcements listing page — paginated, filterable by category.
   */
  public function announcements(): array {
    $request    = \Drupal::request();
    $page       = (int) $request->query->get('page', 0);
    $active_tid = (int) $request->query->get('category', 0);
    $limit      = 10;

    try {
      $node_storage = $this->entityTypeManager()->getStorage('node');
      $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');

      // Load all categories for the filter bar.
      $categories = [];
      $terms = $term_storage->loadByProperties(['vid' => 'announcement_category']);
      foreach ($terms as $term) {
        $categories[] = ['id' => $term->id(), 'name' => $term->getName()];
      }
      usort($categories, fn($a, $b) => strcmp($a['name'], $b['name']));

      // Build base query.
      $base = $node_storage->getQuery()
        ->condition('type', 'announcement')
        ->condition('status', 1)
        ->accessCheck(TRUE);

      if ($active_tid) {
        $base->condition('field_announcement_category', $active_tid);
      }

      $total = (int) (clone $base)->count()->execute();

      $ids = (clone $base)
        ->sort('sticky', 'DESC')
        ->sort('created', 'DESC')
        ->range($page * $limit, $limit)
        ->execute();

      $items = [];
      foreach ($node_storage->loadMultiple($ids) as $node) {
        // Collect category terms for this node.
        $node_cats = [];
        if ($node->hasField('field_announcement_category') && !$node->get('field_announcement_category')->isEmpty()) {
          foreach ($node->get('field_announcement_category')->referencedEntities() as $term) {
            $node_cats[] = ['id' => $term->id(), 'name' => $term->getName()];
          }
        }
        $items[] = [
          'id'         => $node->id(),
          'title'      => $node->getTitle(),
          'body'       => $this->fieldValue($node, 'field_announcement_body', ''),
          'created'    => $node->getCreatedTime(),
          'sticky'     => $node->isSticky(),
          'featured'   => (bool) $this->fieldValue($node, 'field_featured', FALSE),
          'categories' => $node_cats,
        ];
      }

      return [
        '#theme'       => 'mlp_announcements',
        '#items'       => $items,
        '#categories'  => $categories,
        '#active_tid'  => $active_tid,
        '#page'        => $page,
        '#total_pages' => (int) ceil($total / $limit),
        '#total'       => $total,
        '#cache'       => [
          'max-age'  => 300,
          'tags'     => ['node_list:announcement', 'taxonomy_term_list:announcement_category'],
          'contexts' => ['url.query_args:page', 'url.query_args:category'],
        ],
      ];
    }
    catch (\Exception $e) {
      return ['#markup' => $this->t('Announcements could not be loaded.')];
    }
  }

  /**
   * Single announcement detail page.
   */
  public function announcementDetail($node): array {
    try {
      $storage = $this->entityTypeManager()->getStorage('node');
      $entity  = $storage->load($node);

      if (!$entity || $entity->bundle() !== 'announcement' || !$entity->isPublished()) {
        throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
      }

      $cats = [];
      if ($entity->hasField('field_announcement_category') && !$entity->get('field_announcement_category')->isEmpty()) {
        foreach ($entity->get('field_announcement_category')->referencedEntities() as $term) {
          $cats[] = ['id' => $term->id(), 'name' => $term->getName()];
        }
      }

      return [
        '#theme'      => 'mlp_announcement_detail',
        '#title'      => $entity->getTitle(),
        '#body'       => $this->fieldValue($entity, 'field_announcement_body', ''),
        '#created'    => $entity->getCreatedTime(),
        '#sticky'     => $entity->isSticky(),
        '#featured'   => (bool) $this->fieldValue($entity, 'field_featured', FALSE),
        '#categories' => $cats,
        '#cache'      => [
          'tags' => ['node:' . $entity->id()],
        ],
      ];
    }
    catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      return ['#markup' => $this->t('Announcement not found.')];
    }
  }

  /**
   * Events calendar page — FullCalendar + upcoming list.
   */
  public function events(): array {
    $upcoming = $this->loadUpcomingEvents(20);
    return [
      '#theme'    => 'mlp_events',
      '#events'   => $upcoming,
      '#cache'    => [
        'max-age' => 300,
        'tags'    => ['node_list:event'],
      ],
    ];
  }

  /**
   * JSON feed for FullCalendar at /events/feed.json.
   */
  public function eventsFeed(): JsonResponse {
    try {
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
        $date    = $this->fieldValue($node, 'field_event_date');
        $end     = $this->fieldValue($node, 'field_event_end_date');
        $all_day = (bool) $this->fieldValue($node, 'field_all_day', FALSE);

        $event = [
          'id'     => (string) $node->id(),
          'title'  => $node->getTitle(),
          'start'  => $all_day ? substr($date, 0, 10) : $date,
          'allDay' => $all_day,
        ];

        if ($end) {
          $event['end'] = $all_day ? substr($end, 0, 10) : $end;
        }

        if ($node->hasField('field_event_link') && !$node->get('field_event_link')->isEmpty()) {
          $event['extendedProps']['meetUrl'] = $node->get('field_event_link')->uri;
        }

        $events[] = $event;
      }

      return new JsonResponse($events);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  // ── Private helpers ──────────────────────────────────────

  /**
   * Safely reads a field value, returning $default if the field
   * doesn't exist or is empty.
   */
  private function fieldValue($node, string $field, $default = NULL) {
    if (!$node->hasField($field)) {
      return $default;
    }
    $item = $node->get($field);
    if ($item->isEmpty()) {
      return $default;
    }
    return $item->value ?? $default;
  }

  private function loadFeaturedAnnouncement(): ?array {
    try {
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
        'body'    => $this->fieldValue($node, 'field_announcement_body', ''),
        'created' => $node->getCreatedTime(),
        'id'      => $node->id(),
      ];
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  private function loadRecentAnnouncements(int $limit): array {
    try {
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
          'body'    => $this->fieldValue($node, 'field_announcement_body', ''),
          'created' => $node->getCreatedTime(),
          'sticky'  => $node->isSticky(),
          'id'      => $node->id(),
        ];
      }
      return $items;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  private function loadUpcomingEvents(int $limit): array {
    try {
      $storage = $this->entityTypeManager()->getStorage('node');
      $now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s');
      $query = $storage->getQuery()
        ->condition('type', 'event')
        ->condition('status', 1)
        ->condition('field_event_date.value', $now, '>=')
        ->sort('field_event_date.value', 'ASC')
        ->range(0, $limit)
        ->accessCheck(TRUE);
      $ids = $query->execute();
      $items = [];
      foreach ($storage->loadMultiple($ids) as $node) {
        $link = NULL;
        if ($node->hasField('field_event_link') && !$node->get('field_event_link')->isEmpty()) {
          $link = [
            'url'   => $node->get('field_event_link')->uri,
            'title' => $node->get('field_event_link')->title ?: $this->t('Join meeting'),
          ];
        }
        // daterange stores start in ->value, end in ->end_value (UTC)
        $date_field = $node->hasField('field_event_date') && !$node->get('field_event_date')->isEmpty()
          ? $node->get('field_event_date')->first()
          : NULL;
        $raw_start = $date_field ? $date_field->value : '';
        $raw_end   = $date_field ? $date_field->end_value : NULL;
        $site_tz   = new \DateTimeZone(date_default_timezone_get());

        // Detect all-day: Date All Day module sets time to T00:00:00/T23:59:59
        // in UTC, OR the field_all_day boolean is set. Check both.
        $all_day_field = $node->hasField('field_all_day') && !$node->get('field_all_day')->isEmpty()
          ? (int) $node->get('field_all_day')->value
          : 0;
        $time_suggests_all_day = $raw_start && substr($raw_start, 11) === '00:00:00'
          && $raw_end && (substr($raw_end, 11) === '23:59:59' || substr($raw_end, 11) === '23:59:00');
        $all_day = (bool) $all_day_field || $time_suggests_all_day;

        // Convert UTC to site timezone; all-day events show date only.
        $start_display = '';
        $end_display   = NULL;
        if ($raw_start) {
          $dt = new \DateTime($raw_start, new \DateTimeZone('UTC'));
          $dt->setTimezone($site_tz);
          $start_display = $all_day ? $dt->format('Y-m-d') : $dt->format('Y-m-d H:i:s');
        }
        if ($raw_end && !$all_day) {
          $dt = new \DateTime($raw_end, new \DateTimeZone('UTC'));
          $dt->setTimezone($site_tz);
          $end_display = $dt->format('Y-m-d H:i:s');
        }
        $items[] = [
          'title'    => $node->getTitle(),
          'date'     => $start_display,
          'end_date' => $end_display,
          'location' => $this->fieldValue($node, 'field_event_location'),
          'body'     => $this->fieldValue($node, 'field_event_body', ''),
          'all_day'  => $all_day,
          'link'     => $link,
          'id'       => $node->id(),
        ];
      }
      return $items;
    }
    catch (\Exception $e) {
      return [];
    }
  }

}
