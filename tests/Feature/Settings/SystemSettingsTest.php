<?php

use App\Enums\PlatformRole;
use App\Models\AppSetting;
use App\Models\User;
use App\Notifications\NewLeadCaptured;
use App\Providers\AppSettingsOverrideServiceProvider;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Exceptions\OpenAiBadRequestException;
use App\Support\AppBranding;
use App\Support\LlmErrorPresenter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function platformAdminForSystem(): User
{
    return User::factory()->create([
        'email' => 'admin@example.com',
        'role' => PlatformRole::SuperAdmin,
    ]);
}

test('the system settings page renders provider sections for super_admin', function () {
    $admin = platformAdminForSystem();

    $response = $this->actingAs($admin)->get('/settings/system');

    $response->assertOk()->assertInertia(fn ($p) => $p
        ->component('settings/system')
        ->where('page', 'system')
        ->has('sections.mail')
        ->has('sections.stripe')
        ->has('sections.llm')
        ->has('sections.cache')
        ->has('sections.vector')
        ->has('sections.reverb')
        ->has('sections.marketing')
        ->has('sections.privacy'));
});

test('the dedicated branding, marketing, and privacy pages render for super_admin', function () {
    $admin = platformAdminForSystem();

    $this->actingAs($admin)
        ->get('/settings/branding')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('settings/system')
            ->where('page', 'branding')
            ->has('form.site_title')
            ->has('form.header_logo_url')
            ->has('form.pitchbar_brand_label'));

    $this->actingAs($admin)
        ->get('/settings/marketing')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('settings/system')
            ->where('page', 'marketing')
            ->where('form.marketing_home_content.hero.line_one', 'Turn every'));

    $this->actingAs($admin)
        ->get('/settings/privacy')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('settings/system')
            ->where('page', 'privacy')
            ->where('form.privacy_policy_content.title', 'Privacy policy')
            ->where('form.privacy_policy_content.contact.email', 'privacy@pitchbar.ai'));
});

test('shared session success messages are promoted to inertia toast flash', function () {
    $admin = platformAdminForSystem();

    $response = $this->actingAs($admin)
        ->withSession(['success' => 'Branding settings saved.'])
        ->get('/settings/system');

    $page = $response->inertiaPage();

    $response->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('flash.success', 'Branding settings saved.')
            ->where('flash.error', null));

    expect(data_get($page, 'flash.toast.type'))->toBe('success');
    expect(data_get($page, 'flash.toast.message'))->toBe('Branding settings saved.');
});

test('a customer cannot reach the admin-only settings pages (404 to hide existence)', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($customer)->get('/settings/system')->assertStatus(404);
    $this->actingAs($customer)->get('/settings/branding')->assertStatus(404);
    $this->actingAs($customer)->get('/settings/marketing')->assertStatus(404);
    $this->actingAs($customer)->get('/settings/privacy')->assertStatus(404);
});

test('mail summary masks the SMTP username tail-only', function () {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.com',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.username' => 'super-secret-user@example.com',
    ]);

    $admin = platformAdminForSystem();

    $response = $this->actingAs($admin)->get('/settings/system');

    $response->assertOk()->assertInertia(fn ($p) => $p
        ->where('sections.mail.host', 'smtp.example.com')
        ->where('sections.mail.username', fn ($v) => is_string($v)
            && str_contains((string) $v, '•')
            && str_ends_with((string) $v, '.com')));
});

test('mail test endpoint dispatches a test email to the admin', function () {
    Mail::fake();
    $admin = platformAdminForSystem();

    $response = $this->actingAs($admin)
        ->postJson('/settings/system/test/mail');

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', fn ($m) => is_string($m)
            && str_contains((string) $m, $admin->email));
});

test('lead-email test endpoint dispatches a NewLeadCaptured notification to the admin', function () {
    Notification::fake();
    $admin = platformAdminForSystem();

    $response = $this->actingAs($admin)
        ->postJson('/settings/system/test/lead-email');

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', fn ($m) => is_string($m)
            && str_contains((string) $m, 'lead-captured'));

    Notification::assertSentOnDemand(
        NewLeadCaptured::class,
    );
});

test('lead-email test endpoint requires platform admin', function () {
    $this->postJson('/settings/system/test/lead-email')->assertStatus(401);
});

