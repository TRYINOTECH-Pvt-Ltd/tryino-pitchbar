@extends('kb.layout')

@section('title', $site_title . ' — Knowledge base')
@section('description', 'Browse curated help articles for ' . $site_title . '.')
@section('crumb', 'Knowledge base')
@section('subtitle', 'Curated articles + how-tos. New visitors land here from search; existing ones land here from chat citations.')

@section('content')
    @if($articles->isEmpty())
        <p>{{ __('No articles published yet.') }}</p>
    @else
        <ul class="article-list">
            @foreach($articles as $article)
                <li>
                    <a href="/kb/{{ $workspace->slug }}/{{ $article->slug }}">
                        {{ $article->displayTitle() }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
