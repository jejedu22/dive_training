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
            return new \DateTimeImmutable((string) $v);
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
