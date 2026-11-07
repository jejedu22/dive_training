<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

abstract class ApiController extends AbstractController
{
    protected function body(Request $r): array
    {
        $d = json_decode($r->getContent() ?: '{}', true);
        if (!is_array($d)) throw new BadRequestHttpException('JSON invalide');
        return $d;
    }

    protected function date(mixed $v, string $field): \DateTimeImmutable
    {
        try {
            // Toujours en UTC : les colonnes sont « sans fuseau » et Doctrine écrit l'heure locale de l'objet.
            // Sans cela « 19:00+01:00 » serait stocké 19:00 puis relu 19:00 UTC (= 20:00 à Paris).
            return (new \DateTimeImmutable((string) $v))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new BadRequestHttpException("Date invalide : $field");
        }
    }

    protected function required(array $d, string ...$keys): void
    {
        foreach ($keys as $k) {
            if (!isset($d[$k]) || $d[$k] === '') throw new BadRequestHttpException("Champ requis : $k");
        }
    }
}
