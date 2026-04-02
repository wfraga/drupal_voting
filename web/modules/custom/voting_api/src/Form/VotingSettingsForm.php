<?php

namespace Drupal\voting_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure global settings for the Voting System.
 */
class VotingSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'voting_api_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['voting_api.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('voting_api.settings');

    $form['global_voting_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Global Voting'),
      '#description' => $this->t('Uncheck this box to completely disable the voting flow across the CMS and external API.'),
      '#default_value' => $config->get('global_voting_enabled') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('voting_api.settings')
      ->set('global_voting_enabled', (bool) $form_state->getValue('global_voting_enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
