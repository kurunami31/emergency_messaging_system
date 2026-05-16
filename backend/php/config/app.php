<?php

namespace App\Config;

class App
{
    private static ?App $instance = null;
    private array $config = [];

    private function __construct()
    {
        $this->load();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function load(): void
    {
        $envPath = __DIR__ . '/../.env';
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '#') === 0) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    putenv("$key=$value");
                    $this->config[$key] = $value;
                }
            }
        }

        $vars = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'RABBITMQ_HOST'];
        foreach ($vars as $var) {
            if (!isset($this->config[$var])) {
                $this->config[$var] = getenv($var) ?: '';
            }
        }
    }

    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function getAll(): array
    {
        return $this->config;
    }

    private function __clone() {}
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
