<?php

use App\Models\IntegrationConnection;

/**
 * The string values on IntegrationConnection::KIND_* constants are
 * persisted to the database. A silent rename of any constant would
 * break every existing connection row (queries by `kind` would miss).
 * Lock the values explicitly here so a rename forces a deliberate
 * test update.
 */
test('IntegrationConnection KIND constants match expected persisted strings', function () {
    expect(IntegrationConnection::KIND_SLACK)->toBe('slack');
    expect(IntegrationConnection::KIND_NOTION)->toBe('notion');
    expect(IntegrationConnection::KIND_GOOGLE)->toBe('google');
    expect(IntegrationConnection::KIND_MCP)->toBe('mcp');
});
