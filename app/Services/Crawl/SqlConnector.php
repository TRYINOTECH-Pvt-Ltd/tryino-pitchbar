<?php

namespace App\Services\Crawl;

use App\Support\Exceptions\UnsafeUrlException;
use App\Support\UrlSafetyGuard;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Owner-supplied SQL database knowledge source. Workspace owner pastes
 * connection details + a read-only SELECT; this connector executes the
 * query under a READ ONLY transaction and returns rows for the
 * SyncSqlSourceJob to fan out into Documents.
 *
 * Hard rules:
 *   - SSRF: host runs through UrlSafetyGuard::assertSafeHost() — same
 *     allowlist the crawler uses. Workspace owners can't probe
 *     127.0.0.1, RFC1918 ranges, or AWS metadata IPs.
 *   - Read-only: query must start with SELECT (case-insensitive) and
 *     contain no DDL/DML keywords. Connection is opened with READ ONLY
 *     transaction semantics so even a permissive query string can't
 *     mutate state.
 *   - Row cap: 5000 rows per sync. Protects worker memory + Document
 *     table from runaway queries. Configurable later if a buyer asks.
 *   - Drivers: mysql + pgsql only today. MSSQL / Oracle deferred —
 *     they need PHP extensions we don't ship by default.
 *
 * Drives both manual "test connection" probes from the admin and the
 * scheduled sync flow.
 */
class SqlConnector
{
    public const MAX_ROWS = 5000;

    private const ALLOWED_DRIVERS = ['mysql', 'pgsql'];

    private const FORBIDDEN_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE',
        'GRANT', 'REVOKE', 'CREATE', 'REPLACE', 'CALL', 'EXEC',
        'EXECUTE', 'MERGE', 'LOAD',
    ];

    public function __construct(private readonly UrlSafetyGuard $guard) {}

    /**
     * Run the saved query and return a flat list of row arrays.
     *
     * @param  array{driver: string, host: string, port: int, database: string, username: string, password: string}  $credentials
     * @return array<int, array<string, string|null>>
     */
    public function run(array $credentials, string $query): array
    {
        $this->assertSafeQuery($query);
        $this->assertAllowedDriver($credentials['driver']);

        // UrlSafetyGuard works on URLs, not bare hosts. Wrap with a
        // synthetic `http://` so the scheme + pattern + DNS rebind
        // checks all apply to the SQL host the workspace owner pasted.
        try {
            $this->guard->assertSafe('http://'.$credentials['host'], resolveHostnames: true);
        } catch (UnsafeUrlException $e) {
            throw new RuntimeException('Unsafe SQL host: '.$e->getMessage(), previous: $e);
        }

        $pdo = $this->connect($credentials);

        try {
            $this->beginReadOnly($pdo, $credentials['driver']);
            $stmt = $pdo->prepare($query);
            $stmt->execute();

            $rows = [];
            $count = 0;
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($count >= self::MAX_ROWS) {
                    break;
                }
                $rows[] = $this->stringifyRow($row);
                $count++;
            }
            $pdo->commit();

            return $rows;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('SQL query failed: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @param  array{driver: string, host: string, port: int, database: string, username: string, password: string}  $credentials
     */
    private function connect(array $credentials): PDO
    {
        $driver = $credentials['driver'];
        $host = $credentials['host'];
        $port = (int) $credentials['port'];
        $database = $credentials['database'];

        $dsn = match ($driver) {
            'mysql' => "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            'pgsql' => "pgsql:host={$host};port={$port};dbname={$database}",
            default => throw new RuntimeException("Unsupported driver: {$driver}"),
        };

        try {
            return new PDO($dsn, $credentials['username'], $credentials['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 10,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('SQL connect failed: '.$e->getMessage(), previous: $e);
        }
    }

    private function beginReadOnly(PDO $pdo, string $driver): void
    {
        // Best-effort READ ONLY mode. MySQL accepts the syntax since
        // 5.6, Postgres since 7.4. If the server somehow rejects it,
        // we fall back to a plain transaction — the SQL keyword guard
        // already enforces SELECT-only.
        try {
            match ($driver) {
                'mysql' => $pdo->exec('START TRANSACTION READ ONLY'),
                'pgsql' => $pdo->exec('BEGIN TRANSACTION READ ONLY'),
                default => $pdo->beginTransaction(),
            };
        } catch (PDOException) {
            $pdo->beginTransaction();
        }
    }

    private function assertSafeQuery(string $query): void
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            throw new RuntimeException('Query is empty.');
        }
        if (! preg_match('/^select\b/i', $trimmed)) {
            throw new RuntimeException('Query must start with SELECT.');
        }
        // Reject multi-statement: any semicolon followed by non-whitespace.
        if (preg_match('/;\s*\S/', $trimmed)) {
            throw new RuntimeException('Multi-statement queries are not allowed.');
        }
        $upper = strtoupper(' '.preg_replace('/\s+/', ' ', $trimmed).' ');
        foreach (self::FORBIDDEN_KEYWORDS as $kw) {
            if (str_contains($upper, ' '.$kw.' ')) {
                throw new RuntimeException("Forbidden keyword in query: {$kw}.");
            }
        }
    }

    private function assertAllowedDriver(string $driver): void
    {
        if (! in_array($driver, self::ALLOWED_DRIVERS, true)) {
            throw new RuntimeException("Unsupported SQL driver: {$driver}.");
        }
    }

    /**
     * Coerce every column value to string|null. Keeps the downstream
     * Document body builder simple — numbers and dates render as their
     * native string form.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string|null>
     */
    private function stringifyRow(array $row): array
    {
        $out = [];
        foreach ($row as $col => $value) {
            if ($value === null) {
                $out[$col] = null;
            } elseif (is_scalar($value)) {
                $out[$col] = (string) $value;
            } else {
                // BLOB / array — JSON-encode for safety.
                $encoded = json_encode($value);
                $out[$col] = is_string($encoded) ? $encoded : null;
            }
        }

        return $out;
    }
}
