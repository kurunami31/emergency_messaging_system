<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Middleware\AuthMiddleware;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

AuthMiddleware::requireAuth();

$hotlines = [
    [
        'category' => 'National Emergency',
        'agency' => 'National Emergency Hotline',
        'numbers' => ['911'],
        'icon' => 'phone'
    ],
    [
        'category' => 'Disaster Response',
        'agency' => 'Disaster Risk Reduction and Management Office (DRRMO)',
        'numbers' => ['3884-426', '0912-345-4666', '0927-763-0050'],
        'icon' => 'shield'
    ],
    [
        'category' => 'Medical Emergency',
        'agency' => 'CHO-EMS Emergency Medical Service',
        'numbers' => ['0951-840-9073', '0953-213-1206'],
        'icon' => 'activity'
    ],
    [
        'category' => 'Health',
        'agency' => 'City Health Office',
        'numbers' => ['3884-428', '3884-429', '0981-339-1408'],
        'icon' => 'heart'
    ],
    [
        'category' => 'Law Enforcement',
        'agency' => 'Mati City Police Station',
        'numbers' => ['0998-589-7122'],
        'icon' => 'shield'
    ],
    [
        'category' => 'Military',
        'agency' => '66th Infantry Battalion Philippine Army',
        'numbers' => ['0917-156-9461'],
        'icon' => 'shield'
    ],
    [
        'category' => 'Coast Guard',
        'agency' => 'Philippine Coast Guard-Davao Oriental Station',
        'numbers' => ['0966-837-0536'],
        'icon' => 'anchor'
    ],
    [
        'category' => 'Fire',
        'agency' => 'Mati Fire Station',
        'numbers' => ['160', '0951-812-6593', '0965-782-8090'],
        'icon' => 'flame'
    ],
    [
        'category' => 'Red Cross',
        'agency' => 'Philippine Red Cross-Davao Oriental Chapter',
        'numbers' => ['3884-022', '0965-084-9924', '0905-277-5186'],
        'icon' => 'heart'
    ],
    [
        'category' => 'Social Welfare',
        'agency' => 'City Social Welfare and Development Office',
        'numbers' => ['3883-326', '0975-358-2876'],
        'icon' => 'users'
    ],
    [
        'category' => 'EOD/K9',
        'agency' => 'EODK9 Unit PNP PECU Davao Oriental',
        'numbers' => ['0969-118-4775'],
        'icon' => 'shield'
    ]
];

echo json_encode(['hotlines' => $hotlines]);
