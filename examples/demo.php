<?php

declare(strict_types=1);

use Kaly\Tpl\ViewEngine;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$tz = new \DateTimeZone('Europe/Brussels');

$appointments = (static function () use ($tz): \Generator {
    yield 'a-1' => [
        'patient' => 'Joséphine <script>',
        'startsAt' => new \DateTimeImmutable('2026-06-19 09:00', $tz),
        'status' => 'confirmed',
        'amount' => 42.5,
    ];
    yield 'a-2' => [
        'patient' => 'Stéphane',
        'startsAt' => new \DateTimeImmutable('2026-06-19 10:30', $tz),
        'status' => 'pending',
        'amount' => 65,
    ];
    yield 'a-3' => [
        'patient' => 'Béatrice',
        'startsAt' => new \DateTimeImmutable('2026-06-19 11:15', $tz),
        'status' => 'pending',
        'amount' => 80,
    ];
})();

$menu = [
    [
        'label' => 'Patients',
        'url' => '/patients',
        'children' => [
            ['label' => 'Nouveau patient', 'url' => '/patients/new', 'children' => []],
            ['label' => 'Importer', 'url' => '/patients/import', 'children' => []],
        ],
    ],
    [
        'label' => 'Agenda',
        'url' => '/agenda',
        'children' => [
            ['label' => 'Aujourd’hui', 'url' => '/agenda/today', 'children' => []],
        ],
    ],
];

$view = (new ViewEngine(__DIR__ . '/views'))
    ->debug(true)
    ->autoDocblock(false)
    ->locale('fr_BE', currency: 'EUR', timezone: 'Europe/Brussels')
    ->addGlobal('appName', 'Kaly Tpl Demo')
    ->addGlobal('menu', $menu);

echo
    $view->render(
        'appointments/index',
        [
            'appointments' => $appointments,
        ],
        layout: 'layouts/app',
    )
;
