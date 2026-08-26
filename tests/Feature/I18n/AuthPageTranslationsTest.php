<?php

/**
 * The auth pages (login/register/reset/…) and their themed shells now
 * wrap their chrome in the translator. These strings must stay in the
 * source (en.json, so the manager + DeepL can reach them) and ship a
 * Dutch baseline (nl.json) so the sign-in flow is Dutch out of the box.
 * Regression guard: a refactor that drops the keys would silently revert
 * the auth pages to English on every locale.
 */
$authChromeKeys = [
    'Log in to your account',
    'Enter your email and password below to log in',
    'Create an account',
    'Forgot password',
    'Reset password',
    'Confirm your password',
    'Verify email',
    'Welcome back to the conversations.',
    'Stay connected',
    'Secure access',
    'Back to site',
    'Account access',
];

test('auth chrome strings are registered in the English source', function () use ($authChromeKeys) {
    $en = json_decode((string) file_get_contents(base_path('lang/en.json')), true);

    foreach ($authChromeKeys as $key) {
        expect($en)->toHaveKey($key);
    }
});

test('auth chrome strings ship a Dutch translation', function () use ($authChromeKeys) {
    $nl = json_decode((string) file_get_contents(base_path('lang/nl.json')), true);

    foreach ($authChromeKeys as $key) {
        expect($nl)->toHaveKey($key)
            ->and(trim((string) $nl[$key]))->not->toBe('');
    }
});
