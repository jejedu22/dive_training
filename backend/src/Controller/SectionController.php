<?php
namespace App\Controller;

use App\Entity\{Diver, Section, User};
use App\Service\Presenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/sections')]
class SectionController extends ApiController
{
    #[Route('', methods: 'GET')]
    public function list(EntityManagerInterface $em): JsonResponse
    {
        return $this->json(array_map(Presenter::section(...), $em->getRepository(Section::class)->findBy([], ['name' => 'ASC'])));
    }

    #[Route('/{id}', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(Section $s): JsonResponse { return $this->json(Presenter::section($s)); }

    #[Route('', methods: 'POST')]
    public function create(Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $this->required($d, 'name');
        $s = (new Section())->setName($d['name'])->setTargetLevel($d['targetLevel'] ?? null)->setSeason($d['season'] ?? null);
        $s->addInstructor($this->getUser());
        $em->persist($s);
        $em->flush();
        return $this->json(Presenter::section($s), 201);
    }

    #[Route('/{id}', methods: 'PUT')]
    public function update(Section $s, Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        isset($d['name']) && $s->setName($d['name']);
        array_key_exists('targetLevel', $d) && $s->setTargetLevel($d['targetLevel']);
        array_key_exists('season', $d) && $s->setSeason($d['season']);
        $em->flush();
        return $this->json(Presenter::section($s));
    }

    #[Route('/{id}/divers', methods: 'POST')]
    public function addDiver(Section $s, Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $this->required($d, 'diverId');
        $diver = $em->find(Diver::class, $d['diverId']) ?? throw $this->createNotFoundException('Élève introuvable');
        $s->addDiver($diver);
        $em->flush();
        return $this->json(Presenter::section($s));
    }

    /** Associe un encadrant à la section : {"userId":3} */
    #[Route('/{id}/instructors', methods: 'POST')]
    public function addInstructor(Section $s, Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $this->required($d, 'userId');
        $s->addInstructor($em->find(User::class, $d['userId']) ?? throw $this->createNotFoundException('Encadrant introuvable'));
        $em->flush();
        return $this->json(Presenter::section($s));
    }

    #[Route('/{id}/instructors/{userId}', methods: 'DELETE')]
    public function removeInstructor(Section $s, int $userId, EntityManagerInterface $em): JsonResponse
    {
        $u = $em->find(User::class, $userId) ?? throw $this->createNotFoundException();
        $s->getInstructors()->removeElement($u);
        $em->flush();
        return $this->json(Presenter::section($s));
    }

    #[Route('/{id}/divers/{diverId}', methods: 'DELETE')]
    public function removeDiver(Section $s, int $diverId, EntityManagerInterface $em): JsonResponse
    {
        $diver = $em->find(Diver::class, $diverId) ?? throw $this->createNotFoundException();
        $s->removeDiver($diver);
        $em->flush();
        return $this->json(Presenter::section($s));
    }
}
