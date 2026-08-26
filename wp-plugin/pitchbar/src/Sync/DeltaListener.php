<?php

namespace Pitchbar\Sync;

use Pitchbar\Api\PitchbarClient;
use Pitchbar\Plugin;
use Pitchbar\Support\Logger;
use WP_Post;

/**
 * Wires the `save_post` and trash / delete hooks to push single-post
 * deltas at `/v1/wp/posts/changed`. Cheap: each hook fires one HTTP
 * request to Pitchbar, which queues the indexing job and returns
 * immediately. Failure is logged via `Logger::warn` and otherwise
 * swallowed so a broken Pitchbar deployment never blocks a WP write.
 */
final class DeltaListener
{
    /** @var PostContentExtractor */
    private $extractor;

    public function __construct(?PostContentExtractor $extractor = null)
    {
        $this->extractor = $extractor ?? new PostContentExtractor;
    }

    public function register(): void
    {
        add_action('save_post', [$this, 'onSavePost'], 20, 3);
        add_action('wp_trash_post', [$this, 'onDeletePost']);
        add_action('before_delete_post', [$this, 'onDeletePost']);
    }

    /**
     * @param  WP_Post|null  $post
     */
    public function onSavePost(int $postId, $post = null, bool $update = false): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        $post = $post instanceof WP_Post ? $post : get_post($postId);
        if (! $post instanceof WP_Post) {
            return;
        }

        if ($post->post_status !== 'publish') {
            // Drafts and pending posts are not visible to anonymous
            // visitors; don't leak their content to the agent.
            return;
        }

        if (! $this->shouldSync($post->post_type)) {
            return;
        }

        $plugin = Plugin::instance();
        if (! $plugin->isConfigured()) {
            return;
        }

        $payload = $this->extractor->extract($post);
        $this->push('upsert', $payload);
    }

    public function onDeletePost(int $postId): void
    {
        $post = get_post($postId);
        if (! $post instanceof WP_Post) {
            return;
        }
        if (! $this->shouldSync($post->post_type)) {
            return;
        }

        $plugin = Plugin::instance();
        if (! $plugin->isConfigured()) {
            return;
        }

        $this->push('delete', ['wp_id' => (int) $post->ID]);
    }

    private function shouldSync(string $postType): bool
    {
        $enabled = (array) Plugin::instance()->setting('enabled_post_types', ['post', 'page']);

        return in_array($postType, $enabled, true);
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function push(string $action, array $post): void
    {
        $plugin = Plugin::instance();
        $client = new PitchbarClient(
            (string) $plugin->setting('base_url'),
            (string) $plugin->setting('api_token'),
        );

        $result = $client->postsChanged([
            'agent_id' => (string) $plugin->setting('agent_id'),
            'site_url' => home_url('/'),
            'plugin_version' => PITCHBAR_PLUGIN_VERSION,
            'action' => $action,
            'post' => $post,
        ]);

        if (! $result['ok']) {
            Logger::warn('Delta push failed', [
                'action' => $action,
                'post' => $post['wp_id'] ?? null,
                'status' => $result['status'],
                'error' => $result['error'],
            ]);
        }
    }
}
