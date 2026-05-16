<?php

namespace App\Config;

class RabbitMQConfig
{
    private string $host;
    private int $port;
    private string $user;
    private string $password;
    private string $vhost;

    public function __construct()
    {
        $this->host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $this->port = (int)(getenv('RABBITMQ_PORT') ?: 5672);
        $this->user = getenv('RABBITMQ_USER') ?: 'guest';
        $this->password = getenv('RABBITMQ_PASS') ?: 'guest';
        $this->vhost = getenv('RABBITMQ_VHOST') ?: '/';
    }

    public function getHost(): string { return $this->host; }
    public function getPort(): int { return $this->port; }
    public function getUser(): string { return $this->user; }
    public function getPassword(): string { return $this->password; }
    public function getVhost(): string { return $this->vhost; }
    public function getEmergencyExchange(): string { return 'emergency_exchange'; }
    public function getMessageQueue(): string { return 'emergency_messages'; }
    public function getAlertQueue(): string { return 'emergency_alerts'; }
}
