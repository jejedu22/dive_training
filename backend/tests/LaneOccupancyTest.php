<?php
namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Lignes ouvertes par créneau, plan d'occupation (ligne → section), vue d'occupation. Lundi 7/12/2026, créneau 20h–22h Paris. */
class LaneOccupancyTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $inst;   // encadrant de N1
    private string $inst2;  // encadrant de N2
    private string $ref;
    private array $lanes;   // Fosse L1..L4 puis Grand bassin L1
    private int $n1;
    private int $n2;
    private int $slot;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ([['inst@x.fr', []], ['inst2@x.fr', []], ['ref@x.fr', ['ROLE_REFERENT']]] as [$email, $roles]) {
            $u = (new User())->setEmail($email)->setName($email)->setRoles($roles);
            $u->setPassword($hasher->hashPassword($u, 'pw'));
            $em->persist($u);
        }
        $em->flush();
        $login = fn ($e) => $this->call('POST', '/api/login_check', ['email' => $e, 'password' => 'pw'])[1]['token'];
        [$this->inst, $this->inst2, $this->ref] = [$login('inst@x.fr'), $login('inst2@x.fr'), $login('ref@x.fr')];

        $fosse = $this->call('POST', '/api/pools', ['name' => 'Fosse', 'lanes' => [['name' => 'L1'], ['name' => 'L2'], ['name' => 'L3'], ['name' => 'L4']]], $this->ref)[1];
        $grand = $this->call('POST', '/api/pools', ['name' => 'Grand bassin', 'lanes' => [['name' => 'L1']]], $this->ref)[1];
        $this->lanes = array_merge(array_column($fosse['lanes'], 'id'), array_column($grand['lanes'], 'id'));
        $this->n1 = $this->call('POST', '/api/sections', ['name' => 'N1'], $this->inst)[1]['id'];   // inst devient encadrant de N1
        $this->n2 = $this->call('POST', '/api/sections', ['name' => 'N2'], $this->inst2)[1]['id'];

        // Lundi 20h–22h : seulement les lignes 1 à 3 de la fosse sont ouvertes au club
        [$code, $slot] = $this->call('POST', '/api/slots', ['label' => 'Club', 'weekdays' => [1], 'startTime' => '20:00', 'endTime' => '22:00',
            'laneIds' => [$this->lanes[0], $this->lanes[1], $this->lanes[2]]], $this->ref);
        $this->assertSame(201, $code);
        $this->slot = $slot['id'];
    }

    private function call(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token) $server['HTTP_AUTHORIZATION'] = "Bearer $token";
        $this->client->request($method, $uri, [], [], $server, $body !== null ? json_encode($body) : null);
        return [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent() ?: 'null', true)];
    }

    private function book(int $section, int $lane, string $token, bool $force = false): array
    {
        return $this->call('POST', '/api/sessions', ['sectionId' => $section, 'title' => 'S', 'start' => '2026-12-07T19:00:00Z',
            'end' => '2026-12-07T21:00:00Z', 'laneIds' => [$lane], 'force' => $force], $token);
    }

    private function allocate(array $map): array
    {
        return $this->call('PUT', "/api/slots/{$this->slot}/allocations", ['allocations' => array_map(fn ($l, $s) => ['laneId' => $l, 'sectionId' => $s], array_keys($map), $map)], $this->ref);
    }

    public function testOnlyOpenLanesCanBeBooked(): void
    {
        [, $slots] = $this->call('GET', '/api/slots', null, $this->inst);
        $this->assertSame(array_slice($this->lanes, 0, 3), $slots[0]['availableLaneIds']);

        [$code, $body] = $this->book($this->n1, $this->lanes[3], $this->inst);  // fosse L4 fermée
        $this->assertSame(422, $code);
        $this->assertStringContainsString("L4 (Fosse) n'est pas ouverte", $body['message']);
        $this->assertSame(422, $this->book($this->n1, $this->lanes[4], $this->inst)[0]); // grand bassin fermé
        $this->assertSame(201, $this->book($this->n1, $this->lanes[0], $this->inst)[0]);
        $this->assertSame(201, $this->book($this->n1, $this->lanes[3], $this->ref, true)[0], 'le référent peut forcer');
    }

    public function testAllocationPlan(): void
    {
        $this->assertSame(403, $this->call('PUT', "/api/slots/{$this->slot}/allocations", ['allocations' => []], $this->inst)[0]);
        $this->assertSame(400, $this->allocate([$this->lanes[3] => $this->n1])[0], 'ligne fermée : pas d\'attribution');

        [$code, $slot] = $this->allocate([$this->lanes[0] => $this->n1, $this->lanes[1] => $this->n1, $this->lanes[2] => $this->n2]);
        $this->assertSame(200, $code);
        $this->assertCount(3, $slot['allocations']);

        // ligne attribuée à sa section : confirmée directement, sans validation
        [$code, $s] = $this->book($this->n1, $this->lanes[0], $this->inst);
        $this->assertSame(201, $code);
        $this->assertSame('CONFIRMED', $s['reservations'][0]['status']);
        $this->assertTrue($s['reservations'][0]['planned']);

        // ligne attribuée à une autre section : refusée (sauf référent)
        [$code, $body] = $this->book($this->n2, $this->lanes[1], $this->inst2);
        $this->assertSame(409, $code);
        $this->assertStringContainsString('attribuée à « N1 »', $body['message']);
        $this->assertSame(201, $this->book($this->n2, $this->lanes[1], $this->ref, true)[0]);

        // modifier le plan : retirer L1 (sectionId null), réattribuer L3
        [, $slot] = $this->allocate([$this->lanes[0] => null, $this->lanes[1] => $this->n1, $this->lanes[2] => $this->n1]);
        $this->assertSame([[$this->lanes[1], $this->n1], [$this->lanes[2], $this->n1]], array_map(fn ($a) => [$a['laneId'], $a['sectionId']], $slot['allocations']));

        // fermer L3 sur le créneau retire son attribution
        [, $slot] = $this->call('PUT', "/api/slots/{$this->slot}", ['laneIds' => [$this->lanes[0], $this->lanes[1]]], $this->ref);
        $this->assertSame([$this->lanes[1]], array_column($slot['allocations'], 'laneId'));
    }

    public function testOccupancyShowsWhatEachInstructorHasAccessTo(): void
    {
        $this->allocate([$this->lanes[0] => $this->n1, $this->lanes[2] => $this->n2]);
        $this->book($this->n1, $this->lanes[0], $this->inst);       // confirmée (planifiée)
        $this->book($this->n1, $this->lanes[1], $this->inst);       // ligne libre : en attente

        [$code, $occ] = $this->call('GET', '/api/occupancy?from=2026-12-07T00:00:00%2B01:00&to=2026-12-14T00:00:00%2B01:00', null, $this->inst);
        $this->assertSame(200, $code);
        $this->assertSame([$this->n1], $occ['mySectionIds']);
        $this->assertCount(1, $occ['occurrences']);
        $lanes = $occ['occurrences'][0]['lanes'];
        $this->assertSame(['L1', 'L2', 'L3'], array_column($lanes, 'lane'), 'seulement les lignes ouvertes');

        $this->assertSame(['sectionId' => $this->n1, 'section' => 'N1', 'mine' => true], $lanes[0]['allocation']);
        $this->assertSame('CONFIRMED', $lanes[0]['reservations'][0]['status']);
        $this->assertNull($lanes[1]['allocation']);
        $this->assertSame('PENDING', $lanes[1]['reservations'][0]['status']);
        $this->assertFalse($lanes[2]['allocation']['mine'], 'N2 n\'est pas une section de inst');

        // vue de l'encadrant de N2
        [, $occ2] = $this->call('GET', '/api/occupancy?from=2026-12-07T00:00:00%2B01:00&to=2026-12-14T00:00:00%2B01:00', null, $this->inst2);
        $this->assertTrue($occ2['occurrences'][0]['lanes'][2]['allocation']['mine']);
    }

    public function testSectionInstructors(): void
    {
        [$code, $list] = $this->call('GET', '/api/instructors', null, $this->inst);
        $this->assertSame(200, $code);
        $inst2Id = array_values(array_filter($list, fn ($u) => $u['name'] === 'inst2@x.fr'))[0]['id'];
        [, $s] = $this->call('POST', "/api/sections/{$this->n1}/instructors", ['userId' => $inst2Id], $this->inst);
        $this->assertSame(['inst@x.fr', 'inst2@x.fr'], array_column($s['instructors'], 'name'));
        [, $occ] = $this->call('GET', '/api/occupancy?from=2026-12-07T00:00:00Z&to=2026-12-08T00:00:00Z', null, $this->inst2);
        $this->assertEqualsCanonicalizing([$this->n1, $this->n2], $occ['mySectionIds']);
        [, $s] = $this->call('DELETE', "/api/sections/{$this->n1}/instructors/$inst2Id", null, $this->inst);
        $this->assertCount(1, $s['instructors']);
    }
}
