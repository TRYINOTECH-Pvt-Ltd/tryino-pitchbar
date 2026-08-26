@extends('marketing._layout')

@section('title', __(':title — Pitchbar', ['title' => $content['title'] ?? __('Privacy policy')]))

@section('content')
@php
    $contact = data_get($content, 'contact', []);
    $collection = data_get($content, 'collection', []);
    $usage = data_get($content, 'usage', []);
    $retention = data_get($content, 'retention', []);
    $rights = data_get($content, 'rights', []);
    $gdpr = data_get($content, 'gdpr', []);
@endphp
<section class="mx-auto max-w-3xl px-6 py-24 prose">
    <p><strong>{{ data_get($content, 'eyebrow') }}</strong></p>
    <h1>{{ data_get($content, 'title') }}</h1>
    <p>{{ data_get($content, 'summary') }}</p>
    <p><strong>{{ __('Effective date:') }}</strong> {{ data_get($content, 'effective_date') }}</p>

    <h2>{{ __('Contact') }}</h2>
    <p>
        {{ data_get($contact, 'team_name') }}<br>
        <a href="mailto:{{ data_get($contact, 'email') }}">{{ data_get($contact, 'email') }}</a><br>
        {{ data_get($contact, 'response_sla') }}
    </p>

    <h2>{{ __('What we collect') }}</h2>
    <p>{{ data_get($collection, 'summary') }}</p>
    <ul>
        @foreach ((array) data_get($collection, 'items', []) as $item)
            <li>{{ $item }}</li>
        @endforeach
    </ul>

    <h2>{{ __('How we use data') }}</h2>
    <p>{{ data_get($usage, 'summary') }}</p>
    <ul>
        @foreach ((array) data_get($usage, 'items', []) as $item)
            <li>{{ $item }}</li>
        @endforeach
    </ul>

    <h2>{{ __('Retention') }}</h2>
    <p>{{ data_get($retention, 'summary') }}</p>
    <ul>
        @foreach ((array) data_get($retention, 'items', []) as $item)
            <li>{{ $item }}</li>
        @endforeach
    </ul>

    <h2>{{ __('Your rights') }}</h2>
    <p>{{ data_get($rights, 'summary') }}</p>
    <ul>
        @foreach ((array) data_get($rights, 'items', []) as $item)
            <li>{{ $item }}</li>
        @endforeach
    </ul>

    <h2>{{ __('Data export & deletion (GDPR)') }}</h2>
    <p>{{ data_get($gdpr, 'summary') }}</p>
    <p><strong>{{ __('Request contact:') }}</strong> <a href="mailto:{{ data_get($gdpr, 'request_email') }}">{{ data_get($gdpr, 'request_email') }}</a></p>
    <p>{{ data_get($gdpr, 'request_instructions') }}</p>
</section>
@endsection
