<?php
namespace App\Command;

use App\Entity\User;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:user:password', description: 'Change le mot de passe d\'un compte (et le réactive)')]
class UserPasswordCommand extends Command
{
    public function __construct(private UserManager $users, private EntityManagerInterface $em) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)->addArgument('password', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $u = $this->em->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim($in->getArgument('email')))]);
        if (!$u) { $out->writeln('<error>Compte introuvable</error>'); return Command::FAILURE; }
        try {
            $this->users->setPassword($u, $in->getArgument('password'));
        } catch (\Throwable $e) {
            $out->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $u->setActive(true);
        $this->em->flush();
        $out->writeln('Mot de passe modifié.');
        return Command::SUCCESS;
    }
}
