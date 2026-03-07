<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

class GmailTokenProvider
{
    /**
     * Exchange a refresh token for a fresh access token.
     */
    public static function getAccessToken(string $system, string $refreshToken): string
    {
        $config = GoogleOAuthConfig::fromSystem($system);

        $response = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
            'grant_type'    => 'refresh_token',
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                "Failed to refresh Google access token: " . $response->body()
            );
        }

        $data = $response->json();

        if (empty($data['access_token'])) {
            throw new \RuntimeException(
                "No access_token in token refresh response: " . $response->body()
            );
        }

        return $data['access_token'];
    }
}
