@extends('marketing._layout')

@section('title', __('Terms of service — :brand', ['brand' => config('branding.site_title', 'Pitchbar')]))

@php
    $brand = config('branding.site_title', 'Pitchbar');
    $contactEmail = config('mail.from.address', 'support@example.com');
    $effectiveDate = config('branding.terms_effective_date', now()->format('F j, Y'));
@endphp

@section('content')
<section class="mx-auto max-w-3xl px-6 py-24 prose prose-slate dark:prose-invert">
    <p class="text-sm font-medium uppercase tracking-wider text-emerald-700 dark:text-emerald-400">{{ __('Legal') }}</p>
    <h1>{{ __('Terms of service') }}</h1>
    <p class="lead">
        {!! __('These terms govern your use of :brand (the &ldquo;Service&rdquo;). By creating an account, embedding the widget, or otherwise using the Service, you agree to these terms. If you do not agree, do not use the Service.', ['brand' => e($brand)]) !!}
    </p>
    <p><strong>{{ __('Effective date:') }}</strong> {{ $effectiveDate }}</p>

    <h2>{{ __('1. The Service') }}</h2>
    <p>
        {{ __(':brand is an AI-powered website chat widget and operator console. We provide hosting for your agents, knowledge ingestion, retrieval, large-language-model responses, lead capture, and an inbox where your team can take over conversations from the AI. Specific capabilities, quotas, and limits are described on the pricing page and in your active subscription.', ['brand' => $brand]) }}
    </p>

    <h2>{{ __('2. Your account') }}</h2>
    <ul>
        <li>{{ __('You must provide accurate registration information and keep it current.') }}</li>
        <li>{{ __('You are responsible for safeguarding your password and any access tokens.') }}</li>
        <li>{{ __('You are responsible for all activity under your account, including activity by workspace members you invite.') }}</li>
        <li>{{ __('You must promptly notify us of any unauthorized access or suspected breach.') }}</li>
        <li>{{ __('You must be at least 16 years of age (or the age of digital consent in your jurisdiction).') }}</li>
    </ul>

    <h2>{{ __('3. Acceptable use') }}</h2>
    <p>{{ __('You agree not to use the Service to:') }}</p>
    <ul>
        <li>{{ __('Violate any applicable law, regulation, or third-party right.') }}</li>
        <li>{{ __('Send spam, harass, defraud, or impersonate any person or entity.') }}</li>
        <li>{{ __('Distribute malware, conduct phishing, or attempt to compromise security.') }}</li>
        <li>{{ __('Embed the widget on sites operated by third parties without their permission.') }}</li>
        <li>{{ __('Reverse engineer, scrape, or attempt to derive source code beyond what is expressly permitted.') }}</li>
        <li>{{ __('Resell, sublicense, or otherwise commercialize the Service except as expressly authorized.') }}</li>
        <li>{{ __('Submit knowledge sources or visitor inputs that infringe copyright, contain unlawful content, or expose the personal data of individuals without a lawful basis.') }}</li>
    </ul>
    <p>
        {{ __('We may suspend or terminate accounts that violate this section, with or without notice, depending on severity.') }}
    </p>

    <h2>{!! __('4. Your content &amp; data') !!}</h2>
    <p>
        {!! __('You retain ownership of the data you submit to the Service: knowledge sources, agent configuration, conversation transcripts, leads, and associated metadata (&ldquo;Customer Data&rdquo;). You grant us a worldwide, non-exclusive, royalty-free license to process Customer Data solely as needed to operate, secure, support, and improve the Service for you.') !!}
    </p>
    <p>
        {{ __('You represent that you have all rights necessary to submit Customer Data and that doing so does not violate any law or third-party right.') }}
    </p>

    <h2>{!! __('5. Visitor data &amp; privacy') !!}</h2>
    <p>
        {{ __('When you embed the widget on your site, visitor messages, IP-derived signals, and any contact details they submit pass through the Service. You are the controller of that data; we are the processor. You must:') }}
    </p>
    <ul>
        <li>{{ __('Maintain a privacy notice that discloses the use of :brand and AI processing on your site.', ['brand' => $brand]) }}</li>
        <li>{{ __('Obtain any consents required by applicable law (GDPR, CCPA, equivalent).') }}</li>
        <li>{{ __('Honor data subject rights for visitors who request access, correction, or deletion.') }}</li>
    </ul>
    <p>
        {{ __('Our handling is described in our') }} <a href="{{ route('marketing.privacy') }}">{{ __('Privacy Policy') }}</a>{{ __(', which is incorporated by reference.') }}
    </p>

    <h2>{{ __('6. AI output disclaimer') }}</h2>
    <p>
        {{ __('AI responses are generated based on the knowledge sources you supply and the underlying language model. They may contain inaccuracies, omissions, or unintended outputs. You are responsible for:') }}
    </p>
    <ul>
        <li>{{ __('Reviewing your knowledge base, system prompt, and behavior rules.') }}</li>
        <li>{{ __('Configuring an appropriate confidence threshold for your use case.') }}</li>
        <li>{{ __('Reviewing transcripts and capturing leads for any business-critical interaction.') }}</li>
    </ul>
    <p>
        {{ __('The Service must not be used as the sole source of medical, legal, financial, or other regulated advice without human review.') }}
    </p>

    <h2>{!! __('7. Subscriptions, billing, &amp; refunds') !!}</h2>
    <ul>
        <li>{{ __('Paid plans renew automatically until cancelled. You may cancel from your billing settings; cancellation takes effect at the end of the current billing period.') }}</li>
        <li>{{ __('Fees are billed in advance and are non-refundable except where required by law or where we explicitly grant a refund.') }}</li>
        <li>{{ __('You authorize us and our payment processor (Stripe) to charge the payment method on file.') }}</li>
        <li>{{ __('If your usage exceeds your plan limits, we may rate-limit, block new conversations, or invite you to upgrade.') }}</li>
        <li>{{ __('We may change pricing for new billing periods with reasonable notice.') }}</li>
    </ul>

    <h2>{{ __('8. Third-party services') }}</h2>
    <p>
        {{ __('The Service integrates with third-party providers (large-language-model APIs, vector stores, payment processors, OAuth-based knowledge sources such as Notion or Google Drive, and your configured outgoing webhooks). Their availability, latency, and pricing are outside our control, and your use of those services is subject to their own terms.') }}
    </p>

    <h2>{{ __('9. Service availability') }}</h2>
    <p>
        {{ __('We aim for high availability but do not guarantee uninterrupted access. We may perform maintenance, updates, or emergency response that briefly affects the Service. We are not liable for downtime caused by third-party providers or by force majeure.') }}
    </p>

    <h2>{{ __('10. Termination') }}</h2>
    <ul>
        <li>{{ __('You may terminate by cancelling your subscription and deleting your workspace.') }}</li>
        <li>{{ __('We may suspend or terminate the Service for violation of these terms, non-payment, or to comply with applicable law.') }}</li>
        <li>{{ __('Upon termination we will retain Customer Data for a reasonable transition period (typically 30 days) before deletion.') }}</li>
    </ul>

    <h2>{{ __('11. Intellectual property') }}</h2>
    <p>
        {{ __('The Service, including software, design, and trademarks, is owned by :brand and its licensors. Nothing in these terms grants you ownership of the Service. Feedback you provide may be used by us without obligation.', ['brand' => $brand]) }}
    </p>

    <h2>{{ __('12. Disclaimers') }}</h2>
    <p>
        {!! __('THE SERVICE IS PROVIDED &ldquo;AS IS&rdquo; WITHOUT WARRANTIES OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING IMPLIED WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT. WE DO NOT WARRANT THAT THE SERVICE WILL BE ERROR-FREE OR UNINTERRUPTED, OR THAT AI OUTPUTS WILL BE ACCURATE.') !!}
    </p>

    <h2>{{ __('13. Limitation of liability') }}</h2>
    <p>
        {{ __('TO THE MAXIMUM EXTENT PERMITTED BY LAW, NEITHER PARTY WILL BE LIABLE FOR INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, OR FOR LOST PROFITS, REVENUE, GOODWILL, OR DATA, ARISING OUT OF OR RELATED TO THE SERVICE. OUR AGGREGATE LIABILITY FOR ANY CLAIM WILL NOT EXCEED THE FEES YOU PAID FOR THE SERVICE IN THE 12 MONTHS PRECEDING THE CLAIM.') }}
    </p>

    <h2>{{ __('14. Indemnification') }}</h2>
    <p>
        {{ __('You agree to defend and indemnify :brand against claims arising from (a) your Customer Data, (b) your use of the Service in violation of these terms, or (c) your violation of any law or third-party right.', ['brand' => $brand]) }}
    </p>

    <h2>{{ __('15. Changes to these terms') }}</h2>
    <p>
        {{ __('We may update these terms from time to time. Material changes will be announced via the Service or by email at least 14 days before they take effect. Continued use after the effective date constitutes acceptance.') }}
    </p>

    <h2>{{ __('16. Governing law') }}</h2>
    <p>
        {{ __('These terms are governed by the laws of the jurisdiction in which the operator of :brand is established, without regard to conflict-of-law principles. Disputes will be resolved in the courts of that jurisdiction unless required otherwise by law.', ['brand' => $brand]) }}
    </p>

    <h2>{{ __('17. Contact') }}</h2>
    <p>
        {{ __('Questions about these terms? Email') }}
        <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
    </p>

    <hr class="my-12 border-slate-200 dark:border-slate-700">
    <p class="text-xs italic text-slate-500 dark:text-slate-400">
        {{ __('Operators deploying :brand: this template is reasonable boilerplate but is not legal advice. Adjust to reflect your jurisdiction, business model, and consult counsel before relying on it in production.', ['brand' => $brand]) }}
    </p>
</section>
@endsection
