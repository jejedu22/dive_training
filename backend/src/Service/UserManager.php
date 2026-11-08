<?php
namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Création / modification des comptes encadrants, partagée par l'API et les commandes. */
final class UserManager
{
    public const MIN_PASSWORD = 8;

    public function __construct(private EntityManagerInterface $em, private UserPasswordHasherInterface $hasher) {}

    public function create(string $email, string $name, string $password, array $roles = []): User
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new BadRequestHttpException('E-mail invalide');
        if (trim($name) === '') throw new BadRequestHttpException('Nom requis');
        if ($this->em->getRepository(User::class)->findOneBy(['email' => $email])) {
            throw new ConflictHttpException('Un compte existe déjà avec cet e-mail');
        }
        $user = (new User())->setEmail($email)->setName(trim($name))->setRoles($roles);
        $this->setPassword($user, $password);
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    public function setPassword(User $user, string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new BadRequestHttpException(sprintf('Mot de passe trop court (%d caractères minimum)', self::MIN_PASSWORD));
        }
        $user->setPassword($this->hasher->hashPassword($user, $password));
    }

    /**
     * Met à jour le profil (nom, e-mail, téléphone, couleur, préférences) à partir des champs présents.
     * @return bool true si l'e-mail a changé (le jeton de connexion doit alors être renouvelé)
     */
    public function updateProfile(User $user, array $d): bool
    {
        if (array_key_exists('name', $d)) {
            $name = trim((string) $d['name']);
            if ($name === '' || mb_strlen($name) > 120) throw new BadRequestHttpException('Nom requis (120 caractères maximum)');
            $user->setName($name);
        }
        $emailChanged = false;
        if (array_key_exists('email', $d)) {
            $email = mb_strtolower(trim((string) $d['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new BadRequestHttpException('E-mail invalide');
            if ($email !== $user->getEmail()) {
                $other = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($other && $other !== $user) throw new ConflictHttpException('Un compte existe déjà avec cet e-mail');
                $user->setEmail($email);
                $emailChanged = true;
            }
        }
        if (array_key_exists('phone', $d)) {
            $phone = $d['phone'] === null ? null : trim((string) $d['phone']);
            if ($phone !== null && $phone !== '' && !preg_match('/^[0-9+().\s-]{6,30}$/', $phone)) throw new BadRequestHttpException('Téléphone invalide');
            $user->setPhone($phone);
        }
        if (array_key_exists('avatarColor', $d)) {
            $c = $d['avatarColor'];
            if ($c !== null && (!is_string($c) || !preg_match('/^#[0-9a-fA-F]{6}$/', $c))) throw new BadRequestHttpException('Couleur invalide (format #rrggbb)');
            $user->setAvatarColor($c);
        }
        if (array_key_exists('preferences', $d)) {
            if (!is_array($d['preferences'])) throw new BadRequestHttpException('Préférences invalides');
            $prefs = $user->getPreferences();
            foreach ($d['preferences'] as $key => $value) {
                if (!array_key_exists($key, User::PREFERENCES)) throw new BadRequestHttpException("Préférence inconnue : $key");
                if (!in_array($value, User::PREFERENCES[$key], true)) throw new BadRequestHttpException("Valeur invalide pour $key");
                $prefs[$key] = $value;
            }
            $user->setPreferences($prefs);
        }
        return $emailChanged;
    }

    public function isPasswordValid(User $user, string $password): bool
    {
        return $this->hasher->isPasswordValid($user, $password);
    }

    public function count(): int
    {
        return $this->em->getRepository(User::class)->count([]);
    }

    public function countActiveAdmins(): int
    {
        return count(array_filter($this->em->getRepository(User::class)->findBy(['active' => true]), fn (User $u) => $u->isAdmin()));
    }
}
