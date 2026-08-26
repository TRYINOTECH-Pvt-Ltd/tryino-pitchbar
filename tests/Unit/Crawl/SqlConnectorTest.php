<?php

use App\Services\Crawl\SqlConnector;
use App\Support\UrlSafetyGuard;

beforeEach(function () {
    $this->connector = new SqlConnector(new UrlSafetyGuard);
});

test('rejects non-SELECT queries', function () {
    $credentials = [
        'driver' => 'mysql', 'host' => 'example.com', 'port' => 3306,
        'database' => 'd', 'username' => 'u', 'password' => 'p',
    ];

    expect(fn () => $this->connector->run($credentials, 'DELETE FROM users'))
        ->toThrow(RuntimeException::class, 'Query must start with SELECT.');

    expect(fn () => $this->connector->run($credentials, 'INSERT INTO t VALUES (1)'))
        ->toThrow(RuntimeException::class, 'Query must start with SELECT.');
});

test('rejects forbidden keywords inside SELECT queries', function () {
    $credentials = [
        'driver' => 'mysql', 'host' => 'example.com', 'port' => 3306,
        'database' => 'd', 'username' => 'u', 'password' => 'p',
    ];

    expect(fn () => $this->connector->run(
        $credentials,
        'SELECT * FROM t WHERE id IN (SELECT id FROM x); DROP TABLE users',
    ))->toThrow(RuntimeException::class);
});

test('rejects unsupported drivers', function () {
    expect(fn () => $this->connector->run([
        'driver' => 'oracle', 'host' => 'example.com', 'port' => 1521,
        'database' => 'd', 'username' => 'u', 'password' => 'p',
    ], 'SELECT 1'))->toThrow(RuntimeException::class, 'Unsupported SQL driver: oracle.');
});

test('rejects SSRF-blocked hosts (loopback)', function () {
    expect(fn () => $this->connector->run([
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
        'database' => 'd', 'username' => 'u', 'password' => 'p',
    ], 'SELECT 1'))->toThrow(RuntimeException::class, 'Unsafe SQL host');
});

test('rejects SSRF-blocked hosts (link-local AWS metadata)', function () {
    expect(fn () => $this->connector->run([
        'driver' => 'mysql', 'host' => '169.254.169.254', 'port' => 3306,
        'database' => 'd', 'username' => 'u', 'password' => 'p',
    ], 'SELECT 1'))->toThrow(RuntimeException::class, 'Unsafe SQL host');
});

test('rejects multi-statement queries', function () {
    $credentials = [
        'driver' => 'mysql', 'host' => 'example.com', 'port' => 3306,
        'database' => 'd', 'username' => 'u', 'password' => 'p',
    ];

    expect(fn () => $this->connector->run($credentials, 'SELECT 1; SELECT 2'))
        ->toThrow(RuntimeException::class, 'Multi-statement queries are not allowed.');
});

test('rejects empty queries', function () {
    $credentials = [
        'driver' => 'mysql', 'host' => 'example.com', 'port' => 3306,
        'database' => 'd', 'username' => 'u', 'password' => 'p',
    ];

    expect(fn () => $this->connector->run($credentials, '   '))
        ->toThrow(RuntimeException::class, 'Query is empty.');
});
