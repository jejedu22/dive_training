<?php
namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Crée un encadrant (rôles : ROLE_REFERENT, ROLE_ADMIN)')]
class CreateUserCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private UserPasswordHasherInterface $hasher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)->addArgument('name', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED)->addArgument('roles', InputArgument::IS_ARRAY);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $u = (new User())->setEmail($in->getArgument('email'))->setName($in->getArgument('name'))->setRoles($in->getArgument('roles'));
        $u->setPassword($this->hasher->hashPassword($u, $in->getArgument('password')));
        $this->em->persist($u);
        $this->em->flush();
        $out->writeln('Utilisateur créé.');
        return Command::SUCCESS;
    }
}
