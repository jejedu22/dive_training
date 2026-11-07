<?php
namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Erreurs de l'API en JSON {code, message}, y compris en production.
 * Sans cela Symfony remplace en prod nos messages métier (« Ligne déjà réservée… ») par « Conflict ».
 * Les erreurs inattendues restent masquées côté client et sont journalisées.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public static function getSubscribedEvents(): array
    {
        // Après le pare-feu (qui transforme les erreurs d'accès en 401/403), avant le rendu par défaut
        return [KernelEvents::EXCEPTION => ['onException', -10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) return;
        $e = $event->getThrowable();

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage() !== '' ? $e->getMessage() : (JsonResponse::$statusTexts[$status] ?? 'Erreur');
            $event->setResponse(new JsonResponse(['code' => $status, 'message' => $message], $status, $e->getHeaders()));
            return;
        }
        $this->logger->error('Erreur API : ' . $e->getMessage(), ['exception' => $e]);
        $event->setResponse(new JsonResponse(['code' => 500, 'message' => 'Erreur interne du serveur'], 500));
    }
}
