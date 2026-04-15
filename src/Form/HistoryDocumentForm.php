<?php

namespace Drupal\mlp_members\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Form for members to submit historical documents.
 */
class HistoryDocumentForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mlp_history_document_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['title'] = [
      '#type'        => 'textfield',
      '#title'       => $this->t('Title'),
      '#description' => $this->t('A brief title for this document or photo.'),
      '#required'    => TRUE,
      '#maxlength'   => 255,
    ];

    $form['description'] = [
      '#type'        => 'textarea',
      '#title'       => $this->t('Description'),
      '#description' => $this->t('Optional context — approximate year, event, or people pictured.'),
      '#rows'        => 4,
    ];

    $form['file'] = [
      '#type'             => 'managed_file',
      '#title'            => $this->t('File'),
      '#description'      => $this->t('Accepted formats: PDF, JPG, PNG, GIF, TIFF, DOC, DOCX. Maximum 20 MB.'),
      '#required'         => TRUE,
      '#upload_location'  => 'private://history-documents/',
      '#upload_validators' => [
        'file_validate_extensions' => ['pdf jpg jpeg png gif tif tiff doc docx'],
        'file_validate_size'       => [20 * 1024 * 1024],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Submit for review'),
    ];

    $form['actions']['cancel'] = [
      '#type'  => 'link',
      '#title' => $this->t('Cancel'),
      '#url'   => \Drupal\Core\Url::fromUri('internal:/history'),
      '#attributes' => ['class' => ['btn', 'btn--outline']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $fid   = $form_state->getValue(['file', 0]);
    $file  = \Drupal::entityTypeManager()->getStorage('file')->load($fid);

    if ($file) {
      $file->setPermanent();
      $file->save();
    }

    $node = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type'                       => 'history_document',
      'title'                      => $form_state->getValue('title'),
      'field_document_description' => ['value' => $form_state->getValue('description'), 'format' => 'plain_text'],
      'field_document_file'        => ['target_id' => $fid],
      'status'                     => 0, // Unpublished — pending review.
      'uid'                        => \Drupal::currentUser()->id(),
    ]);
    $node->save();

    \Drupal::messenger()->addStatus($this->t(
      'Thank you! Your document has been submitted and will appear after review.'
    ));

    $form_state->setRedirect('mlp_members.history');
  }

}
