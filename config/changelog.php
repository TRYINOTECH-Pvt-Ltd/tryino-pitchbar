<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Changelog bootstrap directory
    |--------------------------------------------------------------------------
    |
    | The ChangelogStore auto-seeds entries from markdown files in this
    | directory the first time the JSON file at
    | storage/app/private/changelog-entries.json is read. New versions
    | are inserted; existing entries are never overwritten — admin
    | edits via the UI are the source of truth post-bootstrap.
    |
    | Set this to an empty string to disable the bootstrap entirely
    | (useful when you want to start with an empty changelog and
    | author your own entries through /admin/changelog).
    |
    */
    'bootstrap_dir' => env(
        'CHANGELOG_BOOTSTRAP_DIR',
        base_path('database/changelog-entries'),
    ),
];
