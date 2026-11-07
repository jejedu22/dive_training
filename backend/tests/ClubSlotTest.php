<?php
namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Créneaux du club (fuseau Europe/Paris) : lundi 7/12/2026, mardi 8/12, mercredi 9/12 ; lundi 7/6/2027 (heure d'été). */
class ClubSlotTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $inst;
    private string $ref;
    private int $section;
    private array $poolA;
    private array $poolB;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ([['inst@x.fr', []], ['ref@x.fr', ['ROLE_REFERENT']]] as [$email, $roles]) {
            $u = (new User())->setEmail($email)->setName($email)->setRoles($roles);
            $u->setPassword($hasher->hashPassword($u, 'pw'));
            $em->persist($u);
        }
        $em->flush();
        $this->inst = $this->call('POST', '/api/login_check', ['email' => 'inst@x.fr', 'password' => 'pw'])[1]['token'];
        $this->ref = $this->call('POST', '/api/login_check', ['email' => 'ref@x.fr', 'password' => 'pw'])[1]['token'];
        $this->poolA = $this->call('POST', '/api/pools', ['name' => 'Fosse', 'lanes' => [['name' => 'L1']]], $this->ref)[1];
        $this->poolB = $this->call('POST', '/api/pools', ['name' => 'Grand bassin', 'lanes' => [['name' => 'L1']]], $this->ref)[1];
        $this->section = $this->call('POST', '/api/sections', ['name' => 'N1'], $this->inst)[1]['id'];
    }

    private function call(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token) $server['HTTP_AUTHORIZATION'] = "Bearer $token";
        $this->client->request($method, $uri, [], [], $server, $body !== null ? json_encode($body) : null);
        return [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent() ?: 'null', true)];
    }

    private function session(string $start, string $end, ?array $pool = null, string $token = null, bool $force = false): int
    {
        $pool ??= $this->poolA;
        return $this->call('POST', '/api/sessions', [
            'sectionId' => $this->section, 'title' => 'S', 'start' => $start, 'end' => $end,
            'laneIds' => [$pool['lanes'][0]['id']], 'force' => $force,
        ], $token ?? $this->inst)[0];
    }

    public function testOnlyReferentsManageSlotsAndInputIsValidated(): void
    {
        $slot = ['label' => 'Entraînement', 'weekdays' => [1, 3], 'startTime' => '20:00', 'endTime' => '22:00'];
        $this->assertSame(403, $this->call('POST', '/api/slots', $slot, $this->inst)[0]);
        $this->assertSame(400, $this->call('POST', '/api/slots', ['weekdays' => []] + $slot, $this->ref)[0]);
        $this->assertSame(400, $this->call('POST', '/api/slots', ['weekdays' => [8]] + $slot, $this->ref)[0]);
        $this->assertSame(400, $this->call('POST', '/api/slots', ['endTime' => '19:00'] + $slot, $this->ref)[0]);
        $this->assertSame(400, $this->call('POST', '/api/slots', ['startTime' => '25:00'] + $slot, $this->ref)[0]);

        [$code, $created] = $this->call('POST', '/api/slots', ['weekdays' => [3, 1, 1]] + $slot, $this->ref);
        $this->assertSame(201, $code);
        $this->assertSame([1, 3], $created['weekdays']);
        $this->assertSame('Lundi, mercredi 20:00–22:00', $created['description']);

        [$code, $list] = $this->call('GET', '/api/slots', null, $this->inst); // lecture pour tous
        $this->assertSame(200, $code);
        $this->assertCount(1, $list);

        [$code, $upd] = $this->call('PUT', "/api/slots/{$created['id']}", ['endTime' => '22:30'], $this->ref);
        $this->assertSame([200, '22:30'], [$code, $upd['endTime']]);
        $this->assertSame(204, $this->call('DELETE', "/api/slots/{$created['id']}", null, $this->ref)[0]);
    }

    public function testOccurrencesAreInClubTimezone(): void
    {
        $this->call('POST', '/api/slots', ['weekdays' => [1, 3], 'startTime' => '20:00', 'endTime' => '22:00'], $this->ref);
        [$code, $occ] = $this->call('GET', '/api/slots/occurrences?from=2026-12-07T00:00:00%2B01:00&to=2026-12-14T00:00:00%2B01:00', null, $this->inst);
        $this->assertSame(200, $code);
        $this->assertSame(['2026-12-07T20:00:00+01:00', '2026-12-09T20:00:00+01:00'], array_column($occ, 'start'));
        // été : toujours 20h locales (UTC+2)
        [, $occ] = $this->call('GET', '/api/slots/occurrences?from=2027-06-07T00:00:00Z&to=2027-06-08T00:00:00Z', null, $this->inst);
        $this->assertSame('2027-06-07T20:00:00+02:00', $occ[0]['start']);
    }

    public function testSessionsMustFitInASlot(): void
    {
        // sans créneau défini : aucune contrainte
        $this->assertSame(201, $this->session('2026-12-08T10:00:00Z', '2026-12-08T11:00:00Z'));

        $this->call('POST', '/api/slots', ['weekdays' => [1, 3], 'startTime' => '20:00', 'endTime' => '22:00'], $this->ref);
        $this->assertSame(201, $this->session('2026-12-07T19:00:00Z', '2026-12-07T20:30:00Z'), 'lundi 20h–21h30 Paris');
        $this->assertSame(201, $this->session('2026-12-09T20:00:00+01:00', '2026-12-09T22:00:00+01:00'), 'mercredi créneau complet');
        $this->assertSame(201, $this->session('2027-06-07T18:00:00Z', '2027-06-07T20:00:00Z'), 'été : 20h–22h Paris = 18h–20h UTC');
        $this->assertSame(422, $this->session('2026-12-08T19:00:00Z', '2026-12-08T20:00:00Z'), 'mardi');
        $this->assertSame(422, $this->session('2026-12-07T20:00:00Z', '2026-12-07T21:30:00Z'), 'déborde après 22h');
        $this->assertSame(422, $this->session('2026-12-08T19:00:00Z', '2026-12-08T20:00:00Z', null, null, true), 'un encadrant ne peut pas forcer');
        $this->assertSame(201, $this->session('2026-12-08T19:00:00Z', '2026-12-08T20:00:00Z', null, $this->ref, true), 'un référent peut forcer');

        [, $body] = $this->call('POST', '/api/sessions', ['sectionId' => $this->section, 'title' => 'S', 'start' => '2026-12-08T19:00:00Z', 'end' => '2026-12-08T20:00:00Z', 'laneIds' => [$this->poolA['lanes'][0]['id']]], $this->inst);
        $this->assertStringContainsString('Lundi, mercredi 20:00–22:00', $body['message']);
    }

    public function testSlotRestrictedToAPoolAndValidityPeriod(): void
    {
        $this->call('POST', '/api/slots', ['weekdays' => [1], 'startTime' => '20:00', 'endTime' => '22:00', 'poolId' => $this->poolB['id'], 'validUntil' => '2026-12-31'], $this->ref);
        $this->assertSame(422, $this->session('2026-12-07T19:00:00Z', '2026-12-07T20:00:00Z', $this->poolA), 'créneau réservé au grand bassin');
        $this->assertSame(201, $this->session('2026-12-07T19:00:00Z', '2026-12-07T20:00:00Z', $this->poolB));
        $this->assertSame(422, $this->session('2027-01-04T19:00:00Z', '2027-01-04T20:00:00Z', $this->poolB), 'après la fin de validité');
    }

    public function testCalendarIncludesSlots(): void
    {
        $this->call('POST', '/api/slots', ['label' => 'Club', 'weekdays' => [1], 'startTime' => '20:00', 'endTime' => '22:00'], $this->ref);
        [$code, $cal] = $this->call('GET', '/api/calendar?from=2026-12-07T00:00:00%2B01:00&to=2026-12-08T00:00:00%2B01:00', null, $this->inst);
        $this->assertSame(200, $code);
        $this->assertSame([['slotId' => $cal['slots'][0]['slotId'], 'label' => 'Club', 'poolId' => null, 'pool' => null, 'start' => '2026-12-07T20:00:00+01:00', 'end' => '2026-12-07T22:00:00+01:00']], $cal['slots']);
        $this->assertArrayHasKey('poolId', $cal['resources'][0]);
    }
}
