<?php

namespace App\Services\Crawl;

use GuzzleHttp\Client as Guzzle;

class RobotsTxtParser
{
    /** @var array<string, array<int, string>> */
    private array $disallowCache = [];

    public function __construct(private readonly Guzzle $http = new Guzzle(['timeout' => 5])) {}

    public function isAllowed(string $url, string $userAgent = '*'): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $host = "{$parts['scheme']}://{$parts['host']}";
        $disallow = $this->fetchDisallow($host, $userAgent);

        $path = $parts['path'] ?? '/';
        foreach ($disallow as $rule) {
            if ($rule === '') {
                continue;
            }
            if (str_starts_with($path, $rule)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function fetchDisallow(string $host, string $userAgent): array
    {
        if (isset($this->disallowCache[$host])) {
            return $this->disallowCache[$host];
        }

        try {
            $body = (string) $this->http->get($host.'/robots.txt', ['http_errors' => false])->getBody();
        } catch (\Throwable) {
            return $this->disallowCache[$host] = [];
        }

        $rules = [];
        $applies = false;
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^User-agent:\s*(.+)$/i', $line, $m)) {
                $applies = trim($m[1]) === '*' || strcasecmp(trim($m[1]), $userAgent) === 0;

                continue;
            }
            if ($applies && preg_match('/^Disallow:\s*(.*)$/i', $line, $m)) {
                $rules[] = trim($m[1]);
            }
        }

        return $this->disallowCache[$host] = $rules;
    }
}
