<?php
namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ApiFlowTest extends WebTestCase
{
    private KernelBrowser $client;

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
    }

    private function call(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token) $server['HTTP_AUTHORIZATION'] = "Bearer $token";
        $this->client->request($method, $uri, [], [], $server, $body !== null ? json_encode($body) : null);
        return [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent() ?: 'null', true)];
    }

    private function login(string $email): string
    {
        [$code, $data] = $this->call('POST', '/api/login_check', ['email' => $email, 'password' => 'pw']);
        $this->assertSame(200, $code);
        return $data['token'];
    }

    public function testAnonymousIsRefused(): void
    {
        [$code] = $this->call('GET', '/api/sections');
        $this->assertSame(401, $code);
    }

    public function testFullFlow(): void
    {
        $inst = $this->login('inst@x.fr');
        $ref = $this->login('ref@x.fr');

        // seul un référent crée un bassin
        [$code] = $this->call('POST', '/api/pools', ['name' => 'Fosse'], $inst);
        $this->assertSame(403, $code);
        [$code, $pool] = $this->call('POST', '/api/pools', ['name' => 'Fosse', 'lanes' => [['name' => 'L1'], ['name' => 'L2']]], $ref);
        $this->assertSame(201, $code);
        [$l1, $l2] = array_column($pool['lanes'], 'id');

        [, $diver1] = $this->call('POST', '/api/divers', ['firstName' => 'A', 'lastName' => 'B'], $inst);
        [, $diver2] = $this->call('POST', '/api/divers', ['firstName' => 'C', 'lastName' => 'D'], $inst);
        [, $section] = $this->call('POST', '/api/sections', ['name' => 'N1'], $inst);
        $this->call('POST', "/api/sections/{$section['id']}/divers", ['diverId' => $diver1['id']], $inst);
        $this->call('POST', "/api/sections/{$section['id']}/divers", ['diverId' => $diver2['id']], $inst);

        $slot = ['sectionId' => $section['id'], 'title' => 'Séance 1', 'start' => '2026-12-01T19:00:00+01:00', 'end' => '2026-12-01T20:30:00+01:00', 'laneIds' => [$l1]];
        [$code, $session] = $this->call('POST', '/api/sessions', $slot, $inst);
        $this->assertSame(201, $code);
        $this->assertCount(2, $session['participants']);
        $this->assertSame('PENDING', $session['reservations'][0]['status']);

        // « qui fait bouteille »
        [, $session] = $this->call('PUT', "/api/sessions/{$session['id']}/participants/{$diver1['id']}", ['usesTank' => true], $inst);
        $this->assertSame(1, $session['tankCount']);

        // un encadrant ne peut pas valider
        $resId = $session['reservations'][0]['id'];
        [$code] = $this->call('POST', "/api/reservations/$resId/confirm", null, $inst);
        $this->assertSame(403, $code);
        [$code, $res] = $this->call('POST', "/api/reservations/$resId/confirm", null, $ref);
        $this->assertSame(200, $code);
        $this->assertSame('CONFIRMED', $res['status']);

        // chevauchement sur la même ligne refusé, autre ligne ou créneau adjacent OK
        $overlap = ['title' => 'Autre', 'start' => '2026-12-01T20:00:00+01:00', 'end' => '2026-12-01T21:00:00+01:00'] + $slot;
        [$code] = $this->call('POST', '/api/sessions', $overlap, $inst);
        $this->assertSame(409, $code);
        [$code] = $this->call('POST', '/api/sessions', ['laneIds' => [$l2]] + $overlap, $inst);
        $this->assertSame(201, $code);
        [$code] = $this->call('POST', '/api/sessions', ['start' => '2026-12-01T20:30:00+01:00', 'end' => '2026-12-01T21:30:00+01:00'] + $overlap, $inst);
        $this->assertSame(201, $code);

        [$code, $cal] = $this->call('GET', '/api/calendar?from=2026-12-01T00:00:00Z&to=2026-12-02T00:00:00Z', null, $inst);
        $this->assertSame(200, $code);
        $this->assertCount(2, $cal['resources']);
        $this->assertCount(3, $cal['events']);
    }

    public function testPendingDoubleBookingBlockedAtConfirmation(): void
    {
        $inst = $this->login('inst@x.fr');
        $ref = $this->login('ref@x.fr');
        [, $pool] = $this->call('POST', '/api/pools', ['name' => 'P', 'lanes' => [['name' => 'L1']]], $ref);
        $lane = $pool['lanes'][0]['id'];
        [, $s] = $this->call('POST', '/api/sections', ['name' => 'S'], $inst);
        $slot = ['sectionId' => $s['id'], 'title' => 'T', 'start' => '2026-12-02T19:00:00Z', 'end' => '2026-12-02T20:00:00Z', 'laneIds' => [$lane]];
        [, $a] = $this->call('POST', '/api/sessions', $slot, $inst);
        [, $b] = $this->call('POST', '/api/sessions', $slot, $inst); // les deux sont PENDING
        [$c1] = $this->call('POST', '/api/reservations/' . $a['reservations'][0]['id'] . '/confirm', null, $ref);
        [$c2] = $this->call('POST', '/api/reservations/' . $b['reservations'][0]['id'] . '/confirm', null, $ref);
        $this->assertSame([200, 409], [$c1, $c2]);
    }
}
