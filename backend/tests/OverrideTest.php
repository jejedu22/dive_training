<?php
namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Exceptions au plan d'occupation. Créneau lundi 20h–22h (Paris), fosse lignes 1 à 3.
 * Plan habituel : L1, L2 → N1 ; L3 → N2. Exception : le 1er lundi du mois, la fosse est à l'Apnée.
 * 2027 : 1er lundi de février = 1er février ; 8 février = 2e lundi ; 22 février = dernier lundi.
 */
class OverrideTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $ref;
    private string $inst;
    private array $lanes;
    private array $sec;
    private int $slot;

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
        $this->ref = $this->call('POST', '/api/login_check', ['email' => 'ref@x.fr', 'password' => 'pw'])[1]['token'];
        $this->inst = $this->call('POST', '/api/login_check', ['email' => 'inst@x.fr', 'password' => 'pw'])[1]['token'];
        $pool = $this->call('POST', '/api/pools', ['name' => 'Fosse', 'lanes' => [['name' => 'L1'], ['name' => 'L2'], ['name' => 'L3']]], $this->ref)[1];
        $this->lanes = array_column($pool['lanes'], 'id');
        foreach (['N1', 'N2', 'Apnée'] as $n) $this->sec[$n] = $this->call('POST', '/api/sections', ['name' => $n], $this->inst)[1]['id'];
        $this->slot = $this->call('POST', '/api/slots', ['label' => 'Entraînement', 'weekdays' => [1], 'startTime' => '20:00', 'endTime' => '22:00'], $this->ref)[1]['id'];
        $this->call('PUT', "/api/slots/{$this->slot}/allocations", ['allocations' => [
            ['laneId' => $this->lanes[0], 'sectionId' => $this->sec['N1']], ['laneId' => $this->lanes[1], 'sectionId' => $this->sec['N1']],
            ['laneId' => $this->lanes[2], 'sectionId' => $this->sec['N2']]]], $this->ref);
    }

    private function call(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token) $server['HTTP_AUTHORIZATION'] = "Bearer $token";
        $this->client->request($method, $uri, [], [], $server, $body !== null ? json_encode($body) : null);
        return [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent() ?: 'null', true)];
    }

    private function book(string $section, int $lane, string $day, bool $force = false, ?string $token = null): array
    {
        return $this->call('POST', '/api/sessions', ['sectionId' => $this->sec[$section], 'title' => "S $section", 'start' => "{$day}T20:00:00+01:00",
            'end' => "{$day}T21:30:00+01:00", 'laneIds' => [$lane], 'force' => $force], $token ?? $this->inst);
    }

    /** Section attribuée à chaque ligne le jour donné, d'après /api/occupancy. */
    private function plan(string $day): array
    {
        [, $occ] = $this->call('GET', "/api/occupancy?from={$day}T00:00:00%2B01:00&to={$day}T23:59:00%2B01:00", null, $this->inst);
        if (!$occ['occurrences']) return ['none'];
        $o = $occ['occurrences'][0];
        return $o['cancelled'] ? ['cancelled'] : array_map(fn ($l) => $l['allocation']['section'] ?? 'libre', $o['lanes']);
    }

    private function apneeFirstMonday(array $extra = []): array
    {
        return $this->call('POST', "/api/slots/{$this->slot}/overrides", $extra + ['label' => 'Apnée en fosse', 'kind' => 'monthly', 'weekday' => 1, 'nth' => 1,
            'allocations' => array_map(fn ($l) => ['laneId' => $l, 'sectionId' => $this->sec['Apnée']], $this->lanes)], $this->ref);
    }

    public function testFirstMondayOfTheMonthGoesToApnea(): void
    {
        // séances déjà prévues par les sections habituelles
        [, $s1] = $this->book('N1', $this->lanes[0], '2027-02-01');   // 1er lundi -> sera annulée
        [, $s2] = $this->book('N1', $this->lanes[0], '2027-02-08');   // 2e lundi -> conservée
        $this->assertSame('CONFIRMED', $s1['reservations'][0]['status']);

        [$code, $o] = $this->apneeFirstMonday();
        $this->assertSame(201, $code);
        $this->assertSame('le 1er lundi de chaque mois', $o['rule']);
        $this->assertSame(1, $o['cancelledReservations']);

        $this->assertSame(['Apnée', 'Apnée', 'Apnée'], $this->plan('2027-02-01'));
        $this->assertSame(['N1', 'N1', 'N2'], $this->plan('2027-02-08'), 'les autres lundis gardent le plan habituel');
        $this->assertSame(['Apnée', 'Apnée', 'Apnée'], $this->plan('2027-03-01'));

        [, $r1] = $this->call('GET', "/api/sessions/{$s1['id']}", null, $this->inst);
        $this->assertSame('CANCELLED', $r1['reservations'][0]['status']);
        $this->assertStringContainsString('Apnée en fosse', $r1['reservations'][0]['cancelReason']);
        [, $r2] = $this->call('GET', "/api/sessions/{$s2['id']}", null, $this->inst);
        $this->assertSame('CONFIRMED', $r2['reservations'][0]['status']);

        // le 1er lundi : N1 ne peut plus réserver sa ligne habituelle, l'Apnée est confirmée directement
        [$code, $body] = $this->book('N1', $this->lanes[0], '2027-03-01');
        $this->assertSame(409, $code);
        $this->assertStringContainsString('attribuée à « Apnée » ce jour-là', $body['message']);
        [$code, $a] = $this->book('Apnée', $this->lanes[1], '2027-03-01');
        $this->assertSame([201, 'CONFIRMED'], [$code, $a['reservations'][0]['status']]);

        // supprimer l'exception rétablit le plan habituel
        $this->assertSame(204, $this->call('DELETE', "/api/overrides/{$o['id']}", null, $this->ref)[0]);
        $this->assertSame(['N1', 'N1', 'N2'], $this->plan('2027-03-01'));
    }

    public function testFreeLanesCancelledSlotAndPrecedence(): void
    {
        // dernier lundi : L3 libérée (N2 perd sa ligne), les autres inchangées
        [, $n2] = $this->book('N2', $this->lanes[2], '2027-02-22');
        [, $n1] = $this->book('N1', $this->lanes[0], '2027-02-22');
        [$code, $o] = $this->call('POST', "/api/slots/{$this->slot}/overrides", ['kind' => 'monthly', 'weekday' => 1, 'nth' => -1,
            'allocations' => [['laneId' => $this->lanes[2], 'sectionId' => null]]], $this->ref);
        $this->assertSame([201, 'le dernier lundi de chaque mois', 1], [$code, $o['rule'], $o['cancelledReservations']]);
        $this->assertSame(['N1', 'N1', 'libre'], $this->plan('2027-02-22'));
        $this->assertSame('CANCELLED', $this->call('GET', "/api/sessions/{$n2['id']}", null, $this->inst)[1]['reservations'][0]['status']);
        $this->assertSame('CONFIRMED', $this->call('GET', "/api/sessions/{$n1['id']}", null, $this->inst)[1]['reservations'][0]['status'], 'ligne non concernée');
        // ligne libérée : demandable, en attente de validation
        [$code, $req] = $this->book('Apnée', $this->lanes[2], '2027-01-25'); // dernier lundi de janvier (heure d'hiver)
        $this->assertSame(201, $code, json_encode($req));
        $this->assertSame('PENDING', $req['reservations'][0]['status']);

        // date précise : piscine fermée -> créneau annulé, réservation impossible, séances annulées
        [, $s] = $this->book('N1', $this->lanes[1], '2027-02-15');
        [$code, $c] = $this->call('POST', "/api/slots/{$this->slot}/overrides", ['label' => 'Piscine fermée', 'kind' => 'date', 'date' => '2027-02-15', 'cancelled' => true], $this->ref);
        $this->assertSame([201, 1], [$code, $c['cancelledReservations']]);
        $this->assertSame(['cancelled'], $this->plan('2027-02-15'));
        $this->assertSame(422, $this->book('N1', $this->lanes[0], '2027-02-15')[0]);
        $this->assertSame(['N1', 'N1', 'N2'], $this->plan('2027-02-08'));

        // une date précise l'emporte sur une règle mensuelle
        $this->apneeFirstMonday();
        $this->call('POST', "/api/slots/{$this->slot}/overrides", ['kind' => 'date', 'date' => '2027-04-05',
            'allocations' => [['laneId' => $this->lanes[0], 'sectionId' => $this->sec['N1']]]], $this->ref);
        $this->assertSame(['N1', 'Apnée', 'Apnée'], $this->plan('2027-04-05'));
    }

    public function testValidation(): void
    {
        $bad = [
            ['kind' => 'weekly', 'cancelled' => true],
            ['kind' => 'date', 'date' => '2027-02-02', 'cancelled' => true],          // un mardi : pas de créneau
            ['kind' => 'monthly', 'weekday' => 3, 'nth' => 1, 'cancelled' => true],   // mercredi : pas de créneau
            ['kind' => 'monthly', 'weekday' => 1, 'nth' => 5, 'cancelled' => true],
            ['kind' => 'date', 'date' => '2027-02-01'],                                // rien à changer
        ];
        foreach ($bad as $b) $this->assertSame(400, $this->call('POST', "/api/slots/{$this->slot}/overrides", $b, $this->ref)[0], json_encode($b));
        $this->assertSame(403, $this->call('POST', "/api/slots/{$this->slot}/overrides", ['kind' => 'date', 'date' => '2027-02-01', 'cancelled' => true], $this->inst)[0]);
        [, $slots] = $this->call('GET', '/api/slots', null, $this->inst);
        $this->assertSame([], $slots[0]['overrides']);

        // modification : la règle passe au 2e lundi
        [, $o] = $this->apneeFirstMonday();
        [$code, $o] = $this->call('PUT', "/api/overrides/{$o['id']}", ['nth' => 2], $this->ref);
        $this->assertSame([200, 'le 2e lundi de chaque mois'], [$code, $o['rule']]);
        $this->assertSame(['N1', 'N1', 'N2'], $this->plan('2027-02-01'));
        $this->assertSame(['Apnée', 'Apnée', 'Apnée'], $this->plan('2027-02-08'));
    }
}
