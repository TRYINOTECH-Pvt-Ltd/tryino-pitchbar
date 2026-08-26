<?php

namespace App\Support;

/**
 * Transforms raw internal crawl / sync exception strings into customer-
 * safe messages for the Sources / Knowledge admin lists.
 *
 * The raw `sources.error` column stays untouched for operator log-spelunking
 * (rendered behind a "Show details" toggle, included in support reports).
 * This presenter only governs the headline string we surface in the UI:
 * no API keys, no upstream HTTP bodies, no provider names — just a short
 * actionable line the customer can read without filing a ticket.
 *
 * The matching is best-effort. Anything we don't have a rule for falls
 * back to a generic "Couldn't reach this page." line — better to under-
 * communicate than leak a stack trace.
 */
final class SourceErrorPresenter
{
    /**
     * Map an internal error string to the customer-facing message. Returns
     * null when there's no error.
     */
    public static function present(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $needle = mb_strtolower($raw);

        // A Google Docs link crawled as a plain website URL — the crawler
        // can never read docs.google.com (JS app + bot protection), so
        // whatever proximate error it hit (429, "enable javascript", a
        // timeout) is noise; the real diagnosis is "wrong source type".
        // Checked FIRST for that reason. The URL form now absorbs these
        // into proper Google Doc sources; rows stamped before that fix
        // get the pointer.
        if (str_contains($needle, 'docs.google.com')) {
            return 'This link is a Google Doc — the web crawler cannot read it. Delete this source and add the link again (it will be picked up as a Google Doc), or use the Google Docs tab.';
        }

        // Cloudflare Browser Rendering — auth failures, rate limits,
        // service errors. The plaintext 401 body exposes the upstream
        // JSON envelope; we want zero of that on the customer side.
        if (str_contains($needle, 'cloudflare browser rendering')) {
            if (str_contains($needle, '401') || str_contains($needle, 'authentication')) {
                return 'The crawl service is temporarily unavailable on your workspace. Pitchbar support has been notified.';
            }
            if (str_contains($needle, '429') || str_contains($needle, 'rate-limited') || str_contains($needle, 'rate limited')) {
                return 'The crawl service is busy right now. We will retry this page automatically in a few minutes.';
            }
            if (str_contains($needle, '5')) {
                return 'The crawl service hit a transient error. We will retry this page automatically.';
            }

            return 'The crawl service could not render this page.';
        }

        // Browserless fallback — same shape, different vendor.
        if (str_contains($needle, 'browserless')) {
            if (str_contains($needle, '401')) {
                return 'The crawl service is temporarily unavailable on your workspace. Pitchbar support has been notified.';
            }
            if (str_contains($needle, '429') || str_contains($needle, 'rate')) {
                return 'The crawl service is busy right now. We will retry this page automatically in a few minutes.';
            }

            return 'The crawl service could not render this page.';
        }

        // Google Doc / Sheet ingestion. The job's raw errors are precise
        // ("Refresh failed: Token has been expired or revoked", "getDoc
        // metadata failed: File not found") but used to fall through to
        // the catch-all "Couldn't index this page." — which told a client
        // whose Google connection had died exactly nothing (2026-07-03).
        // Keep this ABOVE the generic HTTP rule so "export failed:
        // HTTP 403" resolves to the Google-specific advice. Matched on
        // the Google jobs' error prefixes — NOT a bare "google", which
        // any crawl error embedding a google.com target URL would trip.
        if (str_contains($needle, 'google api error')
            || str_contains($needle, 'google sheets api error')
            || str_contains($needle, 'google doc')
            || str_contains($needle, 'google is not connected')
            || str_contains($needle, 'google access token')
            || str_contains($needle, 'reconnect google')
            || str_contains($needle, 'google connection expired')) {
            if (str_contains($needle, 'expired or revoked')
                || str_contains($needle, 'invalid_grant')
                || str_contains($needle, 'reconnect google')
                || str_contains($needle, 'no refresh token')) {
                return 'Your Google connection has expired or was revoked. Reconnect Google under Integrations, then click Reindex on this source.';
            }
            if (str_contains($needle, 'is not a google doc')) {
                return "That link isn't a Google Doc. Only Google Docs work here — for spreadsheets use the Google Sheet source; export anything else to PDF/DOCX and upload it as a file.";
            }
            if (str_contains($needle, 'not found')
                || str_contains($needle, '404')
                || str_contains($needle, 'permission')
                || str_contains($needle, 'insufficient')
                || str_contains($needle, '403')) {
                return "The connected Google account can't open this document. Share it with that account (or set it to 'Anyone with the link can view'), then click Reindex.";
            }
            if (str_contains($needle, 'empty or too short')) {
                return 'This Google Doc is empty or too short to index. Add content to the doc, then click Reindex.';
            }
            if (str_contains($needle, 'not connected')) {
                return 'Google is not connected for this workspace. Connect it under Integrations first.';
            }

            return 'Google returned an error while we fetched this document. Reconnect Google under Integrations and try Reindex.';
        }

        // Generic HTTP failures from cURL / wp_remote_post / Guzzle.
        if (preg_match('/\bhttp\s*([45]\d\d)\b/i', $needle, $m) === 1) {
            $status = (int) $m[1];
            if ($status === 401 || $status === 403) {
                return 'The page returned an authentication error and could not be crawled.';
            }
            if ($status === 404) {
                return "We couldn't find this page (404). It may have moved or been removed.";
            }
            if ($status === 429) {
                return 'The target site is rate-limiting our crawler. We will retry automatically.';
            }
            if ($status >= 500) {
                return 'The target site returned a server error while we were crawling it.';
            }

            return "We couldn't reach this page.";
        }

        // cURL transport-level errors (timeouts, DNS, SSL handshake).
        if (str_contains($needle, 'curl error')
            || str_contains($needle, 'operation timed out')
            || str_contains($needle, 'could not resolve')
            || str_contains($needle, 'ssl handshake')) {
            return "We couldn't reach this page. It may be temporarily offline or blocking automated requests.";
        }

        // SSRF / safety guards (private IPs, localhost, cloud metadata).
        if (str_contains($needle, 'forbidden host')
            || str_contains($needle, 'private ip')
            || str_contains($needle, 'loopback')) {
            return "We can't crawl this URL for safety reasons (private or internal address).";
        }

        // Empty / unparseable content surfaced by the parsers.
        if (str_contains($needle, 'no extractable text')
            || str_contains($needle, 'empty body')
            || str_contains($needle, 'parser failed')) {
            return "The page loaded but we couldn't extract any readable text from it.";
        }

        // Unsupported file type from the upload parser. Spreadsheet /
        // OpenDocument formats only get a parser when Cloudflare Workers AI
        // is configured — the controller stamps a CF hint in the raw error
        // for those, so preserve it instead of collapsing into the generic
        // "this file type is not supported" copy.
        if (str_contains($needle, 'unsupported file type')) {
            if (str_contains($needle, 'cloudflare workers ai')) {
                return 'Spreadsheet / OpenDocument uploads need Cloudflare Workers AI. Configure CLOUDFLARE_ACCOUNT_ID + CLOUDFLARE_API_TOKEN, or export the file to CSV and re-upload.';
            }

            return 'This file type is not supported. Try uploading a PDF, DOCX, XLSX, TXT, CSV, or Markdown file.';
        }

        // Quota / billing.
        if (str_contains($needle, 'quota') || str_contains($needle, 'usage cap')) {
            return 'This workspace has reached its crawl quota for the month.';
        }

        // Legacy scar from the reindex-type bug: uploaded-file / auto
        // sources were routed through the URL crawler and stamped with
        // this. The reindex now handles those types, so the advice is
        // simply to click it again.
        if (str_contains($needle, 'no url configured')) {
            return 'A previous reindex hit a bug with this source type (fixed). Click Reindex once to restore it — the indexed content was never lost.';
        }

        // Catch-all. Strip the "Crawl failed: " prefix CrawlPageJob stamps so
        // we don't show it next to the friendly message; never leak more than
        // a generic line.
        return "Couldn't index this page.";
    }
}
