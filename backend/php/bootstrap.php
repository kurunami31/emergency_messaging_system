<?php

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/autoload.php';
}

\App\Config\App::getInstance();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function ($e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    exit;
});
