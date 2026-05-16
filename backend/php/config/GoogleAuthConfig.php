<?php

namespace App\Config;

class GoogleAuthConfig
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct()
    {
        $this->clientId = getenv('GOOGLE_CLIENT_ID') ?: '';
        $this->clientSecret = getenv('GOOGLE_CLIENT_SECRET') ?: '';
        $this->redirectUri = getenv('GOOGLE_REDIRECT_URI') ?: 'http://127.0.0.1:8000/api/auth';
    }

    public function getClientId(): string { return $this->clientId; }
    public function getClientSecret(): string { return $this->clientSecret; }
    public function getRedirectUri(): string { return $this->redirectUri; }

    public function getAuthUrl(): string
    {
        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    public function exchangeCode(string $code): ?array
    {
        $url = 'https://oauth2.googleapis.com/token';
        $data = [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            error_log("Google token exchange failed: HTTP $httpCode - $response");
            return null;
        }

        return json_decode($response, true);
    }

    public function getUserInfo(string $accessToken): ?array
    {
        $url = 'https://www.googleapis.com/oauth2/v2/userinfo';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            error_log("Google user info fetch failed: HTTP $httpCode");
            return null;
        }

        return json_decode($response, true);
    }
}
