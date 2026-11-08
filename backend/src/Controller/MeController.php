<?php
namespace App\Controller;

use App\Entity\User;
use App\Service\{Presenter, UserManager};
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Le compte de l'utilisateur connecté : profil, préférences, mot de passe. */
#[Route('/api/me')]
class MeController extends ApiController
{
    #[Route('', methods: 'GET')]
    public function me(): JsonResponse { return $this->json(Presenter::user($this->getUser(), true)); }

    /**
     * Profil et préférences : {"name","email","phone","avatarColor","preferences":{"homePage","planningView","onlyMine"}}
     * Changer d'e-mail demande le mot de passe actuel ("currentPassword") et renvoie un nouveau jeton
     * (l'e-mail est l'identifiant de connexion : l'ancien jeton n'est plus valable).
     */
    #[Route('', methods: 'PUT')]
    public function update(Request $req, UserManager $users, EntityManagerInterface $em, JWTTokenManagerInterface $jwt): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $d = $this->body($req);
        $wantsNewEmail = isset($d['email']) && mb_strtolower(trim((string) $d['email'])) !== $user->getEmail();
        if ($wantsNewEmail && !$users->isPasswordValid($user, (string) ($d['currentPassword'] ?? ''))) {
            throw new BadRequestHttpException('Indiquez votre mot de passe actuel pour changer d\'e-mail');
        }
        $emailChanged = $users->updateProfile($user, array_intersect_key($d, array_flip(['name', 'email', 'phone', 'avatarColor', 'preferences'])));
        $em->flush();
        $data = Presenter::user($user, true);
        if ($emailChanged) $data['token'] = $jwt->create($user);
        return $this->json($data);
    }

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
        return $this->json(Presenter::user($user, true));
    }
}
