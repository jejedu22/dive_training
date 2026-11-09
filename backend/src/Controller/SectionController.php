<?php
namespace App\Controller;

use App\Entity\{Diver, Section, User};
use App\Service\Presenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
        $s = (new Section())->setName($this->text($d['name'] ?? '', 120, 'Nom', true))
            ->setTargetLevel($this->text($d['targetLevel'] ?? null, 40, 'Niveau visé'))->setSeason($this->text($d['season'] ?? null, 20, 'Saison'))
            ->setColor(isset($d['color']) ? $this->color($d['color']) : $this->nextColor($em));
        $s->addInstructor($this->getUser());
        $em->persist($s);
        $em->flush();
        return $this->json(Presenter::section($s), 201);
    }

    #[Route('/{id}', methods: 'PUT')]
    public function update(Section $s, Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        array_key_exists('name', $d) && $s->setName($this->text($d['name'], 120, 'Nom', true));
        array_key_exists('targetLevel', $d) && $s->setTargetLevel($this->text($d['targetLevel'], 40, 'Niveau visé'));
        array_key_exists('season', $d) && $s->setSeason($this->text($d['season'], 20, 'Saison'));
        isset($d['color']) && $s->setColor($this->color($d['color']));
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

    /** Texte nettoyé ; vide = null (ou refusé si obligatoire). */
    private function text(mixed $v, int $max, string $label, bool $required = false): ?string
    {
        $v = trim((string) ($v ?? ''));
        if ($v === '') {
            if ($required) throw new BadRequestHttpException("$label requis");
            return null;
        }
        if (mb_strlen($v) > $max) throw new BadRequestHttpException("$label : $max caractères maximum");
        return $v;
    }

    private function color(mixed $c): string
    {
        if (!is_string($c) || !preg_match('/^#[0-9a-fA-F]{6}$/', $c)) throw new BadRequestHttpException('Couleur invalide (format #rrggbb)');
        return $c;
    }

    /** Première couleur de la palette la moins utilisée par les sections existantes. */
    private function nextColor(EntityManagerInterface $em): string
    {
        $used = array_count_values(array_map(fn (Section $x) => $x->getColor(), $em->getRepository(Section::class)->findAll()));
        $best = Section::PALETTE[0];
        foreach (Section::PALETTE as $c) if (($used[$c] ?? 0) < ($used[$best] ?? 0)) $best = $c;
        return $best;
    }
}
