<?php

namespace Drupal\voting_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Controller for the CMS User Interface.
 *
 * Handles the display of voting questions within the Drupal admin theme.
 */
class VotingUiController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a VotingUiController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Renders a list of available questions for CMS users to vote on.
   *
   * @return array
   * A Drupal render array.
   */
  public function listQuestions(): array {

    // Check global voting status.
    $is_voting_enabled = \Drupal::config('voting_api.settings')->get('global_voting_enabled') ?? TRUE;

    if (!$is_voting_enabled) {
      return [
        '#markup' => '<div class="messages messages--warning">' . $this->t('The voting system is currently disabled globally.') . '</div>',
      ];
    }

    $storage = $this->entityTypeManager->getStorage('voting_question');

    // Fetch only active questions.
    $ids = $storage->getQuery()
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->execute();

    if (empty($ids)) {
      return [
        '#markup' => $this->t('There are no active questions available for voting at this time.'),
      ];
    }

    $questions = $storage->loadMultiple($ids);
    $items = [];

    /** @var \Drupal\voting_system\Entity\VotingQuestion $question */
    foreach ($questions as $question) {
      $machine_name = $question->get('field_machine_name')->value;

      // Create a link to the voting form using the machine name.
      $url = Url::fromRoute('voting_api.vote_form', ['machine_name' => $machine_name]);
      $link = Link::fromTextAndUrl($question->label(), $url)->toString();

      $items[] = ['#markup' => $link];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#title' => $this->t('Select a question to vote:'),
    ];
  }

}
