<?php
namespace App\Controller;

use App\Entity\{Lane, Pool};
use App\Service\Presenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/pools')]
class PoolController extends ApiController
{
    #[Route('', methods: 'GET')]
    public function list(EntityManagerInterface $em): JsonResponse
    {
        return $this->json(array_map(Presenter::pool(...), $em->getRepository(Pool::class)->findBy([], ['name' => 'ASC'])));
    }

    /** Crée un bassin avec ses lignes d'eau : {"name":"Fosse","lanes":[{"name":"Ligne 1","maxDepth":20}]} */
    #[Route('', methods: 'POST'), IsGranted('ROLE_REFERENT')]
    public function create(Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $this->required($d, 'name');
        $pool = (new Pool())->setName($d['name']);
        foreach ($d['lanes'] ?? [] as $l) {
            $pool->addLane((new Lane())->setName($l['name'] ?? 'Ligne')->setMaxDepth($l['maxDepth'] ?? null));
        }
        $em->persist($pool);
        $em->flush();
        return $this->json(Presenter::pool($pool), 201);
    }

    #[Route('/{id}/lanes', methods: 'POST'), IsGranted('ROLE_REFERENT')]
    public function addLane(Pool $pool, Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $this->required($d, 'name');
        $lane = (new Lane())->setName($d['name'])->setMaxDepth($d['maxDepth'] ?? null);
        $pool->addLane($lane);
        $em->flush();
        return $this->json(Presenter::lane($lane), 201);
    }
}
