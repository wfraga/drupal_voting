<?php

namespace Drupal\voting_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;

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
   * Returns a list of available questions for voting, including their options.
   *
   * Implements the "Obtenção das Perguntas" requirement.
   * Utilizes CacheableJsonResponse for high-performance reads under heavy load.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   A cacheable JSON response containing questions and their respective options.
   */
  public function getQuestions(): CacheableJsonResponse {
    $storage = $this->entityTypeManager->getStorage('voting_question');

    // Fetch only active questions (status = 1).
    $ids = $storage->getQuery()
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->execute();

    $questions = $storage->loadMultiple($ids);
    $data = [];

    // Initialize cache metadata.
    $cache_metadata = new CacheableMetadata();
    // Add list tags so the cache clears automatically if any question or option is added/deleted.
    $cache_metadata->addCacheTags(['voting_question_list', 'voting_option_list']);
    $cache_metadata->addCacheContexts(['headers:x-api-key']);

    /** @var \Drupal\voting_system\Entity\VotingQuestion $question */
    foreach ($questions as $question) {
      $options_data = [];
      $options = $question->get('field_voting_options')->referencedEntities();

      foreach ($options as $option) {
        $image_url = '';

        // Check and generate the absolute URL for the image.
        if (!$option->get('field_image')->isEmpty()) {
          /** @var \Drupal\file\FileInterface $file */
          $file = $option->get('field_image')->entity;
          if ($file) {
            $image_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
            // Add the file entity as a cache dependency.
            $cache_metadata->addCacheableDependency($file);
          }
        }

        $options_data[] = [
          'id' => $option->id(),
          'title' => (string) $option->label(),
          'description' => (string) $option->get('field_description')->value,
          'image' => $image_url,
        ];

        // Add the individual option entity as a cache dependency.
        $cache_metadata->addCacheableDependency($option);
      }

      $data[] = [
        'id' => $question->get('field_machine_name')->value,
        'title' => (string) $question->label(),
        'options' => $options_data,
      ];

      // Add the individual question entity as a cache dependency.
      $cache_metadata->addCacheableDependency($question);
    }

    // Return the Cacheable response instead of a standard JsonResponse.
    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency($cache_metadata);

    return $response;
  }

  /**
   * Returns details for a specific question by its machine name.
   *
   * Complies with the "Exibição da Pergunta Selecionada" requirement.
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
    $options = $question->get('field_voting_options')->referencedEntities();

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

  /**
   * Registers a vote for a specific question from an external application.
   *
   * Implements the "Registro dos Votos" requirement.
   * Ensures high concurrency support and data integrity using Drupal's Lock API.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *  The request object containing JSON data (question_id, option_id, voter_uuid).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *  A JSON response indicating the result of the voting operation.
   */
  public function registerVote(Request $request): JsonResponse {
    $content = json_decode($request->getContent(), TRUE);
    $question_machine_id = $content['question_id'] ?? '';
    $option_id = $content['option_id'] ?? '';
    $voter_uuid = $content['voter_uuid'] ?? '';

    // Validate required parameters for security and data integrity.
    if (!$question_machine_id || !$option_id || !$voter_uuid) {
      return new JsonResponse(['error' => 'Missing required voting parameters.'], 400);
    }

    // Initialize the Lock service for concurrency control.
    /** @var \Drupal\Core\Lock\LockBackendInterface $lock */
    $lock = \Drupal::service('lock');

    // Create a unique lock name based on the question and the voter.
    $lock_name = 'voting_api_vote_' . $question_machine_id . '_' . $voter_uuid;

    // Try to acquire a lock for up to 5 seconds. If it fails, it means another
    // concurrent request from the same user is already processing.
    if (!$lock->acquire($lock_name, 5.0)) {
      return new JsonResponse(['error' => 'Too many requests. Your vote is already being processed.'], 429);
    }

    try {
      $question_storage = $this->entityTypeManager->getStorage('voting_question');

      // Resolve the internal entity ID using the unique machine identifier.
      $question_ids = $question_storage->getQuery()
        ->condition('field_machine_name', $question_machine_id)
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->execute();

      if (empty($question_ids)) {
        return new JsonResponse(['error' => 'Active question not found.'], 404);
      }
      $internal_question_id = reset($question_ids);

      // Ensure the vote is unique per user/UUID for each question.
      $vote_storage = $this->entityTypeManager->getStorage('vote');
      $existing_vote = $vote_storage->getQuery()
        ->condition('field_vote_question', $internal_question_id)
        ->condition('field_voter_identifier', $voter_uuid)
        ->accessCheck(FALSE)
        ->range(0, 1)
        ->execute();

      if (!empty($existing_vote)) {
        return new JsonResponse(['error' => 'User has already voted for this question.'], 403);
      }

      // Register the vote.
      $vote = $vote_storage->create([
        'field_vote_question' => $internal_question_id,
        'field_vote_option' => $option_id,
        'field_voter_identifier' => $voter_uuid,
        'uid' => 0, // Anonymous for external API.
      ]);
      $vote->save();

      return new JsonResponse(['message' => 'Vote recorded successfully.'], 201);
    }
    catch (\Exception $e) {
      \Drupal::logger('voting_api')->error('Voting error: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['error' => 'An error occurred while processing the vote.'], 500);
    }
    finally {
      // CRITICAL: Always release the lock when done, even if an exception occurred!
      $lock->release($lock_name);
    }
  }

  /**
   * Returns the voting results for a specific question.
   *
   * Implements the "Exibição dos Resultados" requirement.
   * Checks if results should be displayed based on question configuration.
   *
   * @param string $machine_name
   * The unique machine name of the question.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   * A JSON response containing the vote counts or a hidden status.
   */
  public function getResults(string $machine_name): JsonResponse {
    $question_storage = $this->entityTypeManager->getStorage('voting_question');

    $ids = $question_storage->getQuery()
      ->condition('field_machine_name', $machine_name)
      ->accessCheck(TRUE)
      ->execute();

    if (empty($ids)) {
      return new JsonResponse(['error' => 'Question not found'], 404);
    }

    /** @var \Drupal\voting_system\Entity\VotingQuestion $question */
    $question = $question_storage->load(reset($ids));

    // Check configuration to determine if results should be shown.
    $show_results = (bool) $question->get('field_show_results')->value;

    if (!$show_results) {
      return new JsonResponse([
        'id' => $question->get('field_machine_name')->value,
        'message' => 'Results are currently hidden for this question.',
      ], 200);
    }

    $vote_storage = $this->entityTypeManager->getStorage('vote');
    $options = $question->get('field_voting_options')->referencedEntities();
    $results = [];

    foreach ($options as $option) {
      // Count votes per option. High performance query without loading entities.
      $count = $vote_storage->getQuery()
        ->condition('field_vote_question', $question->id())
        ->condition('field_vote_option', $option->id())
        ->accessCheck(FALSE)
        ->count()
        ->execute();

      $results[] = [
        'option_id' => $option->id(),
        'option_title' => $option->label(),
        'votes' => (int) $count,
      ];
    }

    return new JsonResponse([
      'id' => $question->get('field_machine_name')->value,
      'title' => $question->label(),
      'results' => $results,
    ]);
  }

}
