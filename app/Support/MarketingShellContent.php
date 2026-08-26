<?php

namespace App\Support;

/**
 * Slice of the full marketing-home content needed by every marketing
 * page's shared shell (header nav + footer). Centralises the resolve
 * step so pricing / how-it-works / privacy / terms all see the same nav.
 */
final class MarketingShellContent
{
    /**
     * @return array{
     *     nav_items: array<int, array{label: string, href: string}>,
     *     header: array{resources_label: string, resources_href: string, show_documentation: bool},
     *     footer: array<string, mixed>,
     * }
     */
    public static function resolve(?array $home = null): array
    {
        // Apply the external-docs rewrite here (render time) so every
        // shell-based page (pricing / how-it-works / privacy / terms /
        // integrations) repoints its doc links just like the home page.
        $home = MarketingHomeContent::applyExternalDocsUrl(
            $home ?? MarketingHomeContent::resolve(),
        );

        return [
            'nav_items' => $home['nav_items'] ?? [],
            'header' => [
                'resources_label' => $home['header']['resources_label'] ?? '',
                'resources_href' => $home['header']['resources_href'] ?? '/',
                // Carry the toggle through so the Documentation link shows
                // (or hides) consistently on pricing / how-it-works / etc —
                // before this the shell dropped the flag and the link only
                // ever appeared on the home page (buyer report).
                'show_documentation' => $home['header']['show_documentation'] ?? false,
            ],
            'footer' => $home['footer'] ?? [],
        ];
    }
}
