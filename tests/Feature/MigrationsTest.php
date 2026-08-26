<?php

use Illuminate\Support\Facades\File;

test('all migrations have a non-empty down() method', function () {
    $files = File::files(database_path('migrations'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = $file->getContents();

        $this->assertStringContainsString(
            'public function down(',
            $contents,
            "Missing down() in {$file->getFilename()}"
        );

        // down() body must not be empty.
        $emptyBody = preg_match(
            '/public function down\([^)]*\)\s*:?\s*\w*\s*\{\s*\}/',
            $contents
        );
        $this->assertSame(
            0,
            $emptyBody,
            "Empty down() in {$file->getFilename()}"
        );
    }
});
