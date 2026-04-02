<?php

namespace Drupal\voting_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
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
   * The file URL generator service.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The lock backend service.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * The logger channel factory service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The configuration factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a VotingApiController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator service.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FileUrlGeneratorInterface $file_url_generator,
    LockBackendInterface $lock,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $file_url_generator;
    $this->lock = $lock;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
      $container->get('lock'),
      $container->get('logger.factory'),
      $container->get('config.factory')
    );
  }

  /**
   * Returns a list of available questions for voting, including their options.
   *
   * Implements the "Obtenção das Perguntas" requirement.
   * Utilizes CacheableJsonResponse for high-performance reads.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   A cacheable JSON response containing questions and options.
   */
  public function getQuestions(): CacheableJsonResponse {
    $storage = $this->entityTypeManager->getStorage('voting_question');

    $ids = $storage->getQuery()
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->execute();

    $questions = $storage->loadMultiple($ids);
    $questions_data = [];

    $cache_metadata = new CacheableMetadata();
    $cache_metadata->addCacheTags([
      'voting_question_list',
      'voting_option_list',
    ]);
    $cache_metadata->addCacheContexts(['headers:x-api-key']);

    // Load global config and add as cache dependency.
    $config = $this->configFactory->get('voting_api.settings');
    $cache_metadata->addCacheableDependency($config);
    $global_enabled = $config->get('global_voting_enabled') ?? TRUE;

    /** @var \Drupal\voting_system\Entity\VotingQuestion $question */
    foreach ($questions as $question) {
      $options_data = [];
      $options = $question->get('field_voting_options')->referencedEntities();

      foreach ($options as $option) {
        $image_url = '';

        if (!$option->get('field_image')->isEmpty()) {
          /** @var \Drupal\file\FileInterface $file */
          $file = $option->get('field_image')->entity;
          if ($file) {
            $image_uri = $file->getFileUri();
            $image_url = $this->fileUrlGenerator
              ->generateAbsoluteString($image_uri);
            $cache_metadata->addCacheableDependency($file);
          }
        }

        $options_data[] = [
          'id' => $option->id(),
          'title' => (string) $option->label(),
          'description' => (string) $option->get('field_description')->value,
          'image' => $image_url,
        ];

        $cache_metadata->addCacheableDependency($option);
      }

      $questions_data[] = [
        'id' => $question->get('field_machine_name')->value,
        'title' => (string) $question->label(),
        'options' => $options_data,
      ];

      $cache_metadata->addCacheableDependency($question);
    }

    $response_data = [
      'global_voting_enabled' => (bool) $global_enabled,
      'questions' => $questions_data,
    ];

    $response = new CacheableJsonResponse($response_data);
    $response->addCacheableDependency($cache_metadata);

    return $response;
  }

  /**
   * Returns details for a specific question by its machine name.
   *
   * @param string $machine_name
   *   The unique machine name of the question.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse|\Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the question details.
   */
  public function getQuestionDetail(string $machine_name) {
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

    $cache_metadata = new CacheableMetadata();
    $cache_metadata->addCacheContexts(['headers:x-api-key']);
    $cache_metadata->addCacheableDependency($question);

    $config = $this->configFactory->get('voting_api.settings');
    $cache_metadata->addCacheableDependency($config);
    $global_enabled = $config->get('global_voting_enabled') ?? TRUE;

    $options_data = [];
    $options = $question->get('field_voting_options')->referencedEntities();

    foreach ($options as $option) {
      $image_url = '';
      if (!$option->get('field_image')->isEmpty()) {
        $file = $option->get('field_image')->entity;
        if ($file) {
          $image_uri = $file->getFileUri();
          $image_url = $this->fileUrlGenerator
            ->generateAbsoluteString($image_uri);
          $cache_metadata->addCacheableDependency($file);
        }
      }

      $options_data[] = [
        'id' => $option->id(),
        'title' => $option->label(),
        'description' => $option->get('field_description')->value,
        'image' => $image_url,
      ];

      $cache_metadata->addCacheableDependency($option);
    }

    $data = [
      'id' => $question->get('field_machine_name')->value,
      'title' => $question->label(),
      'show_results' => (bool) $question->get('field_show_results')->value,
      'global_voting_enabled' => (bool) $global_enabled,
      'options' => $options_data,
    ];

    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency($cache_metadata);

    return $response;
  }

  /**
   * Registers a vote for a specific question from an external application.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object with JSON data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response indicating the result of the voting operation.
   */
  public function registerVote(Request $request): JsonResponse {
    $content = json_decode($request->getContent(), TRUE);
    $question_machine_id = $content['question_id'] ?? '';
    $option_id = $content['option_id'] ?? '';
    $voter_uuid = $content['voter_uuid'] ?? '';

    if (!$question_machine_id || !$option_id || !$voter_uuid) {
      $msg = 'Missing required voting parameters.';
      return new JsonResponse(['error' => $msg], 400);
    }

    $lock_name = 'voting_api_vote_' . $question_machine_id . '_' . $voter_uuid;

    if (!$this->lock->acquire($lock_name, 5.0)) {
      $this->loggerFactory->get('voting_api.concurrency')->notice(
        'Lock prevented concurrent vote attempt for question @q by voter @v.',
        ['@q' => $question_machine_id, '@v' => $voter_uuid]
      );
      $msg = 'Too many requests. Your vote is already being processed.';
      return new JsonResponse(['error' => $msg], 429);
    }

    try {
      $question_storage = $this->entityTypeManager
        ->getStorage('voting_question');

      $question_ids = $question_storage->getQuery()
        ->condition('field_machine_name', $question_machine_id)
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->execute();

      if (empty($question_ids)) {
        return new JsonResponse(['error' => 'Active question not found.'], 404);
      }
      $internal_question_id = reset($question_ids);

      $vote_storage = $this->entityTypeManager->getStorage('vote');
      $existing_vote = $vote_storage->getQuery()
        ->condition('field_vote_question', $internal_question_id)
        ->condition('field_voter_identifier', $voter_uuid)
        ->accessCheck(FALSE)
        ->range(0, 1)
        ->execute();

      if (!empty($existing_vote)) {
        $this->loggerFactory->get('voting_api.business')->info(
          'Duplicate vote rejected for question @q by voter @v.',
          ['@q' => $internal_question_id, '@v' => $voter_uuid]
        );
        $msg = 'User has already voted for this question.';
        return new JsonResponse(['error' => $msg], 403);
      }

      $vote = $vote_storage->create([
        'field_vote_question' => $internal_question_id,
        'field_vote_option' => $option_id,
        'field_voter_identifier' => $voter_uuid,
        'uid' => 0,
      ]);
      $vote->save();

      return new JsonResponse(['message' => 'Vote recorded successfully.'], 201);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('voting_api.system')->error(
        'Database or system error while registering vote for @q. Error: @msg',
        ['@q' => $question_machine_id, '@msg' => $e->getMessage()]
      );
      $msg = 'An error occurred while processing the vote.';
      return new JsonResponse(['error' => $msg], 500);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Returns the voting results for a specific question.
   *
   * @param string $machine_name
   *   The unique machine name of the question.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse|\Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the vote counts or a hidden status.
   */
  public function getResults(string $machine_name) {
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

    $cache_metadata = new CacheableMetadata();
    $cache_metadata->addCacheContexts(['headers:x-api-key']);
    $cache_metadata->addCacheableDependency($question);

    $config = $this->configFactory->get('voting_api.settings');
    $cache_metadata->addCacheableDependency($config);

    // CRITICAL: Tag ensures cache breaks instantly when a new vote is cast.
    $cache_metadata->addCacheTags(['vote_list']);

    $show_results = (bool) $question->get('field_show_results')->value;

    if (!$show_results) {
      $response_data = [
        'id' => $question->get('field_machine_name')->value,
        'message' => 'Results are currently hidden for this question.',
      ];
      $response = new CacheableJsonResponse($response_data, 200);
      $response->addCacheableDependency($cache_metadata);
      return $response;
    }

    $vote_storage = $this->entityTypeManager->getStorage('vote');
    $options = $question->get('field_voting_options')->referencedEntities();
    $results = [];

    foreach ($options as $option) {
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

    $response_data = [
      'id' => $question->get('field_machine_name')->value,
      'title' => $question->label(),
      'results' => $results,
    ];

    $response = new CacheableJsonResponse($response_data);
    $response->addCacheableDependency($cache_metadata);

    return $response;
  }

}
