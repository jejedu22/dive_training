<?php
namespace App\Controller;

use App\Service\Presenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class MeController extends ApiController
{
    #[Route('/api/me', methods: 'GET')]
    public function me(): JsonResponse { return $this->json(Presenter::user($this->getUser())); }
}
