<?php

namespace App\Services\Widget;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

class WidgetJwt
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttlMinutes = 60,
    ) {}

    /**
     * @param  array{wp_user_id:string, email_hash?:string, source:string}|null  $shopper  Optional CMS-resolved visitor identity (today: the WordPress plugin).
     * @return array{token: string, expires_at: int}
     */
    public function issue(string $agentId, string $visitorId, string $conversationId, ?array $shopper = null): array
    {
        $now = time();
        $exp = $now + ($this->ttlMinutes * 60);
        $payload = [
            'iss' => 'pitchbar',
            'iat' => $now,
            'exp' => $exp,
            'agent_id' => $agentId,
            'visitor_id' => $visitorId,
            'conversation_id' => $conversationId,
        ];

        if ($shopper !== null) {
            $payload['shopper'] = [
                'wp_user_id' => (string) ($shopper['wp_user_id'] ?? ''),
                'email_hash' => (string) ($shopper['email_hash'] ?? ''),
                'source' => (string) ($shopper['source'] ?? 'wordpress'),
            ];
        }

        return [
            'token' => JWT::encode($payload, $this->secret, 'HS256'),
            'expires_at' => $exp,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ExpiredException
     * @throws SignatureInvalidException
     */
    public function verify(string $token): array
    {
        $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));

        return (array) $decoded;
    }
}
