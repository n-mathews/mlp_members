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
    $board_email   = \Drupal::config('meadow_lane.settings')->get('board_email') ?? 'board@meadowlanepark.com';
    $board_phone   = \Drupal::config('meadow_lane.settings')->get('board_phone') ?? '';

    return [
      '#theme'         => 'mlp_member_portal',
      '#featured'      => $featured,
      '#announcements' => $announcements,
      '#events'        => $events,
      '#board_email'   => $board_email,
      '#board_phone'   => $board_phone,
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
        // Try to get file metadata first — if it's a single file, wrap it directly.
        $file_meta = $this->driveService->getFileMetadata($folder['id']);
        if ($file_meta && $file_meta['mimeType'] !== 'application/vnd.google-apps.folder') {
          // It's a single file — display it directly.
          $sections[] = [
            'label'      => $folder['label'] ?? '',
            'subfolders' => ['files' => [$file_meta], 'children' => []],
            'is_file'    => TRUE,
          ];
        }
        else {
          // It's a folder — recurse into it.
          $tree = $this->driveService->getFolderWithSubfolders($folder['id']);
          $sections[] = [
            'label'      => $folder['label'] ?? '',
            'subfolders' => $tree,
            'is_file'    => FALSE,
          ];
        }
      }
    }

    // Log for debugging.
    \Drupal::logger('mlp_members')->notice(
      'Documents page: drive_enabled=@enabled, folders=@count, sections=@sections',
      [
        '@enabled'  => var_export($drive_enabled, TRUE),
        '@count'    => count($folders),
        '@sections' => count($sections),
      ]
    );

    return [
      '#theme'         => 'mlp_member_documents',
      '#sections'      => $sections,
      '#drive_enabled' => $drive_enabled,
      '#cache'         => ['max-age' => 0],
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
    $request        = \Drupal::request();
    $page           = (int) $request->query->get('page', 0);
    $active_tid     = (int) $request->query->get('category', 0);
    $limit          = 10;
    $account        = \Drupal::currentUser();
    $can_add        = $account->hasPermission('create announcement content');
    $can_edit_any   = $account->hasPermission('edit any announcement content');
    $can_delete_any = $account->hasPermission('delete any announcement content');
    $can_edit_own   = $account->hasPermission('edit own announcement content');
    $can_delete_own = $account->hasPermission('delete own announcement content');
    $uid            = $account->id();

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
        $is_own = $node->getOwnerId() == $uid;
        $items[] = [
          'id'         => $node->id(),
          'title'      => $node->getTitle(),
          'body'       => $this->fieldValue($node, 'field_announcement_body', ''),
          'created'    => $node->getCreatedTime(),
          'sticky'     => $node->isSticky(),
          'featured'   => (bool) $this->fieldValue($node, 'field_featured', FALSE),
          'categories' => $node_cats,
          'can_edit'   => $can_edit_any   || ($can_edit_own   && $is_own),
          'can_delete' => $can_delete_any || ($can_delete_own && $is_own),
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
        '#can_add'     => $can_add,
        '#cache'       => [
          'max-age'  => 300,
          'tags'     => ['node_list:announcement', 'taxonomy_term_list:announcement_category'],
          'contexts' => ['url.query_args:page', 'url.query_args:category', 'user.permissions', 'user'],
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

      $attachments = [];
      if ($entity->hasField('field_announcement_attachments') && !$entity->get('field_announcement_attachments')->isEmpty()) {
        $file_storage = $this->entityTypeManager()->getStorage('file');
        foreach ($entity->get('field_announcement_attachments') as $item) {
          $fid  = $item->target_id;
          $file = $fid ? $file_storage->load($fid) : NULL;
          if ($file) {
            $attachments[] = [
              'name'        => $file->getFilename(),
              'url'         => \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri()),
              'fid'         => $file->id(),
              'description' => $item->description ?? '',
              'mime'        => $file->getMimeType(),
              'size'        => \Drupal\Core\StringTranslation\ByteSizeMarkup::create($file->getSize()),
            ];
          }
        }
      }

      $account    = \Drupal::currentUser();
      $is_own     = $entity->getOwnerId() == $account->id();
      $can_edit   = $account->hasPermission('edit any announcement content')
                 || ($account->hasPermission('edit own announcement content') && $is_own);
      $can_delete = $account->hasPermission('delete any announcement content')
                 || ($account->hasPermission('delete own announcement content') && $is_own);

      return [
        '#theme'      => 'mlp_announcement_detail',
        '#title'      => $entity->getTitle(),
        '#body'       => $this->fieldValue($entity, 'field_announcement_body', ''),
        '#created'    => $entity->getCreatedTime(),
        '#sticky'     => $entity->isSticky(),
        '#featured'   => (bool) $this->fieldValue($entity, 'field_featured', FALSE),
        '#categories' => $cats,
        '#can_edit'    => $can_edit,
        '#can_delete'  => $can_delete,
        '#node_id'     => $entity->id(),
        '#attachments' => $attachments,
        '#cache'       => [
          'tags'     => ['node:' . $entity->id()],
          'contexts' => ['user.permissions', 'user'],
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
    $account        = \Drupal::currentUser();
    $can_add        = $account->hasPermission('create event content');
    $can_edit_any   = $account->hasPermission('edit any event content');
    $can_delete_any = $account->hasPermission('delete any event content');
    $can_edit_own   = $account->hasPermission('edit own event content');
    $can_delete_own = $account->hasPermission('delete own event content');
    $uid            = $account->id();

    $upcoming = $this->loadUpcomingEvents(20);

    // Annotate each event with edit/delete permission flags.
    foreach ($upcoming as &$event) {
      $is_own = isset($event['uid']) && $event['uid'] == $uid;
      $event['can_edit']   = $can_edit_any   || ($can_edit_own   && $is_own);
      $event['can_delete'] = $can_delete_any || ($can_delete_own && $is_own);
    }
    unset($event);

    return [
      '#theme'   => 'mlp_events',
      '#events'  => $upcoming,
      '#can_add' => $can_add,
      '#cache'   => [
        'max-age'  => 300,
        'tags'     => ['node_list:event'],
        'contexts' => ['user.permissions', 'user'],
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

        // Detect all-day: Date All Day module stores midnight-to-midnight in UTC.
        // e.g. 2026-05-01T04:00:00 to 2026-05-02T03:59:59 for an Eastern all-day event.
        // The reliable signal is that end = start + 86399 seconds (23h 59m 59s).
        $all_day = FALSE;
        if ($raw_start && $raw_end) {
          try {
            $utc = new \DateTimeZone('UTC');
            $dt_start = new \DateTime($raw_start, $utc);
            $dt_end   = new \DateTime($raw_end,   $utc);
            $diff_seconds = $dt_end->getTimestamp() - $dt_start->getTimestamp();
            // 86399 = 23:59:59, allow small variance for 23:59:00 (86340)
            $all_day = ($diff_seconds >= 86340 && $diff_seconds <= 86399);
          } catch (\Exception $e) {
            $all_day = FALSE;
          }
        }

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
          'uid'      => $node->getOwnerId(),
        ];
      }
      return $items;
    }
    catch (\Exception $e) {
      return [];
    }
  }


  /**
   * Community history page.
   */
  public function history(): array {
    $config       = \Drupal::config('system.theme.global');
    $theme_config = \Drupal::config('meadow_lane.settings');
    $photos_url   = \Drupal::service('theme.manager')->getActiveTheme()->getName() === 'meadow_lane'
      ? (\Drupal::config('meadow_lane.settings')->get('google_photos_url') ?? '')
      : '';
    $join_url     = \Drupal::config('meadow_lane.settings')->get('google_photos_join_url') ?? '';

    // Load approved (published) historical documents.
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->condition('type', 'history_document')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->accessCheck(TRUE)
      ->execute();

    $documents = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $file_field = $node->hasField('field_document_file') && !$node->get('field_document_file')->isEmpty()
        ? $node->get('field_document_file')->entity
        : NULL;
      $documents[] = [
        'id'          => $node->id(),
        'title'       => $node->getTitle(),
        'description' => $this->fieldValue($node, 'field_document_description', ''),
        'created'     => $node->getCreatedTime(),
        'file_url'    => $file_field ? \Drupal::service('file_url_generator')->generateAbsoluteString($file_field->getFileUri()) : NULL,
        'file_name'   => $file_field ? $file_field->getFilename() : NULL,
        'file_mime'   => $file_field ? $file_field->getMimeType() : NULL,
      ];
    }

    $account    = \Drupal::currentUser();
    $can_manage = $account->hasPermission('edit any history_document content')
               || $account->hasPermission('delete any history_document content');

    return [
      '#theme'       => 'mlp_history',
      '#photos_url'  => $photos_url,
      '#join_url'    => $join_url,
      '#documents'   => $documents,
      '#can_manage'  => $can_manage,
      '#cache'       => [
        'max-age'  => 300,
        'tags'     => ['node_list:history_document'],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * History document submission page.
   */
  public function historySubmit(): array {
    $form = \Drupal::formBuilder()->getForm('\Drupal\mlp_members\Form\HistoryDocumentForm');
    return [
      '#theme'  => 'mlp_history_submit',
      '#form'   => $form,
      '#cache'  => ['max-age' => 0],
    ];
  }


}
