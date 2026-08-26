<?php

use Symfony\Component\Finder\Finder;

/*
 * Static guard: the admin/customer Inertia frontend must never call the
 * browser's native window.alert() / window.confirm() / window.prompt().
 * They block the JS thread, can't be styled, and don't translate. We
 * ship shadcn dialogs backed by `useAlert()`, `useConfirm()`, and
 * `usePrompt()` instead. Card #63 closed the alert/confirm rollout;
 * card #64 closed the last remaining prompt() at
 * resources/js/pages/app/agents/curated.tsx:319 (now :321 after the
 * await refactor).
 *
 * This test reads every page/component/hook under resources/js and
 * fails loudly if anyone re-introduces a native dialog.
 */

test('no native window.alert / window.confirm / window.prompt in resources/js', function () {
    $finder = (new Finder)
        ->in(base_path('resources/js'))
        ->files()
        ->name(['*.ts', '*.tsx']);

    $hits = [];

    foreach ($finder as $file) {
        $path = $file->getRelativePathname();
        $contents = $file->getContents();
        $lines = explode("\n", $contents);

        foreach ($lines as $i => $line) {
            // Quick filter: skip obvious non-matches before regex.
            if (
                strpos($line, 'alert(') === false
                && strpos($line, 'confirm(') === false
                && strpos($line, 'prompt(') === false
            ) {
                continue;
            }

            // Skip comment lines — JSDoc / block / line comments
            // routinely mention `window.confirm()` etc. without being
            // actual calls. Trimmed prefix must start with `*`, `//`,
            // or `/*` to be a comment.
            $trimmed = ltrim($line);
            if (
                str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '/*')
            ) {
                continue;
            }

            // Match window-scoped calls only — bare `confirm({...})`
            // is the shadcn hook callsite pattern.
            if (preg_match('/\bwindow\.(alert|confirm|prompt)\s*\(/', $line) === 1) {
                $hits[] = "{$path}:".($i + 1).' — '.trim($line);

                continue;
            }
        }
    }

    expect($hits)->toBeEmpty(
        "Native browser dialogs found (use the shadcn useAlert/useConfirm/usePrompt hooks instead):\n  - "
        .implode("\n  - ", $hits),
    );
});

test('useConfirm / useAlert / usePrompt are exported from confirm-dialog-provider', function () {
    $contents = file_get_contents(resource_path('js/components/confirm-dialog-provider.tsx'));
    expect($contents)->toContain('export function useConfirm()');
    expect($contents)->toContain('export function useAlert()');
    expect($contents)->toContain('export function usePrompt()');
});
