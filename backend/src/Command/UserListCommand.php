<?php
namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:user:list', description: 'Liste les comptes (e-mail, rôle, état)')]
class UserListCommand extends Command
{
    public function __construct(private EntityManagerInterface $em) { parent::__construct(); }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $rows = array_map(fn (User $u) => [
            $u->getEmail(), $u->getName(),
            $u->isAdmin() ? 'administrateur' : ($u->isReferent() ? 'référent' : 'encadrant'),
            $u->isActive() ? 'actif' : 'DÉSACTIVÉ',
        ], $this->em->getRepository(User::class)->findBy([], ['email' => 'ASC']));
        if (!$rows) {
            $out->writeln('Aucun compte. Lancez : php bin/console app:admin:reset vous@club.fr \'motdepasse\'');
            return Command::SUCCESS;
        }
        (new Table($out))->setHeaders(['E-mail', 'Nom', 'Rôle', 'État'])->setRows($rows)->render();
        return Command::SUCCESS;
    }
}
