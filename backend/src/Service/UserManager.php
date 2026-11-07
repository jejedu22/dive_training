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
