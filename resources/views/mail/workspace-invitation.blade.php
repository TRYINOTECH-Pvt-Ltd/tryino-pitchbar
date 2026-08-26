@php
    /** @var \App\Models\Invitation $invitation */
@endphp
<x-mail::message>
# {{ __("You're invited to join :workspace", ['workspace' => $invitation->workspace->name]) }}

{{-- Audit 2026-05-16: pre-fix this line used `{!! __() !!}` which
    renders the locale string as markdown. Workspace name is
    admin-supplied — a name like `**hacker** [click me](javascript:…)`
    would inject markdown styling/links into the recipient's mail.
    Escape the interpolated values, keep the bold formatting on the
    locale string itself (no user data inside the ** markers). --}}
{{ __('You have been invited to join the :workspace workspace on :app as a :role.', [
    'workspace' => $invitation->workspace->name,
    'app' => \App\Support\AppBranding::siteTitle(),
    'role' => $invitation->role,
]) }}

<x-mail::button :url="$acceptUrl">
{{ __('Accept invitation') }}
</x-mail::button>

{{ __('This invitation expires on :date.', ['date' => $invitation->expires_at->toFormattedDateString()]) }}

{{ __('Thanks,') }}
{{ \App\Support\AppBranding::siteTitle() }}
</x-mail::message>
