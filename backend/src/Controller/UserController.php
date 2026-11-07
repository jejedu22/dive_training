<?php
namespace App\Controller;

use App\Entity\User;
use App\Service\{Presenter, UserManager};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Gestion des comptes encadrants : réservée aux administrateurs. */
#[Route('/api/users'), IsGranted('ROLE_ADMIN')]
class UserController extends ApiController
{
    #[Route('', methods: 'GET')]
    public function list(EntityManagerInterface $em): JsonResponse
    {
        return $this->json(array_map(Presenter::user(...), $em->getRepository(User::class)->findBy([], ['name' => 'ASC'])));
    }

    /** {"email","name","password","roles":["ROLE_REFERENT"]} */
    #[Route('', methods: 'POST')]
    public function create(Request $req, UserManager $users): JsonResponse
    {
        $d = $this->body($req);
        $this->required($d, 'email', 'name', 'password');
        return $this->json(Presenter::user($users->create($d['email'], $d['name'], $d['password'], (array) ($d['roles'] ?? []))), 201);
    }

    /** {"name"?, "roles"?, "active"?, "password"?} */
    #[Route('/{id}', methods: 'PUT')]
    public function update(User $user, Request $req, UserManager $users, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $self = $user === $this->getUser();
        $wasActiveAdmin = $user->isAdmin() && $user->isActive();

        if (isset($d['name']) && trim($d['name']) !== '') $user->setName(trim($d['name']));
        if (isset($d['roles'])) $user->setRoles((array) $d['roles']);
        if (isset($d['active'])) {
            if ($self && !$d['active']) throw new BadRequestHttpException('Vous ne pouvez pas désactiver votre propre compte');
            $user->setActive((bool) $d['active']);
        }
        if (!empty($d['password'])) $users->setPassword($user, $d['password']);

        if ($wasActiveAdmin && !($user->isAdmin() && $user->isActive()) && $users->countActiveAdmins() <= 1) {
            throw new BadRequestHttpException('Il doit rester au moins un administrateur actif');
        }
        $em->flush();
        return $this->json(Presenter::user($user));
    }
}
