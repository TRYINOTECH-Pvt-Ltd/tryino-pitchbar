<?php

use Illuminate\Support\Facades\File;

test('pitchbar:build-wp-plugin produces a zip whose root contains pitchbar.php', function () {
    $output = sys_get_temp_dir().'/pitchbar-build-test-'.uniqid();
    File::ensureDirectoryExists($output);

    try {
        $this->artisan('pitchbar:build-wp-plugin', ['--output' => $output])
            ->assertSuccessful();

        $files = File::files($output);
        expect($files)->toHaveCount(1);

        $zipPath = $files[0]->getPathname();
        expect(filesize($zipPath))->toBeGreaterThan(0);

        $zip = new ZipArchive;
        expect($zip->open($zipPath))->toBeTrue();

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        expect($names)->toContain('pitchbar/pitchbar.php');
        expect($names)->toContain('pitchbar/src/Plugin.php');
        expect($names)->toContain('pitchbar/src/Sync/PostSyncer.php');
        expect($names)->toContain('pitchbar/assets/admin.js');
    } finally {
        File::deleteDirectory($output);
    }
});

test('pitchbar:build-wp-plugin excludes dev-only paths from the archive', function () {
    $output = sys_get_temp_dir().'/pitchbar-build-test-'.uniqid();
    File::ensureDirectoryExists($output);

    try {
        $this->artisan('pitchbar:build-wp-plugin', ['--output' => $output])
            ->assertSuccessful();

        $files = File::files($output);
        $zip = new ZipArchive;
        $zip->open($files[0]->getPathname());

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            expect(str_starts_with($name, 'pitchbar/'))->toBeTrue();
            expect($name)->not()->toContain('.DS_Store');
            expect($name)->not()->toContain('node_modules/');
            expect($name)->not()->toContain('.original.md');
        }

        $zip->close();
    } finally {
        File::deleteDirectory($output);
    }
});
