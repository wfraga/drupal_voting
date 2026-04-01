<?php

namespace Drupal\voting_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for manual voting API endpoints.
 */
class VotingApiController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a VotingApiController object.
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
   * Returns a list of available questions for voting.
   * * Complies with the "Obtenção das Perguntas" requirement.
   */
  public function getQuestions(): JsonResponse {
    $storage = $this->entityTypeManager->getStorage('voting_question');

    // Load only active questions (status = 1).
    $ids = $storage->getQuery()
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->execute();

    $questions = $storage->loadMultiple($ids);
    $data = [];

    foreach ($questions as $question) {
      $data[] = [
        'id' => $question->get('field_machine_name')->value,
        'title' => $question->label(),
      ];
    }

    return new JsonResponse($data);
  }

  /**
   * Returns details for a specific question by its machine name.
   * * Complies with the "Exibição da Pergunta Selecionada" requirement.
   * Includes options with image, title, and description.
   */
  public function getQuestionDetail(string $machine_name): JsonResponse {
    $storage = $this->entityTypeManager->getStorage('voting_question');

    $ids = $storage->getQuery()
      ->condition('field_machine_name', $machine_name)
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return new JsonResponse(['error' => 'Question not found'], 404);
    }

    /** @var \Drupal\voting_system\Entity\VotingQuestion $question */
    $question = $storage->load(reset($ids));

    // Load options through the entity reference field.
    $options_data = [];
    $options = $question->get('field_options')->referencedEntities();

    foreach ($options as $option) {
      $image_url = '';
      if (!$option->get('field_image')->isEmpty()) {
        $file = $option->get('field_image')->entity;
        $image_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
      }

      $options_data[] = [
        'id' => $option->id(),
        'title' => $option->label(),
        'description' => $option->get('field_description')->value,
        'image' => $image_url,
      ];
    }

    return new JsonResponse([
      'id' => $question->get('field_machine_name')->value,
      'title' => $question->label(),
      'show_results' => (bool) $question->get('field_show_results')->value,
      'options' => $options_data,
    ]);
  }
}
