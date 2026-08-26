<?php

namespace Pitchbar\Admin;

use Pitchbar\Plugin;

/**
 * Adds a "Pitchbar" column to the Posts, Pages, and (when WooCommerce
 * is active) Products admin list tables. Each row shows whether the
 * post / product has been synced to Pitchbar yet — based on the
 * `_pitchbar_synced_at` meta the syncers stamp on every successful
 * POST. Buyer-requested affordance so admins can see at a glance
 * which entries are searchable by the agent and which are still
 * pending a sync run.
 */
final class SyncStatusColumn
{
    public const META_SYNCED_AT = '_pitchbar_synced_at';

    public const META_SYNCED_HASH = '_pitchbar_synced_hash';

    public const COLUMN_KEY = 'pitchbar_indexed';

    public function register(): void
    {
        if (! function_exists('add_action') || ! function_exists('add_filter')) {
            return;
        }

        // Register columns for every public post-type the admin can
        // edit. Hook fires once per post type list page.
        add_action('admin_init', [$this, 'registerColumns']);

        // Force a fixed width on the Pitchbar column. WP admin's
        // automatic column-width algorithm gives every TH an equal
        // share of the row, which on a wide screen makes the header
        // word `Pitchbar` fit fine — but on the Products screen
        // (where Brand / Date / Stock / SKU all compete) the column
        // wraps to <80px and renders one letter per line. A fixed
        // 110px on the column TH stops the vertical wrap.
        add_action('admin_head', [$this, 'printColumnStyles']);
    }

    public function printColumnStyles(): void
    {
        echo '<style>'.
            '.wp-list-table th.column-'.self::COLUMN_KEY.','.
            '.wp-list-table td.column-'.self::COLUMN_KEY.'{'.
            'width:110px;min-width:110px;white-space:nowrap;'.
            '}'.
            '</style>';
    }

    public function registerColumns(): void
    {
        $postTypes = array_unique(array_merge(
            ['post', 'page', 'product'],
            (array) (Plugin::instance()->setting('enabled_post_types', []) ?: []),
        ));

        foreach ($postTypes as $postType) {
            if (! is_string($postType) || $postType === '') {
                continue;
            }
            add_filter('manage_'.$postType.'_posts_columns', [$this, 'addColumn']);
            add_action('manage_'.$postType.'_posts_custom_column', [$this, 'renderCell'], 10, 2);
        }
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, string>
     */
    public function addColumn(array $columns): array
    {
        // Insert before the date column so the badge sits near the
        // status/title area rather than getting pushed off the end.
        $insertBefore = 'date';
        if (! array_key_exists($insertBefore, $columns)) {
            $columns[self::COLUMN_KEY] = __('Pitchbar', 'pitchbar');

            return $columns;
        }

        $out = [];
        foreach ($columns as $key => $label) {
            if ($key === $insertBefore) {
                $out[self::COLUMN_KEY] = __('Pitchbar', 'pitchbar');
            }
            $out[$key] = $label;
        }

        return $out;
    }

    public function renderCell(string $column, int $postId): void
    {
        if ($column !== self::COLUMN_KEY) {
            return;
        }

        $syncedAt = (int) get_post_meta($postId, self::META_SYNCED_AT, true);
        if ($syncedAt <= 0) {
            echo '<span style="display:inline-block;padding:2px 6px;border-radius:3px;background:#f0f0f1;color:#646970;font-size:11px;">'
                .esc_html__('Not indexed', 'pitchbar').'</span>';

            return;
        }

        $syncedHash = (string) get_post_meta($postId, self::META_SYNCED_HASH, true);
        $stale = $this->isStale($postId, $syncedHash);

        if ($stale) {
            echo '<span title="'.esc_attr__('Indexed but content changed since the last sync.', 'pitchbar')
                .'" style="display:inline-block;padding:2px 6px;border-radius:3px;background:#fcf9e8;color:#8a6914;font-size:11px;">'
                .esc_html__('Out of date', 'pitchbar').'</span>';

            return;
        }

        $human = human_time_diff($syncedAt, time());
        echo '<span title="'.esc_attr(sprintf(
            /* translators: %s: human-readable time difference */
            __('Synced %s ago', 'pitchbar'),
            $human,
        )).'" style="display:inline-block;padding:2px 6px;border-radius:3px;background:#e7f5ea;color:#0a6b2c;font-size:11px;">'
            .esc_html__('Indexed', 'pitchbar').'</span>';
    }

    /**
     * "Out of date" = synced once, but `post_modified_gmt` is newer
     * than the stamped sync timestamp. Heuristic — covers the common
     * "I edited the post but didn't re-run sync" case without needing
     * the agent to compare content_hash.
     */
    private function isStale(int $postId, string $syncedHash): bool
    {
        $modified = get_post_field('post_modified_gmt', $postId);
        if (! is_string($modified) || $modified === '') {
            return false;
        }
        $modifiedTs = strtotime($modified.' UTC');
        if ($modifiedTs === false) {
            return false;
        }
        $syncedAt = (int) get_post_meta($postId, self::META_SYNCED_AT, true);

        return $modifiedTs > $syncedAt;
    }
}
