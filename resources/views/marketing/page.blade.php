@extends('marketing._layout')

@section('title', __(':title — :brand', ['title' => $page['title'], 'brand' => \App\Support\AppBranding::siteTitle()]))

@section('content')
<section class="mx-auto max-w-3xl px-6 py-24">
    <div class="page-content">
        <h1>{{ $page['title'] }}</h1>
        {!! $page['html'] !!}
        @if ($page['updated_at'])
            <hr>
            <p style="font-size: 0.75rem; color: rgb(100 116 139);">
                {{ __('Last updated:') }}
                {{ \Carbon\Carbon::parse($page['updated_at'])->toFormattedDateString() }}
            </p>
        @endif
    </div>
</section>
@endsection
