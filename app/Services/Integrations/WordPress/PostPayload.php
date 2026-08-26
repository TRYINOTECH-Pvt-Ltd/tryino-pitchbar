<?php

namespace App\Services\Integrations\WordPress;

/**
 * Strongly-typed view of a single post / page payload arriving from
 * the WordPress plugin. Validation lives in the controller's
 * request validator; this DTO assumes the payload has already been
 * shape-checked and only normalizes string types.
 */
final class PostPayload
{
    public string $externalId;

    public string $postType;

    public string $title;

    public string $permalink;

    public string $contentHtml;

    public string $excerpt;

    public string $contentHash;

    public ?string $language;

    public ?string $modifiedAt;

    /** @var list<string> */
    public array $taxonomyTerms;

    public function __construct(
        string $externalId,
        string $postType,
        string $title,
        string $permalink,
        string $contentHtml,
        string $excerpt,
        string $contentHash,
        ?string $language,
        ?string $modifiedAt,
        array $taxonomyTerms,
    ) {
        $this->externalId = $externalId;
        $this->postType = $postType;
        $this->title = $title;
        $this->permalink = $permalink;
        $this->contentHtml = $contentHtml;
        $this->excerpt = $excerpt;
        $this->contentHash = $contentHash;
        $this->language = $language;
        $this->modifiedAt = $modifiedAt;
        $this->taxonomyTerms = $taxonomyTerms;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $wpId = (string) ($raw['wp_id'] ?? '');
        $terms = [];
        if (isset($raw['taxonomy_terms']) && is_array($raw['taxonomy_terms'])) {
            foreach ($raw['taxonomy_terms'] as $term) {
                if (is_string($term) && $term !== '') {
                    $terms[] = $term;
                }
            }
        }

        return new self(
            externalId: 'wp:'.$wpId,
            postType: (string) ($raw['post_type'] ?? 'post'),
            title: (string) ($raw['title'] ?? ''),
            permalink: (string) ($raw['permalink'] ?? ''),
            contentHtml: (string) ($raw['content_html'] ?? ''),
            excerpt: (string) ($raw['excerpt'] ?? ''),
            contentHash: (string) ($raw['content_hash'] ?? ''),
            language: isset($raw['language']) ? (string) $raw['language'] : null,
            modifiedAt: isset($raw['modified_at']) ? (string) $raw['modified_at'] : null,
            taxonomyTerms: $terms,
        );
    }

    /**
     * Cleaned plain-text representation of the post body that the
     * RAG pipeline embeds. Combines the title, excerpt, taxonomy
     * terms, and stripped HTML body. Whitespace collapsed.
     */
    public function indexableText(): string
    {
        $parts = [];
        if ($this->title !== '') {
            $parts[] = $this->title;
        }
        if ($this->excerpt !== '') {
            $parts[] = $this->excerpt;
        }
        if ($this->taxonomyTerms !== []) {
            $parts[] = 'Topics: '.implode(', ', $this->taxonomyTerms);
        }
        $body = trim(html_entity_decode(strip_tags($this->contentHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($body !== '') {
            $parts[] = $body;
        }

        $joined = implode("\n\n", $parts);

        return trim(preg_replace('/\s+/u', ' ', $joined) ?? '');
    }
}
