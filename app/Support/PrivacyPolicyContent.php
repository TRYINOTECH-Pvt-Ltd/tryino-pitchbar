<?php

namespace App\Support;

use Illuminate\Support\Str;

class PrivacyPolicyContent
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'eyebrow' => 'Privacy & GDPR',
            'title' => 'Privacy policy',
            'summary' => 'How Pitchbar collects, uses, stores, and deletes visitor and workspace data when the AI bar is installed on a site.',
            'effective_date' => '7 May 2026',
            'contact' => [
                'team_name' => 'Pitchbar Privacy Team',
                'email' => 'privacy@pitchbar.ai',
                'response_sla' => 'We respond to verified privacy requests within 30 days.',
            ],
            'collection' => [
                'summary' => 'We collect only the data needed to run the assistant, route leads, and keep the service secure.',
                'items' => [
                    'Conversation history between visitors and the assistant.',
                    'Anonymous visitor identifiers, hashed IP data, browser metadata, and page context for abuse prevention and analytics.',
                    'Lead details such as name, email, phone, and custom fields only when a visitor submits them deliberately.',
                ],
            ],
            'usage' => [
                'summary' => 'The data is used to answer visitors, notify your team about qualified leads, improve routing quality, and protect the service from misuse.',
                'items' => [
                    'Generate AI responses and maintain conversation context.',
                    'Route captured leads to inboxes, Slack, email, and outbound webhooks configured by the workspace.',
                    'Measure product performance, detect abuse, and troubleshoot delivery issues.',
                ],
            ],
            'retention' => [
                'summary' => 'Workspace data remains available until the workspace deletes it, exports it, or asks for removal through an authenticated privacy request.',
                'items' => [
                    'Visitors can remove their own widget conversation history using the in-widget deletion flow.',
                    'Lead routing logs and operational metadata may be retained for fraud prevention, billing, and auditability.',
                    'Backups age out on the platform retention schedule and are deleted automatically after their recovery window closes.',
                ],
            ],
            'rights' => [
                'summary' => 'Depending on your region, you may be entitled to request access, correction, export, restriction, or deletion of personal data.',
                'items' => [
                    'Request a copy of personal data associated with a visitor or workspace record.',
                    'Ask for correction or deletion of inaccurate or outdated information.',
                    'Withdraw consent for optional follow-up communication at any time.',
                ],
            ],
            'gdpr' => [
                'summary' => 'Pitchbar supports GDPR-aligned deletion workflows for widget conversations and manual review for broader export or erasure requests.',
                'request_email' => 'privacy@pitchbar.ai',
                'request_instructions' => 'Include the workspace name, the visitor identifier or email if known, and the request type so we can verify and fulfill it safely.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function resolve(mixed $content): array
    {
        $defaults = self::defaults();
        $resolved = array_replace_recursive($defaults, is_array($content) ? $content : []);

        $resolved['eyebrow'] = self::normalizeText($resolved['eyebrow'] ?? null, $defaults['eyebrow']);
        $resolved['title'] = self::normalizeText($resolved['title'] ?? null, $defaults['title']);
        $resolved['summary'] = self::normalizeParagraph($resolved['summary'] ?? null, $defaults['summary']);
        $resolved['effective_date'] = self::normalizeText($resolved['effective_date'] ?? null, $defaults['effective_date']);

        $resolved['contact']['team_name'] = self::normalizeText(
            data_get($resolved, 'contact.team_name'),
            data_get($defaults, 'contact.team_name'),
        );
        $resolved['contact']['email'] = self::normalizeText(
            data_get($resolved, 'contact.email'),
            data_get($defaults, 'contact.email'),
        );
        $resolved['contact']['response_sla'] = self::normalizeParagraph(
            data_get($resolved, 'contact.response_sla'),
            data_get($defaults, 'contact.response_sla'),
        );

        foreach (['collection', 'usage', 'retention', 'rights'] as $section) {
            $resolved[$section]['summary'] = self::normalizeParagraph(
                data_get($resolved, $section.'.summary'),
                data_get($defaults, $section.'.summary'),
            );
            $resolved[$section]['items'] = self::normalizeStringList(
                data_get($resolved, $section.'.items'),
                data_get($defaults, $section.'.items'),
            );
        }

        $resolved['gdpr']['summary'] = self::normalizeParagraph(
            data_get($resolved, 'gdpr.summary'),
            data_get($defaults, 'gdpr.summary'),
        );
        $resolved['gdpr']['request_email'] = self::normalizeText(
            data_get($resolved, 'gdpr.request_email'),
            data_get($defaults, 'gdpr.request_email'),
        );
        $resolved['gdpr']['request_instructions'] = self::normalizeParagraph(
            data_get($resolved, 'gdpr.request_instructions'),
            data_get($defaults, 'gdpr.request_instructions'),
        );

        return $resolved;
    }

    private static function normalizeText(mixed $value, string $fallback): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $text = trim($value);

        return $text !== '' ? $text : $fallback;
    }

    private static function normalizeParagraph(mixed $value, string $fallback): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", $value));

        return $text !== '' ? $text : $fallback;
    }

    /**
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    private static function normalizeStringList(mixed $value, array $fallback): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        $items = array_values(array_filter(array_map(
            static function (mixed $item): ?string {
                if (! is_string($item)) {
                    return null;
                }

                $normalized = Str::of($item)->trim()->squish()->toString();

                return $normalized !== '' ? $normalized : null;
            },
            $value,
        )));

        return $items !== [] ? array_slice($items, 0, 10) : $fallback;
    }
}
