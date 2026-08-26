<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Packages the WordPress companion plugin at `wp-plugin/pitchbar/`
 * into a single zip archive ready for upload via WordPress' Plugins
 * → Add New → Upload Plugin flow.
 *
 * The output lands in `storage/app/private/wp-plugin-builds/` and is
 * named `pitchbar-{version}.zip`. The zip's top-level directory is
 * `pitchbar/` — WordPress will install it under
 * `wp-content/plugins/pitchbar/` automatically.
 *
 * Re-running the command overwrites the existing artifact (idempotent).
 */
class BuildWpPluginCommand extends Command
{
    protected $signature = 'pitchbar:build-wp-plugin
                            {--output= : Override the output directory (defaults to storage/app/private/wp-plugin-builds)}';

    protected $description = 'Package wp-plugin/pitchbar/ into an install-ready WordPress plugin zip.';

    private const EXCLUDED_PATTERNS = [
        '#(^|/)\.DS_Store$#',
        '#(^|/)\.git(/|$|ignore$|attributes$)#',
        '#(^|/)node_modules(/|$)#',
        '#(^|/)tests(/|$)#',
        '#\.original\.md$#',
        '#\.(swp|swo)$#',
    ];

    public function handle(): int
    {
        $sourceDir = base_path('wp-plugin/pitchbar');
        if (! is_dir($sourceDir)) {
            $this->error("Plugin source not found at {$sourceDir}.");

            return self::FAILURE;
        }

        $mainFile = $sourceDir.DIRECTORY_SEPARATOR.'pitchbar.php';
        if (! is_file($mainFile)) {
            $this->error("Plugin main file missing: {$mainFile}.");

            return self::FAILURE;
        }

        try {
            $version = $this->extractVersion($mainFile);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Compile every .po file in languages/ to a matching .mo so the
        // installed plugin can actually load translations. WordPress reads
        // .mo only (.po is the human-editable source). We do this in pure
        // PHP to avoid depending on the GNU gettext `msgfmt` binary —
        // CI / cPanel hosts often don't have it.
        $this->compilePoFilesIn($sourceDir.DIRECTORY_SEPARATOR.'languages');

        $outputDir = (string) ($this->option('output') ?: storage_path('app/private/wp-plugin-builds'));
        File::ensureDirectoryExists($outputDir);

        $zipPath = $outputDir.DIRECTORY_SEPARATOR.'pitchbar-'.$version.'.zip';
        if (is_file($zipPath)) {
            File::delete($zipPath);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Could not open {$zipPath} for writing.");

            return self::FAILURE;
        }

        $filesAdded = 0;
        $bytesUncompressed = 0;
        foreach ($this->iterateSource($sourceDir) as $absolute => $relative) {
            $zip->addFile($absolute, 'pitchbar/'.$relative);
            $filesAdded++;
            $size = filesize($absolute);
            if (is_int($size)) {
                $bytesUncompressed += $size;
            }
        }

        $zip->close();

        $pruned = $this->pruneOlderBuilds($outputDir, $version);

        $bytesCompressed = filesize($zipPath);
        $this->info('WordPress plugin packaged.');
        $this->line("  Version:   {$version}");
        $this->line("  Files:     {$filesAdded}");
        $this->line('  Source:    '.$this->humanBytes($bytesUncompressed));
        $this->line('  Archive:   '.$this->humanBytes((int) $bytesCompressed));
        $this->line("  Output:    {$zipPath}");
        if ($pruned !== []) {
            $this->line('  Pruned:    '.implode(', ', $pruned));
        }

        return self::SUCCESS;
    }

    /**
     * Keep only the just-built zip. Older `pitchbar-*.zip` files in
     * the output directory are deleted so the distribution endpoint
     * (and any admin "Download plugin" button) only ever sees the
     * current release. Returns the basenames removed for logging.
     *
     * @return list<string>
     */
    private function pruneOlderBuilds(string $outputDir, string $currentVersion): array
    {
        $keep = 'pitchbar-'.$currentVersion.'.zip';
        $removed = [];

        $entries = @scandir($outputDir);
        if (! is_array($entries)) {
            return [];
        }

        foreach ($entries as $name) {
            if (! preg_match('/^pitchbar-[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.]+)?\.zip$/', $name)) {
                continue;
            }
            if ($name === $keep) {
                continue;
            }
            $path = $outputDir.DIRECTORY_SEPARATOR.$name;
            if (is_file($path) && @unlink($path)) {
                $removed[] = $name;
            }
        }

        return $removed;
    }

    /**
     * Reads the `Version:` header out of the plugin main file.
     */
    private function extractVersion(string $mainFile): string
    {
        $contents = (string) file_get_contents($mainFile);
        if (preg_match('/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.]+)?)/m', $contents, $matches) === 1) {
            return $matches[1];
        }

        throw new RuntimeException('Could not parse Version: header from '.$mainFile);
    }

    /**
     * @return iterable<string, string> absolute path => zip-relative path
     */
    private function iterateSource(string $sourceDir): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $sourceDir,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $prefix = $sourceDir.DIRECTORY_SEPARATOR;
        $prefixLen = strlen($prefix);

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $absolute = $file->getPathname();
            $relative = str_replace('\\', '/', substr($absolute, $prefixLen));

            if ($this->isExcluded($relative)) {
                continue;
            }

            yield $absolute => $relative;
        }
    }

    private function isExcluded(string $relative): bool
    {
        foreach (self::EXCLUDED_PATTERNS as $pattern) {
            if (preg_match($pattern, $relative) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compile every `*.po` file in $dir to a sibling `*.mo` in pure PHP.
     * MO binary format documented at
     * https://www.gnu.org/software/gettext/manual/html_node/MO-Files.html
     * — magic number, hash size 0 (we skip the optional hash table),
     * two sorted string tables, plaintext blob, NUL terminators.
     */
    private function compilePoFilesIn(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.po') ?: [] as $poPath) {
            $moPath = preg_replace('/\.po$/', '.mo', $poPath);
            if (! is_string($moPath)) {
                continue;
            }
            $pairs = $this->parsePoFile($poPath);
            $mo = $this->encodeMo($pairs);
            file_put_contents($moPath, $mo);
        }
    }

    /**
     * Parse a `.po` file into `[ msgid => msgstr ]`. Concatenated
     * continuation lines (`"foo"` directly under another `"bar"`) are
     * joined. `msgctxt` is treated as part of the key via `\x04`
     * separator, matching the gettext MO convention.
     *
     * @return array<string, string>
     */
    private function parsePoFile(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }
        $contents = str_replace("\r\n", "\n", $contents);
        $lines = explode("\n", $contents);

        $entries = [];
        $current = ['msgid' => null, 'msgstr' => null, 'msgctxt' => null];
        $lastKey = null;

        $flush = function () use (&$entries, &$current) {
            if ($current['msgid'] === null || $current['msgstr'] === null) {
                return;
            }
            $key = $current['msgctxt'] !== null
                ? $current['msgctxt']."\x04".$current['msgid']
                : $current['msgid'];
            $entries[$key] = $current['msgstr'];
        };

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#')) {
                if ($line === '') {
                    $flush();
                    $current = ['msgid' => null, 'msgstr' => null, 'msgctxt' => null];
                    $lastKey = null;
                }

                continue;
            }

            foreach (['msgctxt', 'msgid', 'msgstr'] as $directive) {
                $needle = $directive.' ';
                if (str_starts_with($line, $needle)) {
                    $current[$directive] = $this->unquotePoString(substr($line, strlen($needle)));
                    $lastKey = $directive;

                    continue 2;
                }
            }

            // Continuation line — quoted string on its own.
            if ($lastKey !== null && str_starts_with($line, '"')) {
                $current[$lastKey] = ($current[$lastKey] ?? '').$this->unquotePoString($line);
            }
        }
        $flush();

        return $entries;
    }

    private function unquotePoString(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) < 2 || $raw[0] !== '"' || $raw[strlen($raw) - 1] !== '"') {
            return '';
        }
        $inner = substr($raw, 1, -1);

        return strtr($inner, [
            '\\\\' => '\\',
            '\\"' => '"',
            '\\n' => "\n",
            '\\t' => "\t",
            '\\r' => "\r",
        ]);
    }

    /**
     * Encode a [msgid => msgstr] map as little-endian gettext MO binary.
     *
     * @param  array<string, string>  $entries
     */
    private function encodeMo(array $entries): string
    {
        ksort($entries);

        $count = count($entries);
        $headerSize = 28;
        $tableSize = $count * 8;
        $originalsOffset = $headerSize;
        $translationsOffset = $originalsOffset + $tableSize;
        $stringsStart = $translationsOffset + $tableSize;

        // Pass 1: assign offsets + lengths to every msgid in the order
        // they'll be written to the blob. ksort already locked that
        // order; this is just bookkeeping.
        $originalEntries = [];
        $cursor = $stringsStart;
        foreach ($entries as $msgid => $_msgstr) {
            $len = strlen($msgid);
            $originalEntries[] = ['len' => $len, 'offset' => $cursor];
            $cursor += $len + 1; // +1 for the NUL terminator
        }

        // Pass 2: same for msgstr, starting right after the msgid blob.
        $translationEntries = [];
        foreach ($entries as $_msgid => $msgstr) {
            $len = strlen($msgstr);
            $translationEntries[] = ['len' => $len, 'offset' => $cursor];
            $cursor += $len + 1;
        }

        $originalsTable = '';
        foreach ($originalEntries as $pair) {
            $originalsTable .= pack('VV', $pair['len'], $pair['offset']);
        }
        $translationsTable = '';
        foreach ($translationEntries as $pair) {
            $translationsTable .= pack('VV', $pair['len'], $pair['offset']);
        }

        $stringBlob = '';
        foreach ($entries as $msgid => $msgstr) {
            $stringBlob .= $msgid."\0";
        }
        foreach ($entries as $msgid => $msgstr) {
            $stringBlob .= $msgstr."\0";
        }

        $header = pack(
            'VVVVVVV',
            0x950412DE,           // magic
            0,                    // version
            $count,
            $originalsOffset,
            $translationsOffset,
            0,                    // hash table size (skip)
            $stringsStart,        // unused when hash size = 0
        );

        return $header.$originalsTable.$translationsTable.$stringBlob;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $exp = (int) floor(log($bytes) / log(1024));
        $exp = max(0, min($exp, count($units) - 1));

        return sprintf('%.1f %s', $bytes / pow(1024, $exp), $units[$exp]);
    }
}
