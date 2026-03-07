<?php

namespace App\Http\Controllers;

use App\Support\GoogleOAuthConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleOAuthController
{
    private const SCOPES = [
        'https://www.googleapis.com/auth/gmail.modify',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    public function auth(Request $request, string $system)
    {
        try {
            $config = GoogleOAuthConfig::fromSystem($system);
        } catch (\RuntimeException $e) {
            return $this->popupError('Configuration error: ' . $e->getMessage());
        }

        $state = Str::random(40);
        $request->session()->put("google_oauth_state_{$system}", $state);

        $params = [
            'client_id'              => $config['client_id'],
            'redirect_uri'           => $this->callbackUrl($system),
            'response_type'          => 'code',
            'scope'                  => implode(' ', self::SCOPES),
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ];

        if ($request->query('email')) {
            $params['login_hint'] = $request->query('email');
        }

        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

        return redirect($url);
    }

    public function callback(Request $request, string $system)
    {
        if ($request->has('error')) {
            return $this->popupError('Google returned an error: ' . htmlspecialchars((string) $request->query('error')));
        }

        $sessionKey   = "google_oauth_state_{$system}";
        $sessionState = $request->session()->pull($sessionKey);

        if (!$sessionState || $sessionState !== $request->query('state')) {
            return $this->popupError('Invalid OAuth state. Please try again.');
        }

        $code = $request->query('code');
        if (!$code) {
            return $this->popupError('No authorization code received.');
        }

        try {
            $config = GoogleOAuthConfig::fromSystem($system);
        } catch (\RuntimeException $e) {
            return $this->popupError('Configuration error: ' . $e->getMessage());
        }

        $tokenResponse = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri'  => $this->callbackUrl($system),
        ]);

        if ($tokenResponse->failed()) {
            return $this->popupError('Token exchange failed: ' . $tokenResponse->body());
        }

        $tokenData = $tokenResponse->json();

        if (empty($tokenData['refresh_token'])) {
            return $this->popupError(
                "No refresh_token received from Google. " .
                "This usually means you already authorized this app. " .
                "Go to https://myaccount.google.com/permissions, revoke access, then try again."
            );
        }

        $profileResponse = Http::withToken($tokenData['access_token'])
            ->timeout(15)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/profile');

        if ($profileResponse->failed()) {
            return $this->popupError('Failed to fetch Gmail profile: ' . $profileResponse->body());
        }

        $subjectEmail = $profileResponse->json('emailAddress');
        if (!$subjectEmail) {
            return $this->popupError('Could not determine email address from Gmail profile.');
        }

        $scopes         = implode(' ', self::SCOPES);
        $encryptedToken = Crypt::encryptString($tokenData['refresh_token']);
        $now            = now();

        $existing = DB::table('oauth_google_tokens')
            ->where('system', $system)
            ->where('subject_email', $subjectEmail)
            ->first();

        if ($existing) {
            DB::table('oauth_google_tokens')
                ->where('id', $existing->id)
                ->update([
                    'scopes'        => $scopes,
                    'refresh_token' => $encryptedToken,
                    'payload_json'  => json_encode($tokenData),
                    'updated_at'    => $now,
                ]);
        } else {
            DB::table('oauth_google_tokens')->insert([
                'system'        => $system,
                'subject_email' => $subjectEmail,
                'scopes'        => $scopes,
                'refresh_token' => $encryptedToken,
                'payload_json'  => json_encode($tokenData),
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        return $this->popupSuccess($subjectEmail);
    }

    // -------------------------------------------------------------------------

    private function callbackUrl(string $system): string
    {
        return url('/google/callback/' . $system);
    }

    private function popupSuccess(string $email): \Illuminate\Http\Response
    {
        $safeEmail = htmlspecialchars($email, ENT_QUOTES);

        return response(<<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <title>Authorized</title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                       background: #0d1117; color: #e6edf3;
                       display: flex; align-items: center; justify-content: center;
                       min-height: 100vh; padding: 24px; }
                .card { background: #161b22; border: 1px solid #30363d; border-radius: 12px;
                        padding: 32px; max-width: 420px; width: 100%; text-align: center; }
                .icon { font-size: 48px; margin-bottom: 16px; }
                h1 { font-size: 18px; font-weight: 600; color: #3fb950; margin-bottom: 8px; }
                p { font-size: 13px; color: #8b949e; line-height: 1.5; }
                .email { color: #e6edf3; font-weight: 500; }
            </style>
        </head>
        <body>
            <div class="card">
                <div class="icon">✓</div>
                <h1>Authorization successful</h1>
                <p>Logged in as <span class="email">{$safeEmail}</span>.<br>This window will close automatically.</p>
            </div>
            <script>
                if (window.opener && !window.opener.closed) {
                    window.opener.postMessage({ type: 'oauth_success', email: {$this->jsString($email)} }, window.location.origin);
                }
                setTimeout(() => window.close(), 1500);
            </script>
        </body>
        </html>
        HTML);
    }

    private function popupError(string $message): \Illuminate\Http\Response
    {
        $safeMessage = htmlspecialchars($message, ENT_QUOTES);

        return response(<<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <title>Authorization Failed</title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                       background: #0d1117; color: #e6edf3;
                       display: flex; align-items: center; justify-content: center;
                       min-height: 100vh; padding: 24px; }
                .card { background: #161b22; border: 1px solid #30363d; border-radius: 12px;
                        padding: 32px; max-width: 420px; width: 100%; text-align: center; }
                .icon { font-size: 48px; margin-bottom: 16px; }
                h1 { font-size: 18px; font-weight: 600; color: #f85149; margin-bottom: 8px; }
                p { font-size: 13px; color: #8b949e; line-height: 1.5; word-break: break-word; }
                button { margin-top: 20px; background: #21262d; border: 1px solid #30363d;
                         color: #e6edf3; border-radius: 6px; padding: 8px 16px;
                         font-size: 13px; cursor: pointer; }
                button:hover { background: #30363d; }
            </style>
        </head>
        <body>
            <div class="card">
                <div class="icon">✗</div>
                <h1>Authorization failed</h1>
                <p>{$safeMessage}</p>
                <button onclick="window.close()">Close</button>
            </div>
            <script>
                if (window.opener && !window.opener.closed) {
                    window.opener.postMessage({ type: 'oauth_error', message: {$this->jsString($message)} }, window.location.origin);
                }
            </script>
        </body>
        </html>
        HTML, 400);
    }

    private function jsString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
