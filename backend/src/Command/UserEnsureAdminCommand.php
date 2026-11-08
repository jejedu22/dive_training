<?php
namespace App\Command;

use App\Entity\User;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lancée au démarrage du conteneur.
 * - Base sans compte : crée l'administrateur ADMIN_EMAIL / ADMIN_PASSWORD.
 * - Sinon : ne modifie rien, mais signale dans les logs ce qui empêcherait l'administrateur de se connecter.
 */
#[AsCommand(name: 'app:user:ensure-admin', description: 'Crée le premier administrateur depuis ADMIN_EMAIL/ADMIN_PASSWORD si aucun compte n\'existe, et diagnostique le compte admin')]
class UserEnsureAdminCommand extends Command
{
    public function __construct(private UserManager $users, private EntityManagerInterface $em) { parent::__construct(); }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $email = mb_strtolower(trim((string) getenv('ADMIN_EMAIL')));
        $password = (string) getenv('ADMIN_PASSWORD');
        $fix = 'Pour réparer : docker compose exec backend php bin/console app:admin:reset';

        if ($this->users->count() === 0) {
            if ($email === '' || $password === '') {
                $out->writeln('<comment>Aucun compte. Définissez ADMIN_EMAIL et ADMIN_PASSWORD dans .env puis redémarrez, ou lancez app:admin:reset.</comment>');
                return Command::SUCCESS;
            }
            try {
                $u = $this->users->create($email, getenv('ADMIN_NAME') ?: 'Administrateur', $password, ['ROLE_ADMIN']);
                $out->writeln(sprintf('Administrateur créé : %s (mot de passe de %d caractères)', $u->getEmail(), mb_strlen($password)));
            } catch (\Throwable $e) {
                $out->writeln('<error>Administrateur non créé : ' . $e->getMessage() . '</error>');
            }
            return Command::SUCCESS; // ne jamais empêcher le conteneur de démarrer
        }

        if ($this->users->countActiveAdmins() === 0) {
            $out->writeln("<error>Aucun administrateur actif : personne ne peut gérer les comptes. $fix</error>");
        }
        if ($email !== '') {
            $u = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if (!$u) {
                $out->writeln("<comment>ADMIN_EMAIL ($email) ne correspond à aucun compte : il n'est utilisé qu'au tout premier démarrage. Comptes : app:user:list. $fix</comment>");
            } elseif (!$u->isActive() || !$u->isAdmin()) {
                $out->writeln("<comment>Le compte $email existe mais n'est " . (!$u->isActive() ? 'pas actif' : 'pas administrateur') . ". $fix</comment>");
            } elseif ($password !== '' && !$this->users->isPasswordValid($u, $password)) {
                $out->writeln("<comment>ADMIN_PASSWORD ne correspond pas au mot de passe de $email (normal s'il a été changé dans l'application ; .env ne sert qu'à la création). $fix</comment>");
            } else {
                $out->writeln("Administrateur $email : OK.");
            }
        }
        return Command::SUCCESS;
    }
}
