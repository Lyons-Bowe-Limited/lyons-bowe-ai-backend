<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

class GraphMailService
{
    private function accessToken(): string
    {
        $tenantId = config('services.graph.tenant_id');
        $clientId = config('services.graph.client_id');
        $certPath = config('services.graph.cert_path');

        if (! str_starts_with($certPath, '/')) {
            $certPath = base_path($certPath);
        }

        $pem = file_get_contents($certPath);

        if ($pem === false) {
            throw new \Exception("Could not read Graph certificate at: {$certPath}");
        }

        preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $pem, $matches);

        if (empty($matches[0])) {
            throw new \Exception('Certificate block not found in graph.pem');
        }

        $certificate = $matches[0];

        $thumbprint = openssl_x509_fingerprint($certificate, 'sha1', true);

        if ($thumbprint === false) {
            throw new \Exception('Could not generate certificate thumbprint');
        }

        $x5t = rtrim(strtr(base64_encode($thumbprint), '+/', '-_'), '=');

        $now = time();

        $clientAssertion = JWT::encode([
            'aud' => "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
            'iss' => $clientId,
            'sub' => $clientId,
            'jti' => bin2hex(random_bytes(16)),
            'nbf' => $now,
            'exp' => $now + 600,
        ], $pem, 'RS256', null, [
            'x5t' => $x5t,
        ]);

        $response = Http::asForm()->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
            'client_id' => $clientId,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $clientAssertion,
        ]);

        if ($response->failed()) {
            throw new \Exception('Graph token failed: ' . $response->body());
        }

        return $response->json('access_token');
    }

    public function sendMail($to, $subject, $body)
    {
        $token = $this->getAccessToken();

        $recipients = collect($to)->map(fn ($email) => [
            "emailAddress" => ["address" => $email]
        ])->toArray();

        $email = [
            "message" => [
                "subject" => $subject,
                "body" => [
                    "contentType" => "HTML",
                    "content" => $body
                ],
                "toRecipients" => $recipients
            ]
        ];

        $response = Http::withToken($token)
            ->post("https://graph.microsoft.com/v1.0/users/{$this->mailbox}/sendMail", $email);

        \Log::info('GRAPH SEND RESPONSE', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
    }
}