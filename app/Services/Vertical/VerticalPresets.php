<?php

namespace App\Services\Vertical;

/**
 * Single source of truth for the slug allow-list. Reused by validation
 * rules, the apply endpoint, and the registry. Kept as a value class so
 * the constant is stable and lintable as a static reference.
 */
final class VerticalPresets
{
    public const SLUGS = [
        'ecommerce',
        'documentation',
        'saas',
        'help_center',
        'marketing',
        'internal_kb',
        'generic',
    ];
}
