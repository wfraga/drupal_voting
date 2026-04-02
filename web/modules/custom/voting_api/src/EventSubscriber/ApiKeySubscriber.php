<?php

namespace Drupal\voting_api\EventSubscriber;

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
   * Constructs an ApiKeySubscriber object.
   *
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   * The key repository service.
   */
  public function __construct(KeyRepositoryInterface $key_repository) {
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Escuta a requisição para validar a chave (prioridade 300 para rodar cedo).
    $events[KernelEvents::REQUEST][] = ['onKernelRequest', 300];
    // Escuta exceções para formatar erros 404/500 como JSON.
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
        $response = new JsonResponse(['error' => 'Access denied: Missing API Key header (x-api-key).'], 401);
        $event->setResponse($response);
        return;
      }

      $key_entity = $this->keyRepository->getKey('voting_api_key');

      if (!$key_entity || $key_entity->getKeyValue() !== $provided_key) {
        $response = new JsonResponse(['error' => 'Access denied: Invalid API Key.'], 403);
        $event->setResponse($response);
        return;
      }

      // 2. Global Voting Status Validation.
      // Block POST requests to /api/v1/vote if voting is globally disabled.
      if ($request->getMethod() === 'POST' && $path === '/api/v1/vote') {
        $is_voting_enabled = \Drupal::config('voting_api.settings')->get('global_voting_enabled');

        // If the config does not exist yet, default to TRUE.
        $is_voting_enabled = $is_voting_enabled ?? TRUE;

        if (!$is_voting_enabled) {
          $response = new JsonResponse(['error' => 'Forbidden: Voting is currently disabled globally.'], 403);
          $event->setResponse($response);
        }
      }
    }
  }

  /**
   * Formats exceptions as JSON responses for /api/ paths.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   * The event to process.
   */
  public function onKernelException(ExceptionEvent $event) {
    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // Intercepta erros apenas para as rotas da nossa API
    if (strpos($path, '/api/') === 0) {
      $exception = $event->getThrowable();

      // Captura rotas não encontradas (404)
      if ($exception instanceof NotFoundHttpException) {
        $response = new JsonResponse(['error' => 'Not Found: The requested API endpoint does not exist.'], 404);
        $event->setResponse($response);
      }
      // Você pode adicionar outros "if" aqui para capturar AccessDeniedHttpException etc,
      // mas o nosso onKernelRequest já está blindando o acesso.
    }
  }

}
