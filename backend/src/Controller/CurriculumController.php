<?php
namespace App\Controller;

use App\Training\{Mft, Referential};
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Modification des cursus (référentiel) : réservée aux administrateurs. */
#[Route('/api/curricula'), IsGranted('ROLE_ADMIN')]
class CurriculumController extends ApiController
{
    public function __construct(private Referential $referential) {}

    #[Route('', methods: 'GET')]
    public function list(): JsonResponse
    {
        return $this->json(array_values(array_map(fn (array $c) => $this->meta($c) + ['enrolments' => $this->referential->usage($c['code'])['enrolments']], Mft::curricula())));
    }

    #[Route('/{code}', methods: 'GET')]
    public function show(string $code): JsonResponse
    {
        $c = Mft::curriculum($code) ?? throw $this->createNotFoundException('Cursus inconnu');
        return $this->json(['definition' => $c, 'usage' => $this->referential->usage($code)] + $this->meta($c));
    }

    #[Route('/{code}', methods: 'PUT')]
    public function update(string $code, Request $req): JsonResponse
    {
        if (!Mft::curriculum($code)) throw $this->createNotFoundException('Cursus inconnu');
        $this->referential->save($code, $this->body($req), $this->getUser()->getName());
        return $this->show($code);
    }

    /** Nouveau cursus du club, copié d'un cursus existant : {"code":"N4","name":"Niveau 4","copyFrom":"N3"} */
    #[Route('', methods: 'POST')]
    public function create(Request $req): JsonResponse
    {
        $d = $this->body($req);
        $data = $this->referential->create((string) ($d['code'] ?? ''), (string) ($d['copyFrom'] ?? ''), (string) ($d['name'] ?? ''), $this->getUser()->getName());
        $resp = $this->show($data['code']);
        $resp->setStatusCode(201);
        return $resp;
    }

    /** Cursus du MFT : revient aux valeurs du manuel. Cursus du club : supprimé (sans stagiaire). */
    #[Route('/{code}', methods: 'DELETE')]
    public function reset(string $code): JsonResponse
    {
        if (!Mft::curriculum($code)) throw $this->createNotFoundException('Cursus inconnu');
        $this->referential->reset($code);
        return Mft::curriculum($code) ? $this->show($code) : $this->json(null, 204);
    }

    private function meta(array $c): array
    {
        $def = $this->referential->definition($c['code']);
        return [
            'code' => $c['code'], 'name' => $c['name'], 'type' => $c['type'] ?? 'diver', 'version' => $c['version'],
            'origin' => $this->referential->isDefault($c['code']) ? ($def ? 'modified' : 'mft') : 'club',
            'updatedAt' => $def?->getUpdatedAt()->format(\DATE_ATOM), 'updatedBy' => $def?->getUpdatedBy(),
        ];
    }
}
