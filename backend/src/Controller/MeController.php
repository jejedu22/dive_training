<?php
namespace App\Controller;

use App\Service\{Presenter, UserManager};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/me')]
class MeController extends ApiController
{
    #[Route('', methods: 'GET')]
    public function me(): JsonResponse { return $this->json(Presenter::user($this->getUser())); }

    /** {"currentPassword":"…","newPassword":"…"} */
    #[Route('/password', methods: 'PUT')]
    public function password(Request $req, UserManager $users, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $this->required($d, 'currentPassword', 'newPassword');
        $user = $this->getUser();
        if (!$users->isPasswordValid($user, $d['currentPassword'])) throw new BadRequestHttpException('Mot de passe actuel incorrect');
        $users->setPassword($user, $d['newPassword']);
        $em->flush();
        return $this->json(Presenter::user($user));
    }
}
