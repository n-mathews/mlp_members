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
      '#type'  => 'details',
      '#title' => $this->t('Google Drive integration'),
      '#open'  => TRUE,
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
        'Store this <strong>outside the webroot</strong> for security, e.g. <code>/etc/mlp/google-service-account.json</code>.'
      ),
      '#placeholder'   => '/etc/mlp/google-service-account.json',
    ];

    // Folder configuration — up to 8 folders.
    $form['drive']['folders'] = [
      '#type'        => 'details',
      '#title'       => $this->t('Drive folders'),
      '#open'        => TRUE,
      '#description' => $this->t(
        'Each folder appears as a separate section in the Documents page. ' .
        'The folder ID is the string after /folders/ in the Google Drive URL.'
      ),
      '#tree'        => TRUE,
    ];

    $folders = $config->get('drive_folders') ?? [];
    // Ensure we always show at least 4 rows.
    $folders = array_pad($folders, max(4, count($folders)), ['label' => '', 'id' => '']);

    foreach ($folders as $i => $folder) {
      $form['drive']['folders'][$i] = [
        '#type'  => 'container',
        '#attributes' => ['style' => 'display:flex; gap:1rem; margin-bottom:.5rem;'],
      ];
      $form['drive']['folders'][$i]['label'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Section label'),
        '#default_value' => $folder['label'] ?? '',
        '#placeholder'   => $this->t('e.g. Board Minutes'),
        '#size'          => 30,
      ];
      $form['drive']['folders'][$i]['id'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Folder ID'),
        '#default_value' => $folder['id'] ?? '',
        '#placeholder'   => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs',
        '#size'          => 45,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $folders_raw = $form_state->getValue('folders') ?? [];
    // Strip empty rows.
    $folders = array_values(array_filter($folders_raw, fn($f) => !empty($f['id'])));

    $this->config('mlp_members.settings')
      ->set('drive_enabled', (bool) $form_state->getValue('drive_enabled'))
      ->set('service_account_key_path', trim($form_state->getValue('service_account_key_path')))
      ->set('drive_folders', $folders)
      ->save();

    // Clear the Drive cache whenever settings change.
    \Drupal::service('mlp_members.google_drive')->clearCache();

    parent::submitForm($form, $form_state);
  }

}
