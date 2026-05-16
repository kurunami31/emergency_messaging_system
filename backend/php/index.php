<?php

require_once __DIR__ . '/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$requestUri = $_SERVER['REQUEST_URI'];
$basePath = '/api';
$path = parse_url($requestUri, PHP_URL_PATH);

if (strpos($path, $basePath) !== 0) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
    exit;
}

$route = substr($path, strlen($basePath));
$route = '/' . trim($route, '/');

$routes = [
    '/auth' => __DIR__ . '/api/auth.php',
    '/events' => __DIR__ . '/api/events.php',
    '/messages' => __DIR__ . '/api/messages.php',
    '/alerts' => __DIR__ . '/api/alerts.php',
    '/users' => __DIR__ . '/api/users.php',
    '/distress' => __DIR__ . '/api/distress.php',
    '/chat' => __DIR__ . '/api/chat.php',
    '/hotlines' => __DIR__ . '/api/hotlines.php',
    '/system' => __DIR__ . '/api/system.php',
];

$matched = false;
foreach ($routes as $prefix => $file) {
    if (strpos($route, $prefix) === 0) {
        require $file;
        $matched = true;
        break;
    }
}

if (!$matched) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Route not found', 'route' => $route]);
}
