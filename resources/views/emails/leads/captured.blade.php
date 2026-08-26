@php($_localeEntry = \App\Services\I18n\LocaleCatalog::entryFor(app()->getLocale()))<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $_localeEntry['rtl'] ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('New lead — :email', ['email' => $lead->email]) }}</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
    <div style="display:none;max-height:0;overflow:hidden;">
        {{ __('New lead :email for :agent — reply within 5 minutes for best conversion.', ['email' => $lead->email, 'agent' => $agentName]) }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,0.06);">
                    {{-- Header --}}
                    <tr>
                        <td style="padding:24px 32px 16px 32px;background:linear-gradient(135deg,#10b981 0%,#0ea5e9 100%);color:#ffffff;">
                            <p style="margin:0;font-size:13px;font-weight:600;letter-spacing:0.4px;text-transform:uppercase;opacity:0.9;">
                                {{ __('New lead captured') }}
                            </p>
                            <h1 style="margin:6px 0 0 0;font-size:22px;font-weight:600;line-height:1.3;">
                                {{ $lead->email }}
                            </h1>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:24px 32px;">
                            <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#334155;">
                                {!! __('A visitor on <strong>:agent</strong> just left their details. Reply within 5 minutes for the best chance of conversion — that\'s the window where prospects are still warm.', ['agent' => e($agentName)]) !!}
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px 0;border:1px solid #e2e8f0;border-radius:10px;">
                                @foreach($rows as $label => $value)
                                    @if($value)
                                        <tr>
                                            <td style="padding:10px 14px;font-size:12px;color:#64748b;width:88px;border-bottom:1px solid #f1f5f9;">{{ $label }}</td>
                                            <td style="padding:10px 14px;font-size:14px;color:#0f172a;font-weight:500;border-bottom:1px solid #f1f5f9;">{{ $value }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                            </table>

                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:#0f172a;border-radius:8px;">
                                        <a href="{{ $inboxUrl }}" style="display:inline-block;padding:11px 20px;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;">
                                            {{ __('Open in inbox') }} →
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:24px 0 0 0;font-size:12px;color:#94a3b8;">
                                {{ __("You're receiving this because you're an owner or admin of the :workspace workspace.", ['workspace' => $workspaceName]) }}
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:14px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#94a3b8;text-align:center;">
                            {{ $brandName ?? __('Sales AI for any website') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
