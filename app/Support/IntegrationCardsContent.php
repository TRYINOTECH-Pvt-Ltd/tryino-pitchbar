<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Resolves the four landing /integrations cards (Notion, Google Docs,
 * Slack, Stripe). Operators can rename + reword the cards via
 * /settings/system → Integration cards — the override is stored as
 * JSON on app_settings.integration_cards and overlaid on top of the
 * shipped defaults.
 *
 * Override semantics:
 *   - Missing override row → default card is rendered as-is.
 *   - Override row present → editable fields (name, category, tagline,
 *     description) replace the default; non-editable fields (icon,
 *     accent) are always taken from the default so an operator
 *     editing copy can't accidentally break the layout / styling.
 *   - Empty string in an override field → falls back to the default
 *     for that field. Lets the admin "clear" an edit.
 *
 * Cards are keyed by `name` (case-insensitive) so the migration to
 * admin overrides survives even if defaults are reordered later.
 *
 * @phpstan-type CardArray array{
 *     name: string,
 *     category: string,
 *     tagline: string,
 *     description: string,
 *     icon: string,
 *     accent: string,
 * }
 */
final class IntegrationCardsContent
{
    /**
     * Fields the admin form is allowed to override. Everything else
     * (icon, accent) stays controlled by the codebase.
     *
     * @var list<string>
     */
    public const EDITABLE_FIELDS = ['name', 'category', 'tagline', 'description'];

    /**
     * @return list<CardArray>
     */
    public static function defaults(): array
    {
        return [
            [
                'name' => 'Notion',
                'category' => 'Knowledge source',
                'tagline' => "Pipe your team's shared brain into the agent.",
                'description' => "OAuth into your workspace, pick the pages or databases you want indexed, and changes you make in Notion show up in the agent's answers next time you reindex.",
                'icon' => 'BookOpen',
                'accent' => 'slate',
            ],
            [
                'name' => 'Google Docs',
                'category' => 'Knowledge source',
                'tagline' => 'Sync product specs and FAQs the moment they ship.',
                'description' => 'Connect via OAuth and pick the docs that matter. Each document becomes a knowledge source — manual reindex on change, no scheduled polling against your Drive.',
                'icon' => 'FileText',
                'accent' => 'sky',
            ],
            [
                'name' => 'Slack',
                'category' => 'Notifications',
                'tagline' => 'Pipe leads + escalations to the channel that handles them.',
                'description' => 'Pick a channel; new captured leads, low-confidence escalations, and routed conversations land in real time so a human can jump in from where they already work.',
                'icon' => 'MessageSquare',
                'accent' => 'violet',
            ],
            [
                'name' => 'Stripe',
                'category' => 'Billing',
                'tagline' => 'Self-serve checkout and recurring billing.',
                'description' => 'Subscriptions, plan changes, and invoices flow through Stripe Checkout + the Customer Portal. Plans defined in the admin auto-sync to Stripe Products and Prices.',
                'icon' => 'CreditCard',
                'accent' => 'indigo',
            ],
        ];
    }

    /**
     * Resolved cards = defaults overlaid with admin overrides. Pass
     * `$overrides` to test in isolation; otherwise reads from
     * AppSetting::singleton()->integration_cards.
     *
     * @param  array<int, array<string, mixed>>|null  $overrides
     * @return list<CardArray>
     */
    public static function resolve(?array $overrides = null): array
    {
        if ($overrides === null) {
            $overrides = AppSetting::query()->find(AppSetting::SINGLETON_ID)?->integration_cards;
        }

        $byKey = self::indexByKey(is_array($overrides) ? $overrides : []);

        return array_map(static function (array $default) use ($byKey): array {
            $override = $byKey[self::keyFor($default['name'])] ?? null;
            if (! is_array($override)) {
                return $default;
            }

            foreach (self::EDITABLE_FIELDS as $field) {
                $value = $override[$field] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $default[$field] = $value;
                }
            }

            return $default;
        }, self::defaults());
    }

    /**
     * Pure projection of the resolved cards in the shape the admin
     * editor wants — only editable fields per card, keyed off the
     * DEFAULT card name so the form binding is stable even when the
     * admin overrode the visible name. Without this, an admin renaming
     * Stripe → "Stripe Pro" would change the row's React key from
     * `stripe` to `stripe_pro`, remount the input, and lose focus.
     *
     * @return list<array{key: string, name: string, category: string, tagline: string, description: string}>
     */
    public static function editorRows(): array
    {
        $defaults = self::defaults();
        $resolved = self::resolve();

        $rows = [];
        foreach ($defaults as $i => $default) {
            $card = $resolved[$i] ?? $default;
            $rows[] = [
                'key' => self::keyFor($default['name']),
                'name' => $card['name'],
                'category' => $card['category'],
                'tagline' => $card['tagline'],
                'description' => $card['description'],
            ];
        }

        return $rows;
    }

    /**
     * Normalise an admin-submitted override payload so each entry is
     * shaped `{key, name, category, tagline, description}` and only
     * cards that actually override a default are kept (anonymous
     * extra cards are discarded — UI doesn't expose adding cards).
     *
     * @param  array<int, array<string, mixed>>  $submitted
     * @return list<array{key: string, name: string, category: string, tagline: string, description: string}>
     */
    public static function normalise(array $submitted): array
    {
        $defaultKeys = array_map(
            static fn (array $card) => self::keyFor($card['name']),
            self::defaults(),
        );

        $rows = [];
        foreach ($submitted as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = is_string($row['key'] ?? null) ? $row['key'] : self::keyFor((string) ($row['name'] ?? ''));
            if (! in_array($key, $defaultKeys, true)) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'name' => is_string($row['name'] ?? null) ? trim($row['name']) : '',
                'category' => is_string($row['category'] ?? null) ? trim($row['category']) : '',
                'tagline' => is_string($row['tagline'] ?? null) ? trim($row['tagline']) : '',
                'description' => is_string($row['description'] ?? null) ? trim($row['description']) : '',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private static function indexByKey(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = is_string($row['name'] ?? null) ? $row['name'] : null;
            $key = is_string($row['key'] ?? null)
                ? $row['key']
                : ($name !== null ? self::keyFor($name) : null);
            if ($key === null) {
                continue;
            }
            $indexed[$key] = $row;
        }

        return $indexed;
    }

    private static function keyFor(string $name): string
    {
        return strtolower(trim($name));
    }
}