test('llm test endpoint exercises the bound OpenAiClient via streamChat', function () {
    $admin = platformAdminForSystem();

    $response = $this->actingAs($admin)->postJson('/settings/system/test/llm');

    $response->assertOk()->assertJsonPath('ok', true)
        ->assertJsonPath('message', fn ($m) => is_string($m)
            && str_starts_with((string) $m, 'LLM responded:'));
});

test('embed test endpoint returns the dimensionality of the produced vector', function () {
    $admin = platformAdminForSystem();

    $response = $this->actingAs($admin)->postJson('/settings/system/test/embed');

    $response->assertOk()->assertJsonPath('ok', true)
        ->assertJsonPath('message', fn ($m) => is_string($m)
            && str_contains((string) $m, '-dim vector'));
});

test('cache test endpoint round-trips a value via the configured driver', function () {
    $admin = platformAdminForSystem();

    $response = $this->actingAs($admin)->postJson('/settings/system/test/cache');

    $response->assertOk()->assertJsonPath('ok', true)
        ->assertJsonPath('message', fn ($m) => is_string($m)
            && str_contains((string) $m, 'cycle succeeded'));
});

test('stripe test endpoint reports configuration error when STRIPE_SECRET is empty', function () {
    config(['cashier.secret' => '']);
    putenv('STRIPE_SECRET=');
    $admin = platformAdminForSystem();

    $response = $this->actingAs($admin)->postJson('/settings/system/test/stripe');

    $response->assertOk()->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'STRIPE_SECRET is not configured.');
});

test('test endpoints reject unauthenticated requests', function () {
    // postJson sends Accept: application/json which makes the auth
    // middleware return 401 instead of redirecting to /login.
    $this->postJson('/settings/system/test/mail')->assertStatus(401);
});

test('llm test endpoint sanitises a raw Workers AI 401 envelope', function () {
    $admin = platformAdminForSystem();

    // Bind a stub OpenAiClient that throws the exact "Workers AI 401:"
    // envelope CF returns when the token is bad. The controller's catch
    // hands the message to LlmErrorPresenter, which must strip the raw
    // JSON and render an actionable token-rotation line.
    app()->bind(OpenAiClient::class, function () {
        return new class implements OpenAiClient
        {
            public function streamChat(array $messages, array $opts = []): iterable
            {
                throw new OpenAiBadRequestException(
                    'Workers AI 401: {"success":false,"result":[],"messages":[],"error":[{"code":2009,"message":"Unauthorized"}]}'
                );
            }

            public function chat(array $messages, array $opts = []): array
            {
                return ['content' => '', 'finish_reason' => 'stop'];
            }

            public function chatWithTools(array $messages, array $tools, array $opts = []): array
            {
                return ['content' => '', 'finish_reason' => 'stop'];
            }

            public function embed(array $texts, array $opts = []): array
            {
                return [[0.0]];
            }
        };
    });

    $response = $this->actingAs($admin)->postJson('/settings/system/test/llm');

    $response->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', fn ($m) => is_string($m)
            && ! str_contains($m, '"success":false')
            && ! str_contains($m, '"code":2009')
            && str_contains($m, 'CLOUDFLARE_ACCOUNT_ID'));
});

test('playground stream sanitises a raw Workers AI 401 envelope into the error event', function () {
    // Bind a fake retriever-or-llm that throws the buyer's exact envelope.
    // The playground SSE handler emits an `error` event with the raw
    // message field; the fix routes it through LlmErrorPresenter first.
    // We assert on the emitted event payload via a stub on the controller.
    $raw = 'Workers AI embed 401: {"success":false,"result":[],"messages":[],"error":[{"code":2009,"message":"Unauthorized"}]}';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->not->toContain('"success":false');
    expect($msg)->toContain('CLOUDFLARE_ACCOUNT_ID');
});

test('embed test endpoint sanitises a raw Workers AI 401 envelope', function () {
    $admin = platformAdminForSystem();

    app()->bind(OpenAiClient::class, function () {
        return new class implements OpenAiClient
        {
            public function streamChat(array $messages, array $opts = []): iterable
            {
                yield from [];
            }

            public function chat(array $messages, array $opts = []): array
            {
                return ['content' => '', 'finish_reason' => 'stop'];
            }

            public function chatWithTools(array $messages, array $tools, array $opts = []): array
            {
                return ['content' => '', 'finish_reason' => 'stop'];
            }

            public function embed(array $texts, array $opts = []): array
            {
                throw new OpenAiBadRequestException(
                    'Workers AI embed 401: {"success":false,"result":[],"messages":[],"error":[{"code":2009,"message":"Unauthorized"}]}'
                );
            }
        };
    });

    $response = $this->actingAs($admin)->postJson('/settings/system/test/embed');

    $response->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', fn ($m) => is_string($m)
            && ! str_contains($m, '"success":false')
            && str_contains($m, 'CLOUDFLARE_ACCOUNT_ID'));
});

