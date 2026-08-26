<?php

use App\Enums\PlatformRole;
use App\Models\AppSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\AppBranding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function darkLogoSuperAdmin(): User
{
    $user = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $ws = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $ws->id])->save();

    return $user;
}

test('AppBranding::shared() exposes dark logo URLs', function () {
    $shared = AppBranding::shared();

    expect($shared)->toHaveKeys([
        'header_logo_dark_url',
        'footer_logo_dark_url',
        'dashboard_logo_dark_url',
    ]);
});

test('dark logo URLs are null on a fresh install', function () {
    $shared = AppBranding::shared();

    expect($shared['header_logo_dark_url'])->toBeNull();
    expect($shared['footer_logo_dark_url'])->toBeNull();
    expect($shared['dashboard_logo_dark_url'])->toBeNull();
});

test('super_admin can upload a dark header logo and AppBranding exposes its URL', function () {
    Storage::fake('public');

    $admin = darkLogoSuperAdmin();
    $file = UploadedFile::fake()->image('header-dark.png', 800, 200);

    $this->actingAs($admin)
        ->patch('/settings/system/branding', [
            'site_title' => 'Acme',
            'header_logo_dark' => $file,
            'header_brand_display' => 'logo_text',
            'footer_brand_display' => 'logo_text',
            'dashboard_brand_display' => 'logo_text',
        ])
        ->assertRedirect();

    $settings = AppSetting::singleton();
    expect($settings->header_logo_dark_path)
        ->not->toBeNull()
        ->and($settings->header_logo_dark_path)->toStartWith('branding/');

    $shared = AppBranding::shared();
    expect($shared['header_logo_dark_url'])->not->toBeNull();
});

test('uploading a new dark logo replaces the old file on disk', function () {
    Storage::fake('public');

    $admin = darkLogoSuperAdmin();

    $first = UploadedFile::fake()->image('first.png', 100, 100);
    $this->actingAs($admin)
        ->patch('/settings/system/branding', [
            'site_title' => 'Acme',
            'header_logo_dark' => $first,
            'header_brand_display' => 'logo_text',
            'footer_brand_display' => 'logo_text',
            'dashboard_brand_display' => 'logo_text',
        ])
        ->assertRedirect();

    $firstPath = AppSetting::singleton()->header_logo_dark_path;
    Storage::disk('public')->assertExists($firstPath);

    $second = UploadedFile::fake()->image('second.png', 100, 100);
    $this->actingAs($admin)
        ->patch('/settings/system/branding', [
            'site_title' => 'Acme',
            'header_logo_dark' => $second,
            'header_brand_display' => 'logo_text',
            'footer_brand_display' => 'logo_text',
            'dashboard_brand_display' => 'logo_text',
        ])
        ->assertRedirect();

    Storage::disk('public')->assertMissing($firstPath);
    Storage::disk('public')->assertExists(AppSetting::singleton()->header_logo_dark_path);
});
