<?php

namespace Drupal\voting_api\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Validates API keys and formats exceptions for API routes.
 */
class ApiKeySubscriber implements EventSubscriberInterface {

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * The logger factory.
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
   * Constructs an ApiKeySubscriber object.
   *
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory service.
   */
  public function __construct(
    KeyRepositoryInterface $key_repository,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
  ) {
    $this->keyRepository = $key_repository;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Listen to the request to validate the key (priority 300 to run early).
    $events[KernelEvents::REQUEST][] = ['onKernelRequest', 300];
    // Listen for exceptions to format 404/500 errors as JSON.
    $events[KernelEvents::EXCEPTION][] = ['onKernelException', 50];

    return $events;
  }

  /**
   * Validates the API key and global voting status on incoming requests.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event to process.
   */
  public function onKernelRequest(RequestEvent $event) {
    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // Only apply to /api/ routes.
    if (strpos($path, '/api/') === 0) {

      // 1. API Key Validation.
      $provided_key = $request->headers->get('x-api-key');

      if (empty($provided_key)) {
        $this->loggerFactory->get('voting_api.security')->warning(
          'Failed API access attempt with invalid or missing key from IP: @ip',
          ['@ip' => $request->getClientIp()]
        );
        $msg = 'Access denied: Missing API Key header (x-api-key).';
        $response = new JsonResponse(['error' => $msg], 401);
        $event->setResponse($response);
        return;
      }

      $key_entity = $this->keyRepository->getKey('voting_api_key');

      if (!$key_entity || $key_entity->getKeyValue() !== $provided_key) {
        $this->loggerFactory->get('voting_api.security')->warning(
          'Failed API access attempt with invalid or missing key from IP: @ip',
          ['@ip' => $request->getClientIp()]
        );
        $msg = 'Access denied: Invalid API Key.';
        $response = new JsonResponse(['error' => $msg], 403);
        $event->setResponse($response);
        return;
      }

      // 2. Global Voting Status Validation.
      // Block POST requests to /api/v1/vote if voting is globally disabled.
      if ($request->getMethod() === 'POST' && $path === '/api/v1/vote') {
        $config = $this->configFactory->get('voting_api.settings');
        $is_voting_enabled = $config->get('global_voting_enabled') ?? TRUE;

        if (!$is_voting_enabled) {
          $msg = 'Forbidden: Voting is currently disabled globally.';
          $response = new JsonResponse(['error' => $msg], 403);
          $event->setResponse($response);
        }
      }
    }
  }

  /**
   * Formats exceptions as JSON responses for /api/ paths.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The event to process.
   */
  public function onKernelException(ExceptionEvent $event) {
    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // Intercept errors only for our API routes.
    if (strpos($path, '/api/') === 0) {
      $exception = $event->getThrowable();

      // Catches not found routes (404).
      if ($exception instanceof NotFoundHttpException) {
        $msg = 'Not Found: The requested API endpoint does not exist.';
        $response = new JsonResponse(['error' => $msg], 404);
        $event->setResponse($response);
      }
      // You can add other "if" here to catch AccessDeniedHttpException etc.
      // but our onKernelRequest is already shielding access.
    }
  }

}