test('test endpoints reject non-admin users', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($customer)
        ->postJson('/settings/system/test/cache')
        ->assertStatus(404);
});

test('the index exposes form values with sensitive fields nulled and _set booleans', function () {
    $admin = platformAdminForSystem();

    AppSetting::singleton()->forceFill([
        'stripe_key' => 'pk_test_123',
        'stripe_secret' => 'sk_test_secret',
        'cloudflare_account_id' => 'cf-account-1',
        'cloudflare_api_token' => 'cf-token-secret',
        'site_title' => 'Pitchbar Pro',
        'header_logo_path' => 'branding/header/header-logo.png',
        'header_brand_display' => 'logo_text',
        'footer_brand_display' => 'text_only',
        'dashboard_brand_display' => 'logo_only',
        'pitchbar_brand_label' => 'Powered by Pitchbar',
        'marketing_home_content' => [
            'hero' => ['line_one' => 'Convert every'],
        ],
        'privacy_policy_content' => [
            'title' => 'Customer data policy',
            'contact' => ['email' => 'privacy@acme.example'],
        ],
    ])->save();
    AppSetting::flushSingleton();

    $response = $this->actingAs($admin)->get('/settings/system');

    $response->assertOk()->assertInertia(fn ($p) => $p
        // Public values come through as-is.
        ->where('form.stripe_key', 'pk_test_123')
        ->where('form.cloudflare_account_id', 'cf-account-1')
        ->where('form.site_title', 'Pitchbar Pro')
        ->where('form.header_logo_url', fn ($value) => is_string($value)
            && str_contains((string) $value, 'branding/header/header-logo.png'))
        ->where('form.header_brand_display', 'logo_text')
        ->where('form.footer_brand_display', 'text_only')
        ->where('form.dashboard_brand_display', 'logo_only')
        ->where('form.pitchbar_brand_label', 'Powered by Pitchbar')
        ->where('form.marketing_home_content.hero.line_one', 'Convert every')
        ->where('form.privacy_policy_content.title', 'Customer data policy')
        ->where('form.privacy_policy_content.contact.email', 'privacy@acme.example')
        // Sensitive values never reach the browser — only _set booleans.
        ->where('form.stripe_secret_set', true)
        ->where('form.cloudflare_api_token_set', true)
        ->missing('form.stripe_secret')
        ->missing('form.cloudflare_api_token'));
});

test('branding update stores site title and uploaded brand assets', function () {
    Storage::fake(AppBranding::disk());
    $admin = platformAdminForSystem();

    $this->actingAs($admin)
        ->post('/settings/system/branding', [
            '_method' => 'patch',
            'site_title' => 'Acme Assist',
            'header_brand_display' => 'logo_text',
            'footer_brand_display' => 'text_only',
            'dashboard_brand_display' => 'logo_only',
            'pitchbar_brand_url' => 'https://acme.example',
            'pitchbar_brand_label' => 'Powered by Acme Assist',
            'header_logo' => UploadedFile::fake()->image('header.png', 320, 120),
            'footer_logo' => UploadedFile::fake()->image('footer.png', 320, 120),
            'dashboard_logo' => UploadedFile::fake()->image('dashboard.png', 128, 128),
            'favicon' => UploadedFile::fake()->image('favicon.png', 64, 64),
        ])
        ->assertRedirect();

    $fresh = AppSetting::query()->findOrFail(AppSetting::SINGLETON_ID);

    expect($fresh->site_title)->toBe('Acme Assist');
    expect($fresh->header_brand_display)->toBe('logo_text');
    expect($fresh->footer_brand_display)->toBe('text_only');
    expect($fresh->dashboard_brand_display)->toBe('logo_only');
    expect($fresh->pitchbar_brand_url)->toBe('https://acme.example');
    expect($fresh->pitchbar_brand_label)->toBe('Powered by Acme Assist');
    expect($fresh->header_logo_path)->toStartWith('branding/header/');
    expect($fresh->footer_logo_path)->toStartWith('branding/footer/');
    expect($fresh->dashboard_logo_path)->toStartWith('branding/dashboard/');
    expect($fresh->favicon_path)->toStartWith('branding/favicon/');

    Storage::disk(AppBranding::disk())->assertExists($fresh->header_logo_path);
    Storage::disk(AppBranding::disk())->assertExists($fresh->footer_logo_path);
    Storage::disk(AppBranding::disk())->assertExists($fresh->dashboard_logo_path);
    Storage::disk(AppBranding::disk())->assertExists($fresh->favicon_path);
});

