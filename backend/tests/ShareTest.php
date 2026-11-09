<?php
namespace App\Tests;

/** Export PDF du planning et liens de partage publics. */
class ShareTest extends ApiTestCase
{
    private int $slot;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->call('POST', '/api/pools', ['name' => 'Fosse', 'lanes' => [['name' => 'L1'], ['name' => 'L2']]], $this->ref)[1];
        $n1 = $this->call('POST', '/api/sections', ['name' => 'Niveau 1'], $this->inst)[1]['id'];
        $this->slot = $this->call('POST', '/api/slots', ['label' => 'Entraînement', 'weekdays' => [1], 'startTime' => '20:00', 'endTime' => '22:00'], $this->ref)[1]['id'];
        $this->call('PUT', "/api/slots/{$this->slot}/allocations", ['allocations' => [['laneId' => $pool['lanes'][0]['id'], 'sectionIds' => [$n1]]]], $this->ref);
    }

    private function raw(string $uri, ?string $token = null)
    {
        $this->client->request('GET', $uri, [], [], $token ? ['HTTP_AUTHORIZATION' => "Bearer $token"] : []);
        return $this->client->getResponse();
    }

    public function testPdfExport(): void
    {
        $q = '/api/planning/pdf?from=2027-02-01T00:00:00%2B01:00&to=2027-03-01T00:00:00%2B01:00&title=F%C3%A9vrier%202027';
        $this->assertSame(401, $this->raw($q)->getStatusCode(), 'connexion requise');
        $r = $this->raw($q, $this->inst);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $r->getContent());
        $this->assertSame('attachment; filename="planning-2027-02-01.pdf"', $r->headers->get('Content-Disposition'));
        $this->assertSame(400, $this->raw('/api/planning/pdf?from=2027-01-01T00:00:00Z&to=2027-06-01T00:00:00Z', $this->inst)->getStatusCode(), '62 jours max');

        // contenu (HTML avant conversion)
        $pdf = static::getContainer()->get(\App\Service\PlanningPdf::class);
        $planning = static::getContainer()->get(\App\Service\PlanningService::class)
            ->build(new \DateTimeImmutable('2027-02-01T00:00:00+01:00'), new \DateTimeImmutable('2027-02-09T00:00:00+01:00'), null, false);
        $html = $pdf->html($planning, 'Février 2027');
        foreach (['Février 2027', 'Lun. 1 févr.', 'Lun. 8 févr.', '20:00–22:00', 'Niveau 1', 'libre', 'Fosse', 'Semaine du 1 au 7 févr.', 'Semaine du 8 au 14 févr.'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
    }

    public function testShareLinks(): void
    {
        $this->assertSame(403, $this->call('POST', '/api/share-links', ['label' => 'x'], $this->inst)[0], 'référents seulement');
        [$code, $link] = $this->call('POST', '/api/share-links', ['label' => 'Piscine municipale'], $this->ref);
        $this->assertSame(201, $code);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $link['token']);
        $this->assertSame('ref@x.fr', $link['createdBy']);

        // accès sans compte, en lecture seule, sans bouteilles ni « mine »
        $this->assertSame(['label' => 'Piscine municipale', 'expiresAt' => null], $this->call('GET', "/api/public/{$link['token']}")[1]);
        [$code, $p] = $this->call('GET', "/api/public/{$link['token']}/planning?from=2027-02-08T00:00:00%2B01:00&to=2027-02-09T00:00:00%2B01:00");
        $this->assertSame(200, $code);
        $this->assertSame('Niveau 1', $p['occurrences'][0]['lanes'][0]['sections'][0]['name']);
        $this->assertArrayNotHasKey('tanks', $p['occurrences'][0]);
        $this->assertFalse($p['occurrences'][0]['lanes'][0]['sections'][0]['mine']);
        $this->assertSame('%PDF-', substr($this->raw("/api/public/{$link['token']}/pdf?from=2027-02-01T00:00:00Z&to=2027-03-01T00:00:00Z")->getContent(), 0, 5));
        // le lien ne donne accès à rien d'autre
        $this->assertSame(401, $this->call('GET', '/api/sections')[0]);
        $this->assertSame(401, $this->call('GET', "/api/presences?slotId={$this->slot}&date=2027-02-08&sectionId=1")[0]);

        // jeton inconnu, lien expiré, lien révoqué
        $this->assertSame(404, $this->call('GET', '/api/public/inconnu')[0]);
        [, $old] = $this->call('POST', '/api/share-links', ['expiresAt' => '2020-01-01'], $this->ref);
        $this->assertSame(404, $this->call('GET', "/api/public/{$old['token']}/planning")[0]);
        $this->assertSame(400, $this->call('POST', '/api/share-links', ['expiresAt' => 'demain'], $this->ref)[0]);
        [, $list] = $this->call('GET', '/api/share-links', null, $this->ref);
        $this->assertCount(2, $list);
        $this->assertSame(204, $this->call('DELETE', "/api/share-links/{$link['id']}", null, $this->ref)[0]);
        $this->assertSame(404, $this->call('GET', "/api/public/{$link['token']}")[0]);
    }
}
