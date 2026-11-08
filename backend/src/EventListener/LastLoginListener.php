<?php
namespace App\EventListener;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Enregistre la date de dernière connexion (affichée dans la gestion des comptes). */
#[AsEventListener(event: 'lexik_jwt_authentication.on_authentication_success')]
final class LastLoginListener
{
    public function __construct(private EntityManagerInterface $em) {}

    public function __invoke(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) return;
        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->em->flush();
    }
}
