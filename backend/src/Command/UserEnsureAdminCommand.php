<?php
namespace App\Command;

use App\Service\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Lancée au démarrage du conteneur : crée l'administrateur initial (ADMIN_EMAIL / ADMIN_PASSWORD) si la base n'a aucun compte. */
#[AsCommand(name: 'app:user:ensure-admin', description: 'Crée le premier administrateur depuis ADMIN_EMAIL/ADMIN_PASSWORD si aucun compte n\'existe')]
class UserEnsureAdminCommand extends Command
{
    public function __construct(private UserManager $users) { parent::__construct(); }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        if ($this->users->count() > 0) {
            $out->writeln('Comptes existants : aucun administrateur créé.');
            return Command::SUCCESS;
        }
        $email = (string) getenv('ADMIN_EMAIL');
        $password = (string) getenv('ADMIN_PASSWORD');
        if ($email === '' || $password === '') {
            $out->writeln('<comment>Aucun compte. Définissez ADMIN_EMAIL et ADMIN_PASSWORD dans .env, ou lancez app:user:create.</comment>');
            return Command::SUCCESS;
        }
        try {
            $u = $this->users->create($email, getenv('ADMIN_NAME') ?: 'Administrateur', $password, ['ROLE_ADMIN']);
        } catch (\Throwable $e) {
            $out->writeln('<error>Administrateur non créé : ' . $e->getMessage() . '</error>');
            return Command::SUCCESS; // ne pas empêcher le conteneur de démarrer
        }
        $out->writeln(sprintf('Administrateur créé : %s', $u->getEmail()));
        return Command::SUCCESS;
    }
}
