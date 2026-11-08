<?php
namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class UserAuthTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    private function call(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token) $server['HTTP_AUTHORIZATION'] = "Bearer $token";
        $this->client->request($method, $uri, [], [], $server, $body !== null ? json_encode($body) : null);
        return [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent() ?: 'null', true)];
    }

    private function login(string $email, string $password): array
    {
        return $this->call('POST', '/api/login_check', ['email' => $email, 'password' => $password]);
    }

    private function ensureAdmin(?string $email = 'Admin@Club.fr ', ?string $password = 'secret123'): string
    {
        putenv("ADMIN_EMAIL=$email");
        putenv("ADMIN_PASSWORD=$password");
        $tester = new CommandTester((new Application(static::$kernel))->find('app:user:ensure-admin'));
        $tester->execute([]);
        return $tester->getDisplay();
    }

    public function testHealthIsPublic(): void
    {
        [$code, $data] = $this->call('GET', '/api/health');
        $this->assertSame(200, $code);
        $this->assertSame(['api' => 'ok', 'database' => 'ok', 'hasUsers' => false, 'jwtKeys' => 'ok'], $data);
    }

    public function testEnsureAdminCreatesFirstAccountOnlyOnce(): void
    {
        $this->assertStringContainsString('Administrateur créé : admin@club.fr', $this->ensureAdmin());
        $this->assertStringContainsString('ne correspond à aucun compte', $this->ensureAdmin('autre@club.fr'), 'pas de second compte créé, mais un diagnostic');
        // e-mail normalisé : la casse et les espaces ne bloquent pas la connexion
        [$code, $data] = $this->login(' ADMIN@club.FR', 'secret123');
        $this->assertSame(200, $code, json_encode($data));
        [, $me] = $this->call('GET', '/api/me', null, $data['token']);
        $this->assertTrue($me['admin']);
        $this->assertTrue($me['referent']);
    }

    public function testAdminManagesAccounts(): void
    {
        $this->ensureAdmin();
        [, $t] = $this->login('admin@club.fr', 'secret123');
        $admin = $t['token'];

        [$code] = $this->call('POST', '/api/users', ['email' => 'enc@club.fr', 'name' => 'Enc', 'password' => 'court'], $admin);
        $this->assertSame(400, $code);
        [$code, $u] = $this->call('POST', '/api/users', ['email' => 'enc@club.fr', 'name' => 'Enc', 'password' => 'motdepasse'], $admin);
        $this->assertSame(201, $code);
        $this->assertFalse($u['referent']);
        [$code] = $this->call('POST', '/api/users', ['email' => 'ENC@club.fr', 'name' => 'Doublon', 'password' => 'motdepasse'], $admin);
        $this->assertSame(409, $code);

        // un encadrant n'a pas accès à la gestion des comptes
        [, $t] = $this->login('enc@club.fr', 'motdepasse');
        [$code] = $this->call('GET', '/api/users', null, $t['token']);
        $this->assertSame(403, $code);

        // promotion en référent
        [$code, $u] = $this->call('PUT', "/api/users/{$u['id']}", ['roles' => ['ROLE_REFERENT', 'ROLE_INVENTE']], $admin);
        $this->assertSame(['ROLE_REFERENT'], $u['roles']);

        // désactivation : la connexion ET le jeton déjà émis sont refusés
        $this->call('PUT', "/api/users/{$u['id']}", ['active' => false], $admin);
        [$code] = $this->login('enc@club.fr', 'motdepasse');
        $this->assertSame(401, $code);
        [$code] = $this->call('GET', '/api/me', null, $t['token']);
        $this->assertSame(401, $code);

        // garde-fous sur l'administrateur
        [, $me] = $this->call('GET', '/api/me', null, $admin);
        [$code] = $this->call('PUT', "/api/users/{$me['id']}", ['active' => false], $admin);
        $this->assertSame(400, $code);
        [$code] = $this->call('PUT', "/api/users/{$me['id']}", ['roles' => []], $admin);
        $this->assertSame(400, $code);
    }

    public function testChangeOwnPassword(): void
    {
        $this->ensureAdmin();
        [, $t] = $this->login('admin@club.fr', 'secret123');
        [$code] = $this->call('PUT', '/api/me/password', ['currentPassword' => 'faux', 'newPassword' => 'nouveau123'], $t['token']);
        $this->assertSame(400, $code);
        [$code] = $this->call('PUT', '/api/me/password', ['currentPassword' => 'secret123', 'newPassword' => 'nouveau123'], $t['token']);
        $this->assertSame(200, $code);
        $this->assertSame(401, $this->login('admin@club.fr', 'secret123')[0]);
        $this->assertSame(200, $this->login('admin@club.fr', 'nouveau123')[0]);
    }

    public function testBadCredentialsReturnJson401(): void
    {
        [$code, $data] = $this->login('personne@club.fr', 'x');
        $this->assertSame(401, $code);
        $this->assertArrayHasKey('message', $data);
    }
}
