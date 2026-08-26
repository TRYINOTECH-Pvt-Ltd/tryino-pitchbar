<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Your plan was changed</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.5; color: #0f172a; max-width: 640px; margin: 0 auto; padding: 24px;">

    <h2 style="margin-top: 0;">Your plan has been updated</h2>

    <p>
        Hi {{ $workspace?->owner?->name ?? 'there' }},
    </p>

    <p>
        Your subscription plan was changed. The new plan is effective
        immediately, and any price difference is prorated by Stripe on
        your next invoice.
    </p>

    <table style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <tr>
            <td style="padding: 8px 12px; border-bottom: 1px solid #e2e8f0; color: #64748b; width: 180px;">Workspace</td>
            <td style="padding: 8px 12px; border-bottom: 1px solid #e2e8f0;">{{ $workspace?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 12px; border-bottom: 1px solid #e2e8f0; color: #64748b;">Previous plan</td>
            <td style="padding: 8px 12px; border-bottom: 1px solid #e2e8f0;">{{ $previousPlanName }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 12px; border-bottom: 1px solid #e2e8f0; color: #64748b;">New plan</td>
            <td style="padding: 8px 12px; border-bottom: 1px solid #e2e8f0;"><strong>{{ $newPlanName }}</strong></td>
        </tr>
    </table>

    <p style="margin-top: 24px;">
        You can review your subscription, card, and invoices any time on
        your <a href="{{ $billingUrl }}">billing page</a>. If you didn't
        make this change, reply to this email and we'll help sort it out.
    </p>

    <p style="margin-top: 32px; color: #64748b; font-size: 13px;">
        — The {{ $brand }} team
    </p>
</body>
</html>
