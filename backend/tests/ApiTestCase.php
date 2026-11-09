<?php
namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Base des tests d'API : base vide, comptes inst@x.fr (encadrant) et ref@x.fr (référent), mot de passe « pw ». */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected string $inst;
    protected string $ref;

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
        $this->inst = $this->login('inst@x.fr');
        $this->ref = $this->login('ref@x.fr');
    }

    protected function login(string $email): string
    {
        return $this->call('POST', '/api/login_check', ['email' => $email, 'password' => 'pw'])[1]['token'];
    }

    protected function call(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token) $server['HTTP_AUTHORIZATION'] = "Bearer $token";
        $this->client->request($method, $uri, [], [], $server, $body !== null ? json_encode($body) : null);
        return [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent() ?: 'null', true)];
    }

    /** Planning d'un jour (heure de Paris) : liste des occurrences. */
    protected function planningOf(string $day, ?string $token = null): array
    {
        return $this->call('GET', "/api/planning?from={$day}T00:00:00%2B01:00&to={$day}T23:59:00%2B01:00", null, $token ?? $this->inst)[1]['occurrences'];
    }

    /** Section de chaque ligne du 1er créneau du jour (« libre », « annulé » ou « aucun »). */
    protected function lanesOf(string $day): array
    {
        $occ = $this->planningOf($day);
        if (!$occ) return ['aucun'];
        return $occ[0]['cancelled'] ? ['annulé'] : array_map(fn ($l) => $l['section']['name'] ?? 'libre', $occ[0]['lanes']);
    }
}
