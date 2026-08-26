<?php

namespace Pitchbar\Sync;

use Pitchbar\Admin\SyncStatusColumn;
use Pitchbar\Api\PitchbarClient;
use Pitchbar\Plugin;
use Pitchbar\Settings\SettingsPage;
use Pitchbar\Support\Logger;
use WP_Post;
use WP_Query;

/**
 * Full-site sync. Paginates `WP_Query` in batches of 50 posts across
 * the post types the admin opted into, POSTing each page to
 * `/v1/wp/posts/sync`.
 *
 * Resumable: shared hosting commonly enforces a 30s execution cap. We
 * abort the loop when we've burned `TIME_BUDGET_SECONDS` of wall clock
 * and stash the next page in a transient. The Settings page's
 * scheduled-sync hook picks it up 30s later (WP-Cron) without losing
 * progress. Re-running "Sync now" simply resumes from where we left
 * off, since each batch is idempotent on `external_id`.
 */
final class PostSyncer
{
    public const BATCH_SIZE = 50;

    public const TIME_BUDGET_SECONDS = 20;

    public const RESUME_TRANSIENT = 'pitchbar_post_sync_resume';

    /** @var PostContentExtractor */
    private $extractor;

    /** @var int|null */
    private $startedAt = null;

    public function __construct(?PostContentExtractor $extractor = null)
    {
        $this->extractor = $extractor ?? new PostContentExtractor;
    }

    /**
     * @return array{ok: bool, batches: int, posts: int, queued: int, skipped: int, resumed: bool, more: bool, next_page: int|null, errors: list<string>}
     */
    public function runFullSync(): array
    {
        $plugin = Plugin::instance();
        if (! $plugin->isConfigured()) {
            return $this->failure(__('Plugin is not configured.', 'pitchbar'));
        }

        $client = new PitchbarClient(
            (string) $plugin->setting('base_url'),
            (string) $plugin->setting('api_token')
        );
        $agentId = (string) $plugin->setting('agent_id');
        $postTypes = (array) $plugin->setting('enabled_post_types', ['post', 'page']);
        $siteUrl = home_url('/');

        $startPage = $this->resolveStartPage();
        $resumed = $startPage > 1;
        $page = $startPage;
        $batches = 0;
        $totalPosts = 0;
        $totalQueued = 0;
        $totalSkipped = 0;
        $errors = [];
        $more = false;
        $nextPage = null;
        $maxPages = 0;

        $this->startedAt = $this->now();

        do {
            $query = new WP_Query([
                'post_type' => $postTypes,
                'post_status' => 'publish',
                'posts_per_page' => self::BATCH_SIZE,
                'paged' => $page,
                'no_found_rows' => false,
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);

            $maxPages = max($maxPages, (int) $query->max_num_pages);
            if (! $query->have_posts()) {
                break;
            }

            $batch = [];
            foreach ($query->posts as $post) {
                if (! $post instanceof WP_Post) {
                    continue;
                }
                $batch[] = $this->extractor->extract($post);
            }
            wp_reset_postdata();

            if ($batch === []) {
                break;
            }

            $result = $client->postsSync([
                'agent_id' => $agentId,
                'site_url' => $siteUrl,
                'plugin_version' => PITCHBAR_PLUGIN_VERSION,
                'posts' => $batch,
            ]);

            $batches++;
            $totalPosts += count($batch);

            if (! $result['ok']) {
                $errors[] = sprintf(
                    /* translators: 1: page number, 2: error message */
                    __('Batch %1$d failed: %2$s', 'pitchbar'),
                    $page,
                    (string) ($result['error'] ?? __('unknown error', 'pitchbar'))
                );
                Logger::warn('PostSyncer batch failed', ['page' => $page, 'error' => $result['error']]);
            } else {
                $totalQueued += (int) ($result['data']['queued'] ?? 0);
                $totalSkipped += (int) ($result['data']['skipped_unchanged'] ?? 0);
                // Stamp the sync timestamp + content hash on each post
                // so the admin list table can render an "Indexed" badge.
                // Hash is the same one we sent server-side, lets the
                // column flag "Out of date" when post_modified_gmt
                // advances past synced_at.
                $now = time();
                foreach ($batch as $entry) {
                    $wpId = (int) ($entry['wp_id'] ?? 0);
                    if ($wpId > 0) {
                        update_post_meta($wpId, SyncStatusColumn::META_SYNCED_AT, $now);
                        update_post_meta($wpId, SyncStatusColumn::META_SYNCED_HASH, (string) ($entry['content_hash'] ?? ''));
                    }
                }
            }

            $page++;

            // Hard time guard. If we've crossed the budget AND there
            // are still pages to process, persist resume state and
            // bail. The follow-up cron event finishes the job.
            if ($this->budgetExceeded() && $page <= $maxPages) {
                $more = true;
                $nextPage = $page;
                $this->persistResume($nextPage);
                $this->scheduleResume();
                break;
            }
        } while ($page <= $maxPages);

        // If we ran to completion, clear any stale resume marker.
        if (! $more) {
            $this->clearResume();
        }

        return [
            'ok' => $errors === [],
            'batches' => $batches,
            'posts' => $totalPosts,
            'queued' => $totalQueued,
            'skipped' => $totalSkipped,
            'resumed' => $resumed,
            'more' => $more,
            'next_page' => $nextPage,
            'errors' => $errors,
        ];
    }

    private function resolveStartPage(): int
    {
        if (! function_exists('get_transient')) {
            return 1;
        }
        $stored = get_transient(self::RESUME_TRANSIENT);
        if (! is_array($stored) || ! isset($stored['page'])) {
            return 1;
        }
        $page = (int) $stored['page'];

        return $page > 1 ? $page : 1;
    }

    private function persistResume(int $page): void
    {
        if (! function_exists('set_transient')) {
            return;
        }
        set_transient(self::RESUME_TRANSIENT, ['page' => $page, 'at' => time()], HOUR_IN_SECONDS);
    }

    private function clearResume(): void
    {
        if (! function_exists('delete_transient')) {
            return;
        }
        delete_transient(self::RESUME_TRANSIENT);
    }

    private function scheduleResume(): void
    {
        if (! function_exists('wp_schedule_single_event') || ! function_exists('wp_next_scheduled')) {
            return;
        }
        $hook = SettingsPage::SCHEDULED_SYNC_HOOK;
        if (wp_next_scheduled($hook)) {
            return;
        }
        wp_schedule_single_event(time() + 30, $hook);
    }

    private function budgetExceeded(): bool
    {
        if ($this->startedAt === null) {
            return false;
        }

        return ($this->now() - $this->startedAt) >= self::TIME_BUDGET_SECONDS;
    }

    private function now(): int
    {
        return time();
    }

    /**
     * @return array{ok: bool, batches: int, posts: int, queued: int, skipped: int, resumed: bool, more: bool, next_page: int|null, errors: list<string>}
     */
    private function failure(string $error): array
    {
        return [
            'ok' => false,
            'batches' => 0,
            'posts' => 0,
            'queued' => 0,
            'skipped' => 0,
            'resumed' => false,
            'more' => false,
            'next_page' => null,
            'errors' => [$error],
        ];
    }
}
