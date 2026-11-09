<?php
namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Personnalisation du compte (profil, préférences) et gestion des comptes par l'administrateur. */
class AccountTest extends WebTestCase
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
        foreach ([['marie@club.fr', 'Marie', []], ['admin@club.fr', 'Admin', ['ROLE_ADMIN']], ['paul@club.fr', 'Paul', []]] as [$email, $name, $roles]) {
            $u = (new User())->setEmail($email)->setName($name)->setRoles($roles);
            $u->setPassword($hasher->hashPassword($u, 'motdepasse'));
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

    private function login(string $email, string $password = 'motdepasse'): ?string
    {
        return $this->call('POST', '/api/login_check', ['email' => $email, 'password' => $password])[1]['token'] ?? null;
    }

    public function testProfileAndDefaults(): void
    {
        $t = $this->login('marie@club.fr');
        [$code, $me] = $this->call('GET', '/api/me', null, $t);
        $this->assertSame(200, $code);
        $this->assertSame(['homePage' => 'planning', 'planningView' => 'week', 'onlyMine' => false], $me['preferences']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $me['avatarColor']);
        $this->assertNotNull($me['createdAt']);
        $this->assertNotNull($me['lastLoginAt'], 'dernière connexion enregistrée');

        [$code, $me] = $this->call('PUT', '/api/me', ['name' => 'Marie D.', 'phone' => '06 12 34 56 78', 'avatarColor' => '#C2378F',
            'preferences' => ['homePage' => 'sections', 'planningView' => 'month', 'onlyMine' => true]], $t);
        $this->assertSame(200, $code);
        $this->assertSame(['Marie D.', '06 12 34 56 78', '#c2378f'], [$me['name'], $me['phone'], $me['avatarColor']]);
        $this->assertSame(['homePage' => 'sections', 'planningView' => 'month', 'onlyMine' => true], $me['preferences']);
        $this->assertArrayNotHasKey('token', $me, 'pas de nouveau jeton sans changement d\'e-mail');

        // une préférence modifiée seule conserve les autres
        [, $me] = $this->call('PUT', '/api/me', ['preferences' => ['planningView' => 'week']], $t);
        $this->assertSame(['homePage' => 'sections', 'planningView' => 'week', 'onlyMine' => true], $me['preferences']);

        foreach ([['name' => ' '], ['phone' => 'abc'], ['avatarColor' => 'rose'], ['preferences' => ['homePage' => 'ailleurs']], ['preferences' => ['theme' => 'x']]] as $bad) {
            $this->assertSame(400, $this->call('PUT', '/api/me', $bad, $t)[0], json_encode($bad));
        }
        // le rôle ne se change pas soi-même
        $this->call('PUT', '/api/me', ['roles' => ['ROLE_ADMIN']], $t);
        [, $me] = $this->call('GET', '/api/me', null, $t);
        $this->assertFalse($me['admin']);
    }

    public function testChangeOwnEmail(): void
    {
        $t = $this->login('marie@club.fr');
        $this->assertSame(400, $this->call('PUT', '/api/me', ['email' => 'marie.d@club.fr'], $t)[0], 'mot de passe requis');
        $this->assertSame(400, $this->call('PUT', '/api/me', ['email' => 'marie.d@club.fr', 'currentPassword' => 'faux'], $t)[0]);
        $this->assertSame(409, $this->call('PUT', '/api/me', ['email' => 'paul@club.fr', 'currentPassword' => 'motdepasse'], $t)[0]);

        [$code, $me] = $this->call('PUT', '/api/me', ['email' => 'Marie.D@Club.fr', 'currentPassword' => 'motdepasse'], $t);
        $this->assertSame(200, $code);
        $this->assertSame('marie.d@club.fr', $me['email']);
        $this->assertNotEmpty($me['token']);
        $this->assertSame(200, $this->call('GET', '/api/me', null, $me['token'])[0], 'le nouveau jeton fonctionne');
        $this->assertNull($this->login('marie@club.fr'), 'l\'ancien e-mail ne permet plus de se connecter');
        $this->assertNotNull($this->login('marie.d@club.fr'));
    }

    public function testAdminEditsAccounts(): void
    {
        $admin = $this->login('admin@club.fr');
        [, $users] = $this->call('GET', '/api/users', null, $admin);
        $paul = array_values(array_filter($users, fn ($u) => $u['name'] === 'Paul'))[0];
        $this->assertSame([], $paul['sections']);
        $this->assertNull($paul['lastLoginAt'], 'jamais connecté');

        [$code, $u] = $this->call('PUT', "/api/users/{$paul['id']}", ['name' => 'Paul Martin', 'email' => 'p.martin@club.fr', 'phone' => '0612345678'], $admin);
        $this->assertSame(200, $code);
        $this->assertSame(['Paul Martin', 'p.martin@club.fr', '0612345678'], [$u['name'], $u['email'], $u['phone']]);
        $this->assertSame(409, $this->call('PUT', "/api/users/{$paul['id']}", ['email' => 'marie@club.fr'], $admin)[0]);

        // sections encadrées visibles dans la liste
        $marie = $this->login('marie@club.fr');
        $this->call('POST', '/api/sections', ['name' => 'Niveau 1'], $marie);
        [, $users] = $this->call('GET', '/api/users', null, $admin);
        $m = array_values(array_filter($users, fn ($u) => $u['name'] === 'Marie'))[0];
        $this->assertSame(['Niveau 1'], array_column($m['sections'], 'name'));
        $this->assertNotNull($m['lastLoginAt']);
    }
}
