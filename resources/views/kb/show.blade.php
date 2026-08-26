@extends('kb.layout')

@section('title', $article->displayTitle() . ' — ' . $site_title)
@section('description', \Illuminate\Support\Str::limit(strip_tags($article->answer), 158))
@section('crumb', $article->displayTitle())
@section('subtitle', __('Knowledge base article.'))

@section('content')
    <p class="crumb">
        <a href="/kb/{{ $workspace->slug }}">← {{ __('All articles') }}</a>
    </p>
    <h1>{{ $article->displayTitle() }}</h1>
    {{--
        The answer body is operator-curated markdown rendered to HTML
        for the public visitor. Use the SafeMarkdown helper — NOT
        Illuminate\Support\Str::markdown — because the latter ships
        with html_input='allow' + allow_unsafe_links=true, meaning a
        stored payload like `<img onerror=...>` or `[x](javascript:...)`
        becomes live XSS on every visitor browser. SafeMarkdown swaps
        in html_input='strip' + allow_unsafe_links=false.
    --}}
    <div class="answer">
        {!! \App\Support\SafeMarkdown::render((string) $article->answer) !!}
    </div>
@endsection
