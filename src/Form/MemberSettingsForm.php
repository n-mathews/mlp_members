<?php

namespace Drupal\mlp_members\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin settings form for MLP Members module.
 *
 * Configure at: /admin/config/mlp-members
 */
class MemberSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'mlp_members_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['mlp_members.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('mlp_members.settings');

    $form['drive'] = [
      '#type'        => 'details',
      '#title'       => $this->t('Google Drive integration'),
      '#open'        => TRUE,
      '#description' => $this->t(
        'Connect to Google Drive to display documents in the member portal. ' .
        'Requires a Service Account with read access to each folder. ' .
        '<a href="https://console.cloud.google.com" target="_blank">Set up in Google Cloud Console →</a>'
      ),
    ];

    $form['drive']['drive_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable Google Drive integration'),
      '#default_value' => $config->get('drive_enabled') ?? FALSE,
    ];

    $form['drive']['service_account_key_path'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Service account key file path'),
      '#default_value' => $config->get('service_account_key_path') ?? '',
      '#description'   => $this->t(
        'Absolute server path to the downloaded JSON key file. ' .
        'Store this <strong>outside the webroot</strong> for security, ' .
        'e.g. <code>/etc/mlp/google-service-account.json</code>.'
      ),
      '#placeholder'   => '/etc/mlp/google-service-account.json',
    ];

    // ── Folder table ──────────────────────────────────────
    // Determine how many rows to show. Start from saved config,
    // then add any rows the user has requested via "Add another".
    $saved_folders = $config->get('drive_folders') ?? [];
    $folder_count  = $form_state->get('folder_count');

    if ($folder_count === NULL) {
      // First load — use saved count, minimum 1.
      $folder_count = max(1, count($saved_folders));
      $form_state->set('folder_count', $folder_count);
    }

    $form['drive']['folders_wrapper'] = [
      '#type'       => 'container',
      '#attributes' => ['id' => 'mlp-folders-wrapper'],
      '#tree'       => TRUE,
    ];

    $form['drive']['folders_wrapper']['description'] = [
      '#markup' => '<p>' . $this->t(
        'Each folder appears as a separate section in the Documents page. ' .
        'The folder or file ID is the string after <code>/folders/</code> or <code>/file/d/</code> in the Google Drive URL. Both folders and individual files are supported.'
      ) . '</p>',
    ];

    // Column headers
    $form['drive']['folders_wrapper']['header'] = [
      '#markup' => '<div style="display:grid;grid-template-columns:1fr 2fr auto;gap:.5rem 1rem;font-weight:500;font-size:.8125rem;color:#444;padding-bottom:.25rem;border-bottom:1px solid #ddd;margin-bottom:.5rem;">' .
                   '<span>' . $this->t('Section label') . '</span>' .
                   '<span>' . $this->t('Folder or File ID') . '</span>' .
                   '<span></span>' .
                   '</div>',
    ];

    for ($i = 0; $i < $folder_count; $i++) {
      $saved = $saved_folders[$i] ?? [];

      // Use form_state values if present (after AJAX), else fall back to saved.
      $current = $form_state->getValue(['folders_wrapper', 'folders', $i]) ?? $saved;

      $form['drive']['folders_wrapper']['folders'][$i] = [
        '#type'       => 'container',
        '#attributes' => [
          'style' => 'display:grid;grid-template-columns:1fr 2fr auto;gap:.5rem 1rem;align-items:end;margin-bottom:.5rem;',
        ],
      ];

      $form['drive']['folders_wrapper']['folders'][$i]['label'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Label'),
        '#title_display' => 'invisible',
        '#default_value' => $current['label'] ?? '',
        '#placeholder'   => $this->t('e.g. Board Minutes'),
        '#attributes'    => ['style' => 'width:100%;'],
      ];

      $form['drive']['folders_wrapper']['folders'][$i]['id'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Folder or File ID'),
        '#title_display' => 'invisible',
        '#default_value' => $current['id'] ?? '',
        '#placeholder'   => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs',
        '#attributes'    => ['style' => 'width:100%;font-family:monospace;font-size:.875rem;'],
      ];

      // Remove button (only shown when there's more than one row)
      if ($folder_count > 1) {
        $form['drive']['folders_wrapper']['folders'][$i]['remove'] = [
          '#type'                    => 'submit',
          '#value'                   => $this->t('✕'),
          '#name'                    => 'remove_folder_' . $i,
          '#submit'                  => ['::removeFolder'],
          '#ajax'                    => [
            'callback' => '::foldersCallback',
            'wrapper'  => 'mlp-folders-wrapper',
          ],
          '#limit_validation_errors' => [],
          '#folder_index'            => $i,
          '#attributes'              => [
            'style' => 'min-width:2.5rem;padding:.4rem .6rem;',
            'title' => $this->t('Remove this folder'),
          ],
        ];
      }
      else {
        // Placeholder to keep grid alignment consistent
        $form['drive']['folders_wrapper']['folders'][$i]['remove'] = [
          '#markup' => '<span></span>',
        ];
      }
    }

    // "Add another folder" button
    $form['drive']['folders_wrapper']['add_folder'] = [
      '#type'                    => 'submit',
      '#value'                   => $this->t('+ Add another folder'),
      '#submit'                  => ['::addFolder'],
      '#ajax'                    => [
        'callback' => '::foldersCallback',
        'wrapper'  => 'mlp-folders-wrapper',
      ],
      '#limit_validation_errors' => [],
      '#attributes'              => [
        'style' => 'margin-top:.5rem;',
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * AJAX callback — returns the updated folders wrapper.
   */
  public function foldersCallback(array &$form, FormStateInterface $form_state): array {
    return $form['drive']['folders_wrapper'];
  }

  /**
   * Submit handler — adds a new empty folder row.
   */
  public function addFolder(array &$form, FormStateInterface $form_state): void {
    $folder_count = $form_state->get('folder_count') ?? 1;
    $form_state->set('folder_count', $folder_count + 1);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler — removes the folder row at the clicked index.
   */
  public function removeFolder(array &$form, FormStateInterface $form_state): void {
    $triggering  = $form_state->getTriggeringElement();
    $remove_index = $triggering['#folder_index'];
    $folder_count = $form_state->get('folder_count') ?? 1;

    // Pull current values, remove the target row, re-index.
    $values  = $form_state->getValue(['folders_wrapper', 'folders']) ?? [];
    $folders = array_values(array_filter(
      $values,
      fn($v, $k) => $k !== $remove_index,
      ARRAY_FILTER_USE_BOTH
    ));

    // Write cleaned values back so the form rebuilds with correct defaults.
    $form_state->setValue(['folders_wrapper', 'folders'], $folders);
    $form_state->set('folder_count', max(1, $folder_count - 1));
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $raw = $form_state->getValue(['folders_wrapper', 'folders']) ?? [];

    // Strip rows where the folder ID is empty.
    $folders = array_values(array_filter($raw, fn($f) => !empty(trim($f['id'] ?? ''))));

    $this->config('mlp_members.settings')
      ->set('drive_enabled', (bool) $form_state->getValue('drive_enabled'))
      ->set('service_account_key_path', trim($form_state->getValue('service_account_key_path') ?? ''))
      ->set('drive_folders', $folders)
      ->save();

    \Drupal::service('mlp_members.google_drive')->clearCache();

    parent::submitForm($form, $form_state);
  }

}
