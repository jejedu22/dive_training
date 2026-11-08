<?php
namespace App\Tests;

use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AdminCommandsTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        putenv('ADMIN_EMAIL=admin@club.fr');
        putenv('ADMIN_PASSWORD=secret123');
    }

    private function cmd(string $name, array $args = []): string
    {
        $t = new CommandTester((new Application(static::$kernel))->find($name));
        $t->execute($args);
        return $t->getDisplay();
    }

    private function login(string $email, string $password): bool
    {
        $users = static::getContainer()->get(UserManager::class);
        $u = static::getContainer()->get(EntityManagerInterface::class)->getRepository(\App\Entity\User::class)->findOneBy(['email' => $email]);
        return $u && $u->isActive() && $u->isAdmin() && $users->isPasswordValid($u, $password);
    }

    public function testEnsureAdminDiagnosesAPasswordThatNoLongerMatches(): void
    {
        $this->assertStringContainsString('Administrateur créé', $this->cmd('app:user:ensure-admin'));
        $this->assertStringContainsString('Administrateur admin@club.fr : OK', $this->cmd('app:user:ensure-admin'));
        putenv('ADMIN_PASSWORD=autre-mot-de-passe');   // .env modifié après coup
        $out = $this->cmd('app:user:ensure-admin');
        $this->assertStringContainsString('ADMIN_PASSWORD ne correspond pas', $out);
        $this->assertStringContainsString('app:admin:reset', $out);
        $this->assertTrue($this->login('admin@club.fr', 'secret123'), 'ensure-admin ne change pas le mot de passe existant');
    }

    public function testAdminResetRepairsTheAccount(): void
    {
        // compte créé à la main sans le rôle admin, puis désactivé
        $this->cmd('app:user:create', ['email' => 'Admin@Club.fr', 'name' => 'Moi', 'password' => 'ancienmdp']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'admin@club.fr'])->setActive(false);
        $em->flush();
        $this->assertStringContainsString("n'est pas actif", $this->cmd('app:user:ensure-admin'));

        $out = $this->cmd('app:admin:reset');   // valeurs de ADMIN_EMAIL / ADMIN_PASSWORD
        $this->assertStringContainsString('réparé', $out);
        $this->assertStringContainsString('9 caractères', $out);
        $this->assertTrue($this->login('admin@club.fr', 'secret123'));

        $this->assertStringContainsString('créé', $this->cmd('app:admin:reset', ['email' => 'chef@club.fr', 'password' => 'motdepasse2']));
        $this->assertTrue($this->login('chef@club.fr', 'motdepasse2'));
        $this->assertStringContainsString('chef@club.fr', $this->cmd('app:user:list'));
        $this->assertStringContainsString('Mot de passe trop court', $this->cmd('app:admin:reset', ['email' => 'x@club.fr', 'password' => 'court']));
    }
}
