<?php
namespace App\Command;

use App\Entity\User;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dépannage : garantit qu'un compte administrateur utilisable existe.
 * Crée le compte s'il n'existe pas ; sinon le réactive, lui donne ROLE_ADMIN et remplace son mot de passe.
 * Sans arguments, utilise ADMIN_EMAIL / ADMIN_PASSWORD de l'environnement (.env).
 */
#[AsCommand(name: 'app:admin:reset', description: 'Crée ou répare le compte administrateur (e-mail et mot de passe en arguments, ou ADMIN_EMAIL / ADMIN_PASSWORD)')]
class AdminResetCommand extends Command
{
    public function __construct(private UserManager $users, private EntityManagerInterface $em) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::OPTIONAL)->addArgument('password', InputArgument::OPTIONAL);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $email = mb_strtolower(trim((string) ($in->getArgument('email') ?? getenv('ADMIN_EMAIL'))));
        $password = (string) ($in->getArgument('password') ?? getenv('ADMIN_PASSWORD'));
        if ($email === '' || $password === '') {
            $out->writeln('<error>Indiquez l\'e-mail et le mot de passe : app:admin:reset vous@club.fr \'motdepasse\' (ou définissez ADMIN_EMAIL / ADMIN_PASSWORD).</error>');
            return Command::FAILURE;
        }
        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if (!$user) {
                $user = $this->users->create($email, getenv('ADMIN_NAME') ?: 'Administrateur', $password, ['ROLE_ADMIN']);
                $out->writeln("Compte administrateur créé : $email");
            } else {
                $this->users->setPassword($user, $password);
                $user->setRoles(array_unique([...$user->getStoredRoles(), 'ROLE_ADMIN']))->setActive(true);
                $this->em->flush();
                $out->writeln("Compte $email réparé : actif, administrateur, mot de passe remplacé.");
            }
        } catch (\Throwable $e) {
            $out->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        // Aide au diagnostic : un « $ » dans .env est interprété par Docker Compose et raccourcit le mot de passe
        $out->writeln(sprintf('Mot de passe enregistré : %d caractères. Connectez-vous avec %s.', mb_strlen($password), $email));
        return Command::SUCCESS;
    }
}
