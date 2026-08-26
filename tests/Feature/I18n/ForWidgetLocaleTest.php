<?php

use App\Services\I18n\LocaleResolver;
use Illuminate\Http\Request;

/**
 * forWidget() precedence: an explicit page locale (the widget's
 * `data-locale` / `<html lang>`) wins over the agent's default, so a
 * widget embedded on a translated page follows the page. Falls back to
 * the agent default, then Accept-Language, then "en".
 */
function widgetRequest(string $acceptLanguage = ''): Request
{
    $request = Request::create('/api/v1/widget/init', 'POST');
    if ($acceptLanguage !== '') {
        $request->headers->set('Accept-Language', $acceptLanguage);
    }

    return $request;
}

test('an explicit page locale overrides the agent default', function () {
    $resolver = new LocaleResolver;

    expect($resolver->forWidget(widgetRequest(), 'en', 'nl'))->toBe('nl');
});

test('a regional explicit tag degrades to its primary subtag', function () {
    $resolver = new LocaleResolver;

    expect($resolver->forWidget(widgetRequest(), 'en', 'nl-NL'))->toBe('nl')
        ->and($resolver->forWidget(widgetRequest(), 'en', 'pt-BR'))->toBe('pt-BR'); // pt-BR ships its own file
});

test('an unsupported or empty explicit locale falls back to the agent default', function () {
    $resolver = new LocaleResolver;

    expect($resolver->forWidget(widgetRequest(), 'de', 'zz'))->toBe('de')
        ->and($resolver->forWidget(widgetRequest(), 'de', ''))->toBe('de')
        ->and($resolver->forWidget(widgetRequest(), 'de', null))->toBe('de');
});

test('with no explicit or agent locale, Accept-Language then en win', function () {
    $resolver = new LocaleResolver;

    expect($resolver->forWidget(widgetRequest('fr-FR,fr;q=0.9'), 'zz', 'qq'))->toBe('fr')
        ->and($resolver->forWidget(widgetRequest(), 'zz', 'qq'))->toBe('en');
});
