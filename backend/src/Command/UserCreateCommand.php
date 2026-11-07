<?php
namespace App\Command;

use App\Service\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:user:create', description: 'Crée un encadrant. Rôles possibles : ROLE_REFERENT, ROLE_ADMIN')]
class UserCreateCommand extends Command
{
    public function __construct(private UserManager $users) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)->addArgument('name', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED)->addArgument('roles', InputArgument::IS_ARRAY);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        try {
            $u = $this->users->create($in->getArgument('email'), $in->getArgument('name'), $in->getArgument('password'), $in->getArgument('roles'));
        } catch (\Throwable $e) {
            $out->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $out->writeln(sprintf('Compte créé : %s (%s)', $u->getEmail(), implode(', ', $u->getRoles())));
        return Command::SUCCESS;
    }
}
