<?php
namespace App\Controller;

use App\Entity\{ClubSlot, Diver, Presence, Section, User};
use App\Service\Presenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException, BadRequestHttpException};
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bouteilles par date et par section : les élèves de la section, et pour chacun s'il fait bouteille
 * sur ce créneau ce jour-là. Modifiable par les encadrants de la section et les référents.
 */
class PresenceController extends ApiController
{
    /** ?slotId=1&date=2026-12-07&sectionId=3 */
    #[Route('/api/presences', methods: 'GET')]
    public function list(Request $req, EntityManagerInterface $em): JsonResponse
    {
        [$slot, $date, $section] = $this->target($req->query->all(), $em);
        return $this->json($this->present($em, $slot, $date, $section));
    }

    /** {"slotId":1,"date":"2026-12-07","sectionId":3,"diverId":7,"usesTank":true} */
    #[Route('/api/presences', methods: 'PUT')]
    public function update(Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        [$slot, $date, $section] = $this->target($d, $em);
        /** @var User $me */
        $me = $this->getUser();
        if (!$me->isReferent() && !$section->getInstructors()->contains($me)) {
            throw new AccessDeniedHttpException('Réservé aux encadrants de la section et aux référents');
        }
        if (!$section->usesTanks()) throw new BadRequestHttpException('Cette section n\'utilise pas de bouteilles');
        $this->required($d, 'diverId');
        $diver = $em->find(Diver::class, $d['diverId']) ?? throw $this->createNotFoundException('Élève introuvable');
        if (!$section->getDivers()->contains($diver)) throw new BadRequestHttpException('Cet élève n\'est pas dans la section');
        $p = $em->getRepository(Presence::class)->findOneBy(['slot' => $slot, 'date' => $date, 'section' => $section, 'diver' => $diver]);
        if (!$p) {
            $p = (new Presence())->setSlot($slot)->setDate($date)->setSection($section)->setDiver($diver);
            $em->persist($p);
        }
        $p->setUsesTank((bool) ($d['usesTank'] ?? false));
        $em->flush();
        return $this->json($this->present($em, $slot, $date, $section));
    }

    /** @return array{0: ClubSlot, 1: \DateTimeImmutable, 2: Section} */
    private function target(array $d, EntityManagerInterface $em): array
    {
        $this->required($d, 'slotId', 'date', 'sectionId');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['date'])) throw new BadRequestHttpException('Date invalide (AAAA-MM-JJ)');
        return [
            $em->find(ClubSlot::class, $d['slotId']) ?? throw $this->createNotFoundException('Créneau introuvable'),
            new \DateTimeImmutable($d['date']),
            $em->find(Section::class, $d['sectionId']) ?? throw $this->createNotFoundException('Section introuvable'),
        ];
    }

    private function present(EntityManagerInterface $em, ClubSlot $slot, \DateTimeImmutable $date, Section $section): array
    {
        $tanks = [];
        foreach ($em->getRepository(Presence::class)->findBy(['slot' => $slot, 'date' => $date, 'section' => $section]) as $p) {
            $tanks[$p->getDiver()->getId()] = $p->usesTank();
        }
        $divers = $section->getDivers()->getValues();
        usort($divers, fn (Diver $a, Diver $b) => [$a->getLastName(), $a->getFirstName()] <=> [$b->getLastName(), $b->getFirstName()]);
        $list = array_map(fn (Diver $d) => ['diver' => Presenter::diver($d), 'usesTank' => $tanks[$d->getId()] ?? false], $divers);
        return ['section' => ['id' => $section->getId(), 'name' => $section->getName(), 'color' => $section->getColor()],
            'date' => $date->format('Y-m-d'), 'slotId' => $slot->getId(), 'divers' => $list,
            'tankCount' => count(array_filter($list, fn ($x) => $x['usesTank']))];
    }
}
