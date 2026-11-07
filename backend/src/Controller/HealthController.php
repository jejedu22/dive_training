<?php
namespace App\Controller;

use App\Service\UserManager;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Diagnostic public : l'API répond-elle, la base est-elle joignable, existe-t-il des comptes ? */
class HealthController extends ApiController
{
    #[Route('/api/health', methods: 'GET')]
    public function health(Connection $db, UserManager $users): JsonResponse
    {
        $data = ['api' => 'ok', 'database' => 'ok', 'hasUsers' => null, 'jwtKeys' => is_readable($this->getParameter('kernel.project_dir') . '/config/jwt/private.pem') ? 'ok' : 'manquantes'];
        try {
            $db->executeQuery('SELECT 1');
            $data['hasUsers'] = $users->count() > 0;
        } catch (\Throwable $e) {
            $data['database'] = 'erreur';
        }
        $ok = $data['database'] === 'ok' && $data['jwtKeys'] === 'ok';
        return $this->json($data, $ok ? 200 : 503);
    }
}
