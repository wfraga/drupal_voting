<?php

namespace Drupal\voting_api\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\voting_system\Entity\VotingQuestion;
use Drupal\Core\Render\Markup;
use Drupal\Component\Utility\Html;

/**
 * Provides a voting form for the CMS interface.
 */
class VotingForm extends FormBase {

  protected $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container) {
    return new static($container->get('entity_type.manager'));
  }

  public function getFormId() {
    return 'voting_api_vote_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $machine_name = NULL) {
    // Load question by unique machine identifier.
    $questions = $this->entityTypeManager->getStorage('voting_question')
      ->loadByProperties(['field_machine_name' => $machine_name]);

    if (empty($questions)) {
      return ['#markup' => $this->t('Question not found.')];
    }

    /** @var \Drupal\voting_system\Entity\VotingQuestion $question */
    $question = reset($questions);
    $form['#question'] = $question;

    // Display the Question Title at the top of the form.
    $form['question_title'] = [
      '#markup' => '<h2>' . Html::escape((string) $question->label()) . '</h2>',
      '#weight' => -100,
    ];

    // Retrieve the user's existing vote, if any.
    $user_vote = $this->getUserVote($question->id());

    // If the user has voted, show the results or the "already voted" message.
    if ($user_vote) {
      return $this->showResultsOrMessage($question, $user_vote);
    }

    $options = [];
    foreach ($question->get('field_voting_options')->referencedEntities() as $option) {
      $title = (string) $option->label();
      $description = (string) $option->get('field_description')->value;

      // Check and generate the image HTML if it exists.
      $image_html = '';
      if (!$option->get('field_image')->isEmpty()) {
        /** @var \Drupal\file\FileInterface $file */
        $file = $option->get('field_image')->entity;
        if ($file) {
          $image_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
          $image_html = '<div style="margin-bottom: 5px;"><img src="' . $image_url . '" alt="' . Html::escape($title) . '" style="max-width: 150px; height: auto; border: 1px solid #ccc; border-radius: 4px;" /></div>';
        }
      }

      // Build the structured HTML for the radio button label.
      $label_html = '<div class="voting-option-wrapper" style="display: inline-block; vertical-align: top; margin-left: 8px; margin-bottom: 15px;">';
      $label_html .= $image_html;
      $label_html .= '<strong style="display: block; font-size: 1.1em;">' . Html::escape($title) . '</strong>';

      if (!empty($description)) {
        // Escaping description to ensure security while allowing basic HTML structure.
        $label_html .= '<span style="color: #555; font-size: 0.9em; display: block; margin-top: 4px;">' . Html::escape($description) . '</span>';
      }
      $label_html .= '</div>';

      // Use Markup::create() so Drupal renders the HTML safely instead of escaping it as plain text.
      $options[$option->id()] = Markup::create($label_html);
    }

    $form['vote_option'] = [
      '#type' => 'radios',
      '#title' => $this->t('Select your option:'),
      '#options' => $options,
      '#required' => TRUE,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Vote Now'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Check global voting status.
    $is_voting_enabled = \Drupal::config('voting_api.settings')->get('global_voting_enabled') ?? TRUE;

    if (!$is_voting_enabled) {
      return [
        '#markup' => '<div class="messages messages--warning">' . $this->t('The voting system is currently disabled globally. You cannot cast a vote at this time.') . '</div>',
      ];
    }

    $question = $form['#question'];
    $uid = \Drupal::currentUser()->id();

    // Create the Vote entity[cite: 20].
    $vote = $this->entityTypeManager->getStorage('vote')->create([
      'field_vote_question' => $question->id(),
      'field_vote_option' => $form_state->getValue('vote_option'),
      'uid' => $uid,
      'field_voter_identifier' => 'cms_user_' . $uid, // Internal identifier.
    ]);
    $vote->save();

    $this->messenger()->addStatus($this->t('Your vote has been recorded.'));
  }

  /**
   * Retrieves the user's vote for a given question.
   *
   * @param int $question_id
   * The internal ID of the question.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   * The vote entity or NULL if the user hasn't voted.
   */
  private function getUserVote(int $question_id) {
    $uid = \Drupal::currentUser()->id();
    $query = $this->entityTypeManager->getStorage('vote')->getQuery()
      ->condition('field_vote_question', $question_id)
      ->condition('uid', $uid)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    if (!empty($query)) {
      return $this->entityTypeManager->getStorage('vote')->load(reset($query));
    }
    return NULL;
  }

  /**
   * Shows the user's vote and the overall results based on configuration.
   *
   * @param \Drupal\voting_system\Entity\VotingQuestion $question
   * The question entity.
   * @param \Drupal\Core\Entity\EntityInterface $user_vote
   * The vote entity belonging to the current user.
   *
   * @return array
   * A render array with the message and optional results.
   */
  private function showResultsOrMessage(VotingQuestion $question, $user_vote): array {
    $build = [];

    // Cast the label to string to strictly comply with PHP 8.3.
    $question_title = (string) $question->label();

    // Display the Question Title at the top.
    $build['question_title'] = [
      '#markup' => '<h2>' . Html::escape($question_title) . '</h2>',
      '#weight' => -100,
    ];

    // Extract the chosen option entity.
    $voted_option = $user_vote->get('field_vote_option')->entity;
    $option_label = $voted_option ? (string) $voted_option->label() : (string) $this->t('Unknown option');

    // Display the personalized feedback.
    $build['user_feedback'] = [
      '#markup' => '<div class="messages messages--status" style="margin-bottom: 20px;"><strong>' . $this->t('You have already participated in this vote.') . '</strong><br>' . $this->t('Your choice: @choice', ['@choice' => $option_label]) . '</div>',
    ];

    // Check configuration to determine if results should be shown.
    $show_results = (bool) $question->get('field_show_results')->value;

    if ($show_results) {
      $vote_storage = $this->entityTypeManager->getStorage('vote');

      // 1. Get total votes for the question.
      $total_votes = $vote_storage->getQuery()
        ->condition('field_vote_question', $question->id())
        ->accessCheck(FALSE)
        ->count()
        ->execute();

      $results_html = '<div class="voting-results" style="margin-top: 20px; padding: 20px; background: #f8f9fa; border: 1px solid #e1e4e8; border-radius: 6px;">';
      $results_html .= '<h3 style="margin-top: 0; margin-bottom: 15px;">' . $this->t('Current Results') . '</h3>';

      if ($total_votes > 0) {
        $options = $question->get('field_voting_options')->referencedEntities();

        // 2. Calculate and display results for each option.
        foreach ($options as $option) {
          $option_votes = $vote_storage->getQuery()
            ->condition('field_vote_question', $question->id())
            ->condition('field_vote_option', $option->id())
            ->accessCheck(FALSE)
            ->count()
            ->execute();

          $percentage = round(($option_votes / $total_votes) * 100, 1);
          $option_title = Html::escape((string) $option->label());

          // Build a simple visual bar.
          $results_html .= '<div style="margin-bottom: 15px;">';
          $results_html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 5px;">';
          $results_html .= '<strong>' . $option_title . '</strong>';
          $results_html .= '<span>' . $option_votes . ' ' . $this->t('votes') . ' (' . $percentage . '%)</span>';
          $results_html .= '</div>';
          $results_html .= '<div style="background: #e9ecef; border-radius: 4px; width: 100%; height: 24px; overflow: hidden;">';
          $results_html .= '<div style="background: #003cc5; height: 100%; border-radius: 4px; width: ' . $percentage . '%; transition: width 0.5s ease;"></div>';
          $results_html .= '</div></div>';
        }
        $results_html .= '<div style="margin-top: 20px; padding-top: 10px; border-top: 1px solid #dee2e6; font-size: 0.9em; color: #6c757d;">';
        $results_html .= $this->t('Total votes: @total', ['@total' => $total_votes]);
        $results_html .= '</div>';
      } else {
        $results_html .= '<p>' . $this->t('No votes have been cast yet.') . '</p>';
      }

      $results_html .= '</div>';

      $build['results_placeholder'] = [
        '#markup' => Markup::create($results_html),
      ];
    }

    return $build;
  }

}
