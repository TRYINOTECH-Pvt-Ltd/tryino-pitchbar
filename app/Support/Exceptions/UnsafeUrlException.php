<?php

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * Thrown when UrlSafetyGuard rejects a URL because the host resolves
 * to a private/internal/loopback/metadata address. Carries a short,
 * human-readable reason that can safely surface to admins (we do NOT
 * expose the resolved IP — that would let an attacker probe DNS
 * mappings via the error channel).
 */
class UnsafeUrlException extends RuntimeException {}
