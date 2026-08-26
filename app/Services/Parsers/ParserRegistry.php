<?php

namespace App\Services\Parsers;

use App\Services\Cloudflare\ToMarkdownClient;
use App\Services\Parsers\Contracts\FileParser;
use Illuminate\Contracts\Container\Container;

class ParserRegistry
{
    /** @var array<int, FileParser>|null  Frozen list — set when constructed with an explicit array (tests). */
    private ?array $overrides;

    public function __construct(?array $parsers = null, private readonly ?Container $container = null)
    {
        $this->overrides = $parsers;
    }

    public function for(string $extension): ?FileParser
    {
        foreach ($this->parsers() as $parser) {
            if ($parser->supports($extension)) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * @return array<int, FileParser>
     */
    private function parsers(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        // Resolve the Cloudflare parser lazily so a test that binds
        // ToMarkdownClient *after* the registry singleton was built can
        // still exercise the CF path. The in-process PHP parsers below
        // remain wired in either way and act as the fallback when the
        // CF client isn't bound (no token, BYOK OpenAI customer, etc).
        $cfParser = $this->resolveCloudflareParser();

        return array_values(array_filter([
            $cfParser,
            new PdfParser,
            new DocxParser,
            new CsvParser,
            new TextParser,
        ]));
    }

    private function resolveCloudflareParser(): ?CloudflareMarkdownParser
    {
        $container = $this->container ?? (function_exists('app') ? app() : null);
        if ($container === null || ! $container->bound(ToMarkdownClient::class)) {
            return null;
        }

        try {
            $client = $container->make(ToMarkdownClient::class);
        } catch (\Throwable) {
            return null;
        }

        if (! $client instanceof ToMarkdownClient) {
            return null;
        }

        return new CloudflareMarkdownParser($client);
    }
}
