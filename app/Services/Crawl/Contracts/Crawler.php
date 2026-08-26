<?php

namespace App\Services\Crawl\Contracts;

interface Crawler
{
    /**
     * Fetch the rendered HTML of a URL.
     */
    public function content(string $url, array $opts = []): string;
}
