<?php
namespace App\Controller;

use App\Entity\{Lane, Pool, Reservation};
use App\Service\Presenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Bassins et lignes d'eau. Lecture : tous les encadrants. Modification : référents. */
class PoolController extends ApiController
{
    #[Route('/api/pools', methods: 'GET')]
    public function list(EntityManagerInterface $em): JsonResponse
    {
        return $this->json(array_map(Presenter::pool(...), $em->getRepository(Pool::class)->findBy([], ['name' => 'ASC'])));
    }

    /** Crée un bassin avec ses lignes d'eau : {"name":"Fosse","lanes":[{"name":"Ligne 1","maxDepth":20}]} */
    #[Route('/api/pools', methods: 'POST'), IsGranted('ROLE_REFERENT')]
    public function create(Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $pool = (new Pool())->setName($this->name($d['name'] ?? ''));
        foreach ($d['lanes'] ?? [] as $l) {
            $pool->addLane((new Lane())->setName($this->name($l['name'] ?? 'Ligne'))->setMaxDepth($this->depth($l['maxDepth'] ?? null)));
        }
        $em->persist($pool);
        $em->flush();
        return $this->json(Presenter::pool($pool), 201);
    }

    /** Renomme un bassin : {"name":"Fosse 20 m"} */
    #[Route('/api/pools/{id}', methods: 'PUT', requirements: ['id' => '\d+']), IsGranted('ROLE_REFERENT')]
    public function update(Pool $pool, Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        if (array_key_exists('name', $d)) $pool->setName($this->name($d['name']));
        $em->flush();
        return $this->json(Presenter::pool($pool));
    }

    /** Supprime un bassin et ses lignes (refusé s'il reste des réservations à venir). */
    #[Route('/api/pools/{id}', methods: 'DELETE', requirements: ['id' => '\d+']), IsGranted('ROLE_REFERENT')]
    public function delete(Pool $pool, EntityManagerInterface $em): JsonResponse
    {
        $upcoming = $this->upcoming($em, $pool->getLanes()->getValues());
        if ($upcoming) throw new ConflictHttpException(sprintf('Impossible : %d réservation(s) à venir dans « %s ». Supprimez ou déplacez d\'abord ces séances.', $upcoming, $pool->getName()));
        $em->remove($pool);
        $em->flush();
        return $this->json(null, 204);
    }

    #[Route('/api/pools/{id}/lanes', methods: 'POST', requirements: ['id' => '\d+']), IsGranted('ROLE_REFERENT')]
    public function addLane(Pool $pool, Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $lane = (new Lane())->setName($this->name($d['name'] ?? ''))->setMaxDepth($this->depth($d['maxDepth'] ?? null));
        $pool->addLane($lane);
        $em->flush();
        return $this->json(Presenter::lane($lane), 201);
    }

    /** Ordre des lignes du bassin : {"laneIds":[3,1,2]} (toutes les lignes du bassin). */
    #[Route('/api/pools/{id}/lanes/order', methods: 'PUT', requirements: ['id' => '\d+']), IsGranted('ROLE_REFERENT')]
    public function order(Pool $pool, Request $req, EntityManagerInterface $em): JsonResponse
    {
        $ids = $this->body($req)['laneIds'] ?? null;
        $current = array_map(fn (Lane $l) => $l->getId(), $pool->getLanes()->getValues());
        if (!is_array($ids) || count($ids) !== count($current) || array_diff($current, array_map('intval', $ids))) {
            throw new BadRequestHttpException('laneIds doit contenir toutes les lignes du bassin, dans le nouvel ordre');
        }
        foreach ($pool->getLanes() as $lane) $lane->setPosition((int) array_search($lane->getId(), array_map('intval', $ids), true));
        $em->flush();
        $em->refresh($pool);
        return $this->json(Presenter::pool($pool));
    }

    /** Modifie une ligne : {"name":"Ligne 1 (lente)","maxDepth":4.5} */
    #[Route('/api/lanes/{id}', methods: 'PUT', requirements: ['id' => '\d+']), IsGranted('ROLE_REFERENT')]
    public function updateLane(Lane $lane, Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        if (array_key_exists('name', $d)) $lane->setName($this->name($d['name']));
        if (array_key_exists('maxDepth', $d)) $lane->setMaxDepth($this->depth($d['maxDepth']));
        $em->flush();
        return $this->json(Presenter::lane($lane));
    }

    /** Supprime une ligne (refusé s'il reste des réservations à venir dessus). */
    #[Route('/api/lanes/{id}', methods: 'DELETE', requirements: ['id' => '\d+']), IsGranted('ROLE_REFERENT')]
    public function deleteLane(Lane $lane, EntityManagerInterface $em): JsonResponse
    {
        $upcoming = $this->upcoming($em, [$lane]);
        if ($upcoming) throw new ConflictHttpException(sprintf('Impossible : %d réservation(s) à venir sur %s. Supprimez ou déplacez d\'abord ces séances.', $upcoming, $lane->getName()));
        $lane->getPool()->removeLane($lane);
        $em->flush();
        return $this->json(null, 204);
    }

    private function name(mixed $v): string
    {
        $v = trim((string) $v);
        if ($v === '' || mb_strlen($v) > 60) throw new BadRequestHttpException('Nom requis (60 caractères maximum)');
        return $v;
    }

    private function depth(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v) || $v < 0 || $v > 100) throw new BadRequestHttpException('Profondeur invalide (en mètres, entre 0 et 100)');
        return (float) $v;
    }

    /** Nombre de réservations (non refusées) à venir sur ces lignes. @param Lane[] $lanes */
    private function upcoming(EntityManagerInterface $em, array $lanes): int
    {
        if (!$lanes) return 0;
        return (int) $em->createQueryBuilder()->select('COUNT(r.id)')->from(Reservation::class, 'r')
            ->where('r.lane IN (:lanes)')->andWhere('r.endAt > :now')->andWhere('r.status != :rej')
            ->setParameter('lanes', $lanes)->setParameter('now', new \DateTimeImmutable())->setParameter('rej', Reservation::REJECTED)
            ->getQuery()->getSingleScalarResult();
    }
}
