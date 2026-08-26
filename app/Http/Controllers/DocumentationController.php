<?php

namespace App\Http\Controllers;

use App\Support\DocumentationNav;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;

/**
 * Public documentation site. Renders Mintlify-style HTML pages stored as
 * Blade partials under `resources/views/documentation/pages/{slug}.blade.php`.
 * The sidebar tree lives in {@see DocumentationNav}; adding a new page is
 * two steps: drop a Blade partial in pages/, then register it in the nav.
 *
 * Public on purpose — no auth, no workspace scope, no tenancy. Anyone
 * with the URL can read it. Don't put account-specific data on these
 * pages.
 */
class DocumentationController
{
    public function show(?string $slug = null): View
    {
        $slug = $slug ?: 'welcome';
        $flat = DocumentationNav::flat();

        if (! isset($flat[$slug])) {
            abort(404);
        }

        $partial = "documentation.pages.{$slug}";

        if (! ViewFactory::exists($partial)) {
            abort(404);
        }

        return view('documentation.layout', [
            'slug' => $slug,
            'pageTitle' => $flat[$slug]['title'],
            'pageGroup' => $flat[$slug]['group'],
            'tree' => DocumentationNav::tree(),
            'neighbors' => DocumentationNav::neighbors($slug),
            'partial' => $partial,
        ]);
    }
}
