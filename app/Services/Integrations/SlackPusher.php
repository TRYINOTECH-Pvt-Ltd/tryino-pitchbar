<?php

namespace App\Services\Integrations;

use App\Models\Lead;
use GuzzleHttp\Client as Guzzle;

class SlackPusher
{
    public function __construct(private readonly Guzzle $http = new Guzzle(['timeout' => 5])) {}

    public function pushLead(string $webhookUrl, Lead $lead): bool
    {
        $payload = [
            'text' => "New lead captured: {$lead->email}",
            'blocks' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => '🎯 New lead']],
                ['type' => 'section', 'fields' => [
                    ['type' => 'mrkdwn', 'text' => "*Email:* {$lead->email}"],
                    ['type' => 'mrkdwn', 'text' => '*Name:* '.($lead->name ?? '—')],
                    ['type' => 'mrkdwn', 'text' => '*Phone:* '.($lead->phone ?? '—')],
                ]],
            ],
        ];

        try {
            $code = $this->http->post($webhookUrl, ['json' => $payload, 'http_errors' => false])->getStatusCode();

            return $code < 400;
        } catch (\Throwable) {
            return false;
        }
    }
}