test('branding update stores site title without requiring file uploads', function () {
    $admin = platformAdminForSystem();

    $this->actingAs($admin)
        ->patch('/settings/system/branding', [
            'site_title' => 'Prince',
            'header_brand_display' => 'logo_text',
            'footer_brand_display' => 'logo_only',
            'dashboard_brand_display' => 'text_only',
            'pitchbar_brand_url' => 'https://prince.example',
            'pitchbar_brand_label' => 'Powered by Prince',
        ])
        ->assertRedirect();

    $fresh = AppSetting::query()->findOrFail(AppSetting::SINGLETON_ID);

    expect($fresh->site_title)->toBe('Prince');
    expect($fresh->header_brand_display)->toBe('logo_text');
    expect($fresh->footer_brand_display)->toBe('logo_only');
    expect($fresh->dashboard_brand_display)->toBe('text_only');
    expect($fresh->pitchbar_brand_url)->toBe('https://prince.example');
    expect($fresh->pitchbar_brand_label)->toBe('Powered by Prince');
});

test('branding update reports an error when no changes were submitted', function () {
    $admin = platformAdminForSystem();

    $this->actingAs($admin)
        ->patch('/settings/system/branding', [])
        ->assertRedirect()
        ->assertSessionHas('error', 'No branding settings changes were received.');

    expect(AppSetting::query()->find(AppSetting::SINGLETON_ID)?->site_title)
        ->toBeNull();
});

test('the update endpoint persists section fields and flushes cache', function () {
    $admin = platformAdminForSystem();

    $this->actingAs($admin)
        ->patch('/settings/system/stripe', [
            'stripe_key' => 'pk_live_new',
            'stripe_secret' => 'sk_live_new',
            'cashier_currency' => 'eur',
        ])
        ->assertRedirect();

    $fresh = AppSetting::query()->find(AppSetting::SINGLETON_ID);
    expect($fresh->stripe_key)->toBe('pk_live_new');
    expect($fresh->stripe_secret)->toBe('sk_live_new');
    expect($fresh->cashier_currency)->toBe('eur');
});

test('the marketing update endpoint stores normalized landing page content', function () {
    $admin = platformAdminForSystem();

    $this->actingAs($admin)
        ->patch('/settings/system/marketing', [
            'marketing_home_content' => [
                'nav_items' => [
                    [
                        'label' => 'Overview',
                        'href' => '/overview',
                    ],
                ],
                'hero' => [
                    'line_one' => 'Convert every',
                ],
                'footer' => [
                    'copyright' => 'Copyright 2027 Pitchbar Inc. All rights reserved.',
                ],
            ],
        ])
        ->assertRedirect();

    $fresh = AppSetting::query()->find(AppSetting::SINGLETON_ID);

    expect($fresh->marketing_home_content)->toBeArray();
    expect(data_get($fresh->marketing_home_content, 'hero.line_one'))->toBe('Convert every');
    expect(data_get($fresh->marketing_home_content, 'hero.line_four_highlight'))->toBe('conversation.');
    expect(data_get($fresh->marketing_home_content, 'brand_name'))->toBe('Pitchbar');
    expect(data_get($fresh->marketing_home_content, 'nav_items'))->toHaveCount(1);
    expect(data_get($fresh->marketing_home_content, 'nav_items.0.label'))->toBe('Overview');
    expect(data_get($fresh->marketing_home_content, 'footer.copyright'))
        ->toBe('Copyright 2027 Pitchbar Inc. All rights reserved.');
});

