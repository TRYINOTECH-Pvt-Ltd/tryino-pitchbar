<?php

/*
 * Demo-mode configuration. Setting DEMO=YES (or true / 1) in .env
 * surfaces the seeded demo accounts on the login page as click-to-fill
 * pills so reviewers can sign in without hunting for credentials.
 *
 * Leave it OFF in production deployments — the credentials block is a
 * loud invitation to brute-force the demo account, and it accidentally
 * leaks the convention to anyone who lands on /login.
 */

$rawFlag = strtolower((string) env('DEMO', ''));
$enabled = in_array($rawFlag, ['yes', 'true', '1', 'on'], true);

return [
    'enabled' => $enabled,

    /*
     * Seeded demo accounts shown on the login page when demo mode is on.
     * Mirrors the rows produced by database/seeders/UserSeeder.php.
     */
    'credentials' => [
        [
            'role' => 'Customer',
            'email' => 'customer@mail.com',
            'password' => 'password',
            'description' => 'Workspace owner — agents, inbox, billing.',
        ],
        [
            'role' => 'Platform admin',
            'email' => 'admin@mail.com',
            'password' => 'password',
            'description' => 'Super-admin — plans, subscriptions, every workspace.',
        ],
    ],
];
