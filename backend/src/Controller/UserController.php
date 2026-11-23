<?php
namespace App\Controller;

use App\Entity\{Section, User};
use App\Service\{Presenter, UserManager};
use App\Training\Mft;
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
        // Sections encadrées par chaque compte
        $led = [];
        foreach ($em->getRepository(Section::class)->findBy([], ['name' => 'ASC']) as $s) {
            foreach ($s->getInstructors() as $i) $led[$i->getId()][] = ['id' => $s->getId(), 'name' => $s->getName(), 'color' => $s->getColor()];
        }
        return $this->json(array_map(fn (User $u) => Presenter::user($u) + ['sections' => $led[$u->getId()] ?? []],
            $em->getRepository(User::class)->findBy([], ['name' => 'ASC'])));
    }

    /** {"email","name","password","roles":["ROLE_REFERENT"]} */
    #[Route('', methods: 'POST')]
    public function create(Request $req, UserManager $users, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $this->required($d, 'email', 'name', 'password');
        $user = $users->create($d['email'], $d['name'], $d['password'], (array) ($d['roles'] ?? []));
        $this->applyTraining($user, $d);
        $em->flush();
        return $this->json(Presenter::user($user), 201);
    }

    /** {"name"?, "email"?, "phone"?, "roles"?, "active"?, "password"?, "level"?: "E1"…"E4"|null, "tsi"?, "canIssue"?} */
    #[Route('/{id}', methods: 'PUT')]
    public function update(User $user, Request $req, UserManager $users, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $self = $user === $this->getUser();
        $wasActiveAdmin = $user->isAdmin() && $user->isActive();

        $users->updateProfile($user, array_intersect_key($d, array_flip(['name', 'email', 'phone'])));
        if (isset($d['roles'])) $user->setRoles((array) $d['roles']);
        if (isset($d['active'])) {
            if ($self && !$d['active']) throw new BadRequestHttpException('Vous ne pouvez pas désactiver votre propre compte');
            $user->setActive((bool) $d['active']);
        }
        if (!empty($d['password'])) $users->setPassword($user, $d['password']);
        $this->applyTraining($user, $d);

        if ($wasActiveAdmin && !($user->isAdmin() && $user->isActive()) && $users->countActiveAdmins() <= 1) {
            throw new BadRequestHttpException('Il doit rester au moins un administrateur actif');
        }
        $em->flush();
        return $this->json(Presenter::user($user));
    }

    /** Niveau d'encadrement (E1 à E4), tuteur de stage initiateur, délivrance du N1 (président). */
    private function applyTraining(User $user, array $d): void
    {
        if (array_key_exists('level', $d)) {
            if ($d['level'] !== null && $d['level'] !== '' && !in_array($d['level'], Mft::LEVELS, true)) throw new BadRequestHttpException('Niveau d\'encadrement invalide (E1 à E4)');
            $user->setLevel($d['level'] ?: null);
        }
        if (isset($d['tsi'])) $user->setTsi((bool) $d['tsi']);
        if (isset($d['canIssue'])) $user->setCanIssue((bool) $d['canIssue']);
    }
}
