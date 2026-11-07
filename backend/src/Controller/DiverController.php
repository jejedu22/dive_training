<?php
namespace App\Controller;

use App\Entity\Diver;
use App\Service\Presenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/divers')]
class DiverController extends ApiController
{
    #[Route('', methods: 'GET')]
    public function list(EntityManagerInterface $em): JsonResponse
    {
        return $this->json(array_map(Presenter::diver(...), $em->getRepository(Diver::class)->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC'])));
    }

    #[Route('', methods: 'POST')]
    public function create(Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $this->required($d, 'firstName', 'lastName');
        $diver = (new Diver())->setFirstName($d['firstName'])->setLastName($d['lastName'])->setLevel($d['level'] ?? null);
        $em->persist($diver);
        $em->flush();
        return $this->json(Presenter::diver($diver), 201);
    }
}
