<?php
namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Modification des bassins et des lignes d'eau après création. */
class PoolEditTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $ref;
    private string $inst;

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
    }

    private function call(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token) $server['HTTP_AUTHORIZATION'] = "Bearer $token";
        $this->client->request($method, $uri, [], [], $server, $body !== null ? json_encode($body) : null);
        return [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent() ?: 'null', true)];
    }

    private function pool(int $lanes = 3): array
    {
        return $this->call('POST', '/api/pools', ['name' => 'Fosse', 'lanes' => array_map(fn ($i) => ['name' => "Ligne $i"], range(1, $lanes))], $this->ref)[1];
    }

    public function testRenamePoolAndEditLanes(): void
    {
        $pool = $this->pool();
        $this->assertSame(403, $this->call('PUT', "/api/pools/{$pool['id']}", ['name' => 'X'], $this->inst)[0], 'encadrant : lecture seule');
        $this->assertSame(400, $this->call('PUT', "/api/pools/{$pool['id']}", ['name' => '  '], $this->ref)[0]);
        [$code, $p] = $this->call('PUT', "/api/pools/{$pool['id']}", ['name' => 'Fosse 20 m'], $this->ref);
        $this->assertSame([200, 'Fosse 20 m'], [$code, $p['name']]);

        $lane = $pool['lanes'][0]['id'];
        $this->assertSame(403, $this->call('PUT', "/api/lanes/$lane", ['name' => 'X'], $this->inst)[0]);
        $this->assertSame(400, $this->call('PUT', "/api/lanes/$lane", ['maxDepth' => -2], $this->ref)[0]);
        [$code, $l] = $this->call('PUT', "/api/lanes/$lane", ['name' => 'Ligne lente', 'maxDepth' => '4.5'], $this->ref);
        $this->assertSame([200, 'Ligne lente', 4.5], [$code, $l['name'], $l['maxDepth']]);
        [, $l] = $this->call('PUT', "/api/lanes/$lane", ['maxDepth' => null], $this->ref);
        $this->assertNull($l['maxDepth']);
    }

    public function testLaneOrder(): void
    {
        // « Ligne 10 » doit rester après « Ligne 2 » (avant : tri alphabétique)
        $pool = $this->pool(10);
        [, $pools] = $this->call('GET', '/api/pools', null, $this->inst);
        $this->assertSame(array_map(fn ($i) => "Ligne $i", range(1, 10)), array_column($pools[0]['lanes'], 'name'));

        [, $p] = $this->call('POST', "/api/pools/{$pool['id']}/lanes", ['name' => 'Ligne 11'], $this->ref);
        $ids = array_column($pool['lanes'], 'id');
        $reordered = array_merge([$ids[2]], array_diff($ids, [$ids[2]]), [$p['id']]);
        $this->assertSame(400, $this->call('PUT', "/api/pools/{$pool['id']}/lanes/order", ['laneIds' => [$ids[0]]], $this->ref)[0], 'toutes les lignes requises');
        [$code, $out] = $this->call('PUT', "/api/pools/{$pool['id']}/lanes/order", ['laneIds' => array_values($reordered)], $this->ref);
        $this->assertSame(200, $code);
        $this->assertSame(array_values($reordered), array_column($out['lanes'], 'id'));
        // l'ordre est repris dans la liste des bassins (et donc partout dans l'application)
        [, $pools] = $this->call('GET', '/api/pools', null, $this->inst);
        $this->assertSame(array_values($reordered), array_column($pools[0]['lanes'], 'id'));
    }

    public function testDeleteLaneAndPool(): void
    {
        $pool = $this->pool(3);
        [$l1, $l2, $l3] = array_column($pool['lanes'], 'id');
        $this->assertSame(403, $this->call('DELETE', "/api/lanes/$l2", null, $this->inst)[0]);
        $this->assertSame(204, $this->call('DELETE', "/api/lanes/$l3", null, $this->ref)[0]);
        [, $pools] = $this->call('GET', '/api/pools', null, $this->ref);
        $this->assertSame([$l1, $l2], array_column($pools[0]['lanes'], 'id'));
        $this->assertSame(403, $this->call('DELETE', "/api/pools/{$pool['id']}", null, $this->inst)[0]);
        $this->assertSame(204, $this->call('DELETE', "/api/pools/{$pool['id']}", null, $this->ref)[0]);
        [, $pools] = $this->call('GET', '/api/pools', null, $this->ref);
        $this->assertSame([], $pools);
    }

    public function testNewLaneOpensOnSlotsWhereTheWholePoolIsOpen(): void
    {
        $fosse = $this->pool(2);
        $grand = $this->call('POST', '/api/pools', ['name' => 'Grand bassin', 'lanes' => [['name' => 'G1'], ['name' => 'G2']]], $this->ref)[1];
        $F = array_column($fosse['lanes'], 'id');
        $G = array_column($grand['lanes'], 'id');
        // créneau : toute la fosse + seulement G1 du grand bassin
        $slot = $this->call('POST', '/api/slots', ['weekdays' => [1], 'startTime' => '20:00', 'endTime' => '22:00', 'laneIds' => [...$F, $G[0]]], $this->ref)[1];
        $f3 = $this->call('POST', "/api/pools/{$fosse['id']}/lanes", ['name' => 'Ligne 3'], $this->ref)[1]['id'];
        $g3 = $this->call('POST', "/api/pools/{$grand['id']}/lanes", ['name' => 'G3'], $this->ref)[1]['id'];
        [, $slots] = $this->call('GET', '/api/slots', null, $this->ref);
        $open = $slots[0]['availableLaneIds'];
        $this->assertContains($f3, $open, 'toute la fosse était ouverte');
        $this->assertNotContains($g3, $open, 'le grand bassin ne l\'était qu\'en partie');
        $this->assertSame($slot['id'], $slots[0]['id']);
    }
}