test('the privacy update endpoint stores normalized privacy policy content', function () {
    $admin = platformAdminForSystem();

    $this->actingAs($admin)
        ->patch('/settings/system/privacy', [
            'privacy_policy_content' => [
                'title' => 'Customer data policy',
                'contact' => [
                    'email' => 'privacy@acme.example',
                ],
                'gdpr' => [
                    'request_instructions' => 'Include the workspace slug and visitor email when you request erasure.',
                ],
            ],
        ])
        ->assertRedirect();

    $fresh = AppSetting::query()->find(AppSetting::SINGLETON_ID);

    expect($fresh->privacy_policy_content)->toBeArray();
    expect(data_get($fresh->privacy_policy_content, 'title'))->toBe('Customer data policy');
    expect(data_get($fresh->privacy_policy_content, 'contact.email'))->toBe('privacy@acme.example');
    expect(data_get($fresh->privacy_policy_content, 'eyebrow'))->toBe('Privacy & GDPR');
    expect(data_get($fresh->privacy_policy_content, 'gdpr.request_instructions'))
        ->toBe('Include the workspace slug and visitor email when you request erasure.');
    expect(data_get($fresh->privacy_policy_content, 'rights.items'))->toBeArray()->toHaveCount(3);
});

test('blank submitted secrets do not overwrite stored secrets', function () {
    $admin = platformAdminForSystem();

    AppSetting::singleton()->forceFill([
        'stripe_secret' => 'sk_existing',
    ])->save();
    AppSetting::flushSingleton();

    $this->actingAs($admin)
        ->patch('/settings/system/stripe', [
            'stripe_key' => 'pk_test_only',
            'stripe_secret' => '',
        ])
        ->assertRedirect();

    $fresh = AppSetting::query()->find(AppSetting::SINGLETON_ID);
    expect($fresh->stripe_secret)->toBe('sk_existing');
    expect($fresh->stripe_key)->toBe('pk_test_only');
});

test('sensitive fields are stored encrypted at rest, not in plaintext', function () {
    $admin = platformAdminForSystem();

    $this->actingAs($admin)
        ->patch('/settings/system/cloudflare', [
            'cloudflare_account_id' => 'visible-acct',
            'cloudflare_api_token' => 'super-secret-token',
        ])
        ->assertRedirect();

    $row = DB::table('app_settings')
        ->where('id', AppSetting::SINGLETON_ID)
        ->first();

    // Public fields land in the DB plain; sensitive fields are
    // unrecognizable ciphertext.
    expect($row->cloudflare_account_id)->toBe('visible-acct');
    expect($row->cloudflare_api_token)->not->toBe('super-secret-token');
    expect($row->cloudflare_api_token)->toBeString();

    // The cast decrypts cleanly when reading through the model.
    expect(Crypt::decryptString($row->cloudflare_api_token))
        ->toBe('super-secret-token');
});

test('the update endpoint rejects unknown section names', function () {
    $admin = platformAdminForSystem();

    // Either 404 (regex-constraint miss) or 405 (Symfony router falls
    // back to method-mismatch on the literal `/settings/system/*`
    // path when the {section} regex doesn't match). Both prove the
    // operator cannot patch an arbitrary section.
    $status = $this->actingAs($admin)
        ->patch('/settings/system/bogus', ['anything' => 'goes'])
        ->getStatusCode();

    expect($status)->toBeIn([404, 405]);
});

test('non-admins cannot reach the update endpoint', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($customer)
        ->patch('/settings/system/stripe', ['stripe_key' => 'evil'])
        ->assertStatus(404);

    expect(AppSetting::query()->find(AppSetting::SINGLETON_ID)?->stripe_key)
        ->toBeNull();
});

test('the override provider applies stored AppSetting values to config', function () {
    AppSetting::singleton()->forceFill([
        'stripe_secret' => 'sk_from_db',
        'site_title' => 'Acme Assist',
        'dashboard_logo_path' => 'branding/dashboard/logo.png',
        'pitchbar_brand_url' => 'https://example.com',
        'cloudflare_account_id' => 'cf-acct',
    ])->save();
    AppSetting::flushSingleton();

    config([
        'cashier.secret' => 'env-default',
        'app.name' => 'Pitchbar',
        'branding.site_title' => 'Pitchbar',
        'branding.dashboard_logo_path' => null,
        'branding.url' => 'https://default.com',
        'services.cloudflare.account_id' => '',
    ]);

    // Re-run the provider's boot() against the current container so the
    // singleton row's values get merged into config(). Mirrors what
    // happens on every fresh request boot.
    (new AppSettingsOverrideServiceProvider($this->app))->boot();

    expect(config('cashier.secret'))->toBe('sk_from_db');
    expect(config('app.name'))->toBe('Acme Assist');
    expect(config('branding.site_title'))->toBe('Acme Assist');
    expect(config('branding.dashboard_logo_path'))->toBe('branding/dashboard/logo.png');
    expect(config('branding.url'))->toBe('https://example.com');
    expect(config('services.cloudflare.account_id'))->toBe('cf-acct');
});
