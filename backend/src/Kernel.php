<?php
namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Les dates sont stockées en UTC (colonnes sans fuseau) : ne pas dépendre du date.timezone de php.ini.
        date_default_timezone_set('UTC');
        parent::boot();
    }
}
