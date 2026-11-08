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
 * - Aucun administrateur actif (base vide, ou comptes créés sans rôle admin) :
 *   crée le compte ADMIN_EMAIL / ADMIN_PASSWORD, ou s'il existe déjà, le réactive et le promeut
 *   administrateur (sans toucher à son mot de passe).
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

        if ($this->users->countActiveAdmins() === 0) {
            if ($email === '' || $password === '') {
                $out->writeln("<error>Aucun administrateur. Définissez ADMIN_EMAIL et ADMIN_PASSWORD dans .env puis redémarrez le backend. $fix</error>");
                return Command::SUCCESS;
            }
            try {
                $u = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
                if (!$u) {
                    $u = $this->users->create($email, getenv('ADMIN_NAME') ?: 'Administrateur', $password, ['ROLE_ADMIN']);
                    $out->writeln(sprintf('Administrateur créé : %s (aucun administrateur n\'existait ; mot de passe de %d caractères).', $u->getEmail(), mb_strlen($password)));
                } else {
                    $u->setRoles(array_unique([...$u->getStoredRoles(), 'ROLE_ADMIN']))->setActive(true);
                    $this->em->flush();
                    $out->writeln("Aucun administrateur : le compte existant $email est maintenant administrateur (mot de passe inchangé).");
                }
            } catch (\Throwable $e) {
                $out->writeln('<error>Administrateur non créé : ' . $e->getMessage() . '</error>');
            }
            return Command::SUCCESS; // ne jamais empêcher le conteneur de démarrer
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
