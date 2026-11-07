<?php
namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Liste légère des encadrants actifs (pour les associer aux sections). */
class InstructorController extends ApiController
{
    #[Route('/api/instructors', methods: 'GET')]
    public function list(EntityManagerInterface $em): JsonResponse
    {
        return $this->json(array_map(fn (User $u) => ['id' => $u->getId(), 'name' => $u->getName()],
            $em->getRepository(User::class)->findBy(['active' => true], ['name' => 'ASC'])));
    }
}
