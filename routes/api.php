<?php

use App\Http\Controllers\Api\SourcesController;
use App\Http\Controllers\Internal\QueueTickController;
use App\Http\Controllers\Widget\CheckoutController;
use App\Http\Controllers\Widget\ConversationClearController;
use App\Http\Controllers\Widget\ConversationMessagesController;
use App\Http\Controllers\Widget\CouponApplyController;
use App\Http\Controllers\Widget\EventsController;
use App\Http\Controllers\Widget\GdprController;
use App\Http\Controllers\Widget\InitController;
use App\Http\Controllers\Widget\LeadController as WidgetLeadController;
use App\Http\Controllers\Widget\MessageController;
use App\Http\Controllers\Widget\MessageStreamController;
use App\Http\Controllers\Widget\RequestHumanController;
use App\Http\Controllers\Widget\SatisfactionController;
use App\Http\Controllers\Widget\TryNowController;
use App\Http\Controllers\Widget\TypingController;
use App\Http\Controllers\Wp\CouponsSyncController;
use App\Http\Controllers\Wp\HandshakeController;
use App\Http\Controllers\Wp\PostDeltaController;
use App\Http\Controllers\Wp\PostSyncController;
use App\Http\Controllers\Wp\ProductDeltaController;
use App\Http\Controllers\Wp\ProductSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/widget')->group(function () {
    Route::post('init', InitController::class)
        ->middleware('throttle:widget-init')
        ->name('widget.init');

    // Anonymous "try now" demo for the marketing hero. Visitor pastes
    // any URL, we fetch it, and they can chat with a one-shot demo
    // grounded in that page. Cache-only — no agent, no workspace, no
    // DB. Rate-limited per-IP to prevent abuse.
    Route::post('try-now', [TryNowController::class, 'start'])
        ->middleware('throttle:try-now-start')
        ->name('widget.try-now.start');
    Route::post('try-now/stream', [TryNowController::class, 'stream'])
        ->middleware('throttle:try-now-stream')
        ->name('widget.try-now.stream');

    // All bearer-token-protected endpoints re-validate the request
    // Origin against the JWT's agent.allowed_origins. /widget/init
    // above enforces the same check inline before issuing the JWT;
    // re-checking here is defence-in-depth against stolen tokens
    // replayed from an unlisted origin.
    Route::middleware(['throttle:widget-session', 'widget.origin'])->group(function () {
        Route::post('messages', MessageController::class)->name('widget.messages');
        Route::post('messages/stream', MessageStreamController::class)->name('widget.messages.stream');
        Route::post('events', EventsController::class)->name('widget.events');
        Route::delete('me', [GdprController::class, 'delete'])->name('widget.gdpr.delete');
        Route::get('conversation/messages', ConversationMessagesController::class)->name('widget.conversation.messages');
        Route::post('conversation/clear', ConversationClearController::class)->name('widget.conversation.clear');
        Route::post('request-human', RequestHumanController::class)->name('widget.request-human');
        // Per-request inline throttles for low-cost endpoints. These
        // segment by IP via Laravel's default `throttle` middleware
        // which used to 429 NAT'd visitors (corporate VPN, mobile
        // carrier, shared WiFi). Limits raised so the only thing they
        // catch now is genuine abuse, not benign shared-IP bursts.
        Route::post('typing', TypingController::class)
            ->middleware('throttle:600,1')
            ->name('widget.typing');
        Route::post('satisfaction', SatisfactionController::class)
            ->middleware('throttle:60,1')
            ->name('widget.satisfaction');
        Route::post('coupon/apply', CouponApplyController::class)
            ->middleware('throttle:120,1')
            ->name('widget.coupon.apply');
        // In-chat Stripe Checkout. Visitor clicks "Pay now" on the
        // <checkout/> card → widget POSTs the parsed block payload
        // here → controller mints a hosted Stripe Checkout session +
        // returns the URL. Capability-gated to `in_chat_payments`.
        Route::post('checkout/create', CheckoutController::class)
            ->middleware('throttle:30,1')
            ->name('widget.checkout.create');
    });

    Route::post('leads', WidgetLeadController::class)
        ->middleware(['throttle:widget-leads', 'widget.origin'])
        ->name('widget.leads');
});

// Internal endpoint hit by an external cron (Cloudflare Workers Cron
// Trigger, GitHub Actions, cron-job.org, etc.) to drive the Laravel
// queue when cPanel cron + long-running queue:work daemons are
// unreliable. Auth via INTERNAL_QUEUE_TOKEN env.
Route::post('v1/internal/queue-tick', QueueTickController::class)
    ->name('internal.queue-tick');

// Public programmatic ingest. Workspace-scoped bearer token with the
// `sources:write` ability. Endpoint shape is documented at
// /documentation/api-ingest and is part of the buyer-facing API.
Route::prefix('v1/workspace')
    ->middleware('auth.api_token:sources:write')
    ->group(function () {
        Route::get('sources', [SourcesController::class, 'index'])
            ->name('api.sources.index');
        Route::post('sources', [SourcesController::class, 'store'])
            ->name('api.sources.store');
        Route::get('sources/{source}', [SourcesController::class, 'show'])
            ->name('api.sources.show');
    });

// WordPress / WooCommerce companion plugin endpoints. All authenticated
// by workspace-scoped bearer token with the `wp:integration` ability.
// Plugin-only — not surfaced in public OpenAPI.
Route::prefix('v1/wp')
    ->middleware(['auth.api_token:wp:integration', 'throttle:wp-plugin'])
    ->group(function () {
        Route::post('handshake', HandshakeController::class)->name('wp.handshake');

        // Mutating endpoints additionally require a valid HMAC signature
        // on the request body so a leaked bearer alone can't be replayed.
        Route::middleware('hmac.signature')->group(function () {
            Route::post('posts/sync', PostSyncController::class)->name('wp.posts.sync');
            Route::post('posts/changed', PostDeltaController::class)->name('wp.posts.changed');
            Route::post('products/sync', ProductSyncController::class)->name('wp.products.sync');
            Route::post('products/changed', ProductDeltaController::class)->name('wp.products.changed');
            Route::post('coupons/sync', CouponsSyncController::class)->name('wp.coupons.sync');
        });
    });
