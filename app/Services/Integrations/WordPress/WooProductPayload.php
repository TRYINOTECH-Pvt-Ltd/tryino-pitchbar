<?php

namespace App\Services\Integrations\WordPress;

/**
 * Strongly-typed view of a single WooCommerce product payload arriving
 * from the WordPress plugin. Mirrors `PostPayload` for posts.
 *
 * The `indexableText()` method shapes a structured chunk text the RAG
 * pipeline can embed and that the LLM can resurface as a
 * `<product/>` block.
 */
final class WooProductPayload
{
    public string $externalId;

    public string $sku;

    public string $name;

    public string $permalink;

    public string $imageUrl;

    public string $shortDescription;

    public string $description;

    public string $price;

    public string $regularPrice;

    public string $salePrice;

    public string $currency;

    public string $stockStatus;

    public bool $onSale;

    public string $contentHash;

    public ?string $modifiedAt;

    /** @var list<string> */
    public array $categories;

    /** @var list<string> */
    public array $attributes;

    public function __construct(
        string $externalId,
        string $sku,
        string $name,
        string $permalink,
        string $imageUrl,
        string $shortDescription,
        string $description,
        string $price,
        string $regularPrice,
        string $salePrice,
        string $currency,
        string $stockStatus,
        bool $onSale,
        string $contentHash,
        ?string $modifiedAt,
        array $categories,
        array $attributes,
    ) {
        $this->externalId = $externalId;
        $this->sku = $sku;
        $this->name = $name;
        $this->permalink = $permalink;
        $this->imageUrl = $imageUrl;
        $this->shortDescription = $shortDescription;
        $this->description = $description;
        $this->price = $price;
        $this->regularPrice = $regularPrice;
        $this->salePrice = $salePrice;
        $this->currency = $currency;
        $this->stockStatus = $stockStatus;
        $this->onSale = $onSale;
        $this->contentHash = $contentHash;
        $this->modifiedAt = $modifiedAt;
        $this->categories = $categories;
        $this->attributes = $attributes;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $wpId = (string) ($raw['wp_id'] ?? '');
        $categories = self::stringList($raw['categories'] ?? []);
        $attributes = self::stringList($raw['attributes'] ?? []);

        return new self(
            externalId: 'wc:'.$wpId,
            sku: (string) ($raw['sku'] ?? ''),
            name: (string) ($raw['name'] ?? ''),
            permalink: (string) ($raw['permalink'] ?? ''),
            imageUrl: (string) ($raw['image_url'] ?? ''),
            shortDescription: (string) ($raw['short_description'] ?? ''),
            description: (string) ($raw['description'] ?? ''),
            price: (string) ($raw['price'] ?? ''),
            regularPrice: (string) ($raw['regular_price'] ?? ''),
            salePrice: (string) ($raw['sale_price'] ?? ''),
            currency: (string) ($raw['currency'] ?? ''),
            stockStatus: (string) ($raw['stock_status'] ?? ''),
            onSale: (bool) ($raw['on_sale'] ?? false),
            contentHash: (string) ($raw['content_hash'] ?? ''),
            modifiedAt: isset($raw['modified_at']) ? (string) $raw['modified_at'] : null,
            categories: $categories,
            attributes: $attributes,
        );
    }

    /**
     * Structured text the chunker embeds. The price-first ordering
     * matches the LLM's natural composition order when emitting
     * `<product/>` blocks (title -> price -> description).
     */
    public function indexableText(): string
    {
        $parts = ["Product: {$this->name}"];

        if ($this->sku !== '') {
            $parts[] = "SKU: {$this->sku}";
        }

        $priceLine = $this->formatPriceLine();
        if ($priceLine !== '') {
            $parts[] = $priceLine;
        }

        if ($this->stockStatus !== '') {
            $parts[] = 'Stock: '.$this->stockStatus;
        }

        if ($this->categories !== []) {
            $parts[] = 'Categories: '.implode(', ', $this->categories);
        }

        if ($this->attributes !== []) {
            $parts[] = 'Attributes: '.implode(', ', $this->attributes);
        }

        $body = trim(html_entity_decode(strip_tags($this->shortDescription."\n\n".$this->description), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($body !== '') {
            $parts[] = $body;
        }

        $parts[] = 'URL: '.$this->permalink;

        // Surface the image URL inline. EcommercePreset tells the LLM
        // to skip the `image=` attribute when it doesn't have one,
        // which previously meant every product card rendered without
        // a thumbnail because the chunk text didn't carry image_url
        // at all. Now it does, and the LLM can pick it back up.
        if ($this->imageUrl !== '') {
            $parts[] = 'Image: '.$this->imageUrl;
        }

        $joined = implode("\n\n", $parts);

        return trim(preg_replace('/\s+/u', ' ', $joined) ?? '');
    }

    private function formatPriceLine(): string
    {
        $current = $this->salePrice !== '' ? $this->salePrice : $this->price;
        if ($current === '') {
            return '';
        }
        $line = 'Price: '.$current;
        if ($this->currency !== '') {
            $line .= ' '.$this->currency;
        }
        if ($this->onSale && $this->regularPrice !== '' && $this->regularPrice !== $current) {
            $line .= ' (was '.$this->regularPrice.')';
        }

        return $line;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private static function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }
}
