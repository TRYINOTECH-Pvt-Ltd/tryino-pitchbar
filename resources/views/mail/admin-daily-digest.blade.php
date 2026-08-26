@component('mail::message')
# Daily admin digest

**{{ $stats['period_start'] }} → {{ $stats['period_end'] }}** (last 24 hours)

@component('mail::table')
| Metric | Count |
| :--- | ---: |
| New users signed up | {{ number_format($stats['new_users']) }} |
| New workspaces created | {{ number_format($stats['new_workspaces']) }} |
| New paid subscriptions | {{ number_format($stats['new_subscriptions']) }} |
| New leads captured | {{ number_format($stats['new_leads']) }} |
| Conversations active now | {{ number_format($stats['active_conversations']) }} |
@endcomponent

@component('mail::button', ['url' => $stats['admin_url']])
Open admin dashboard
@endcomponent

You're receiving this because the **admin daily digest** is enabled in
Settings → System. Disable it any time to stop these emails.

Thanks,<br>
{{ \App\Support\AppBranding::siteTitle() }}
@endcomponent
