<?php
namespace App\Controller;

use App\Service\{PlanningPdf, PlanningService};
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le planning d'occupation : pour chaque créneau du club sur la période, chaque ligne ouverte
 * et les sections qui l'occupent ce jour-là, avec le nombre de bouteilles par section.
 */
class PlanningController extends ApiController
{
    #[Route('/api/planning', methods: 'GET')]
    public function planning(Request $req, PlanningService $planning): JsonResponse
    {
        [$from, $to] = $this->period($req);
        return $this->json($planning->build($from, $to, $this->getUser()));
    }

    /** Export PDF de la période : ?from=…&to=…&title=… */
    #[Route('/api/planning/pdf', methods: 'GET')]
    public function pdf(Request $req, PlanningService $planning, PlanningPdf $pdf): Response
    {
        [$from, $to] = $this->period($req);
        return $pdf->response($planning->build($from, $to, null, false), (string) $req->query->get('title', 'Planning des lignes d\'eau'), $from);
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function period(Request $req): array
    {
        $from = $this->date($req->query->get('from', 'today'), 'from');
        $to = $req->query->get('to') ? $this->date($req->query->get('to'), 'to') : $from->modify('+7 days');
        return [$from, $to];
    }
}
