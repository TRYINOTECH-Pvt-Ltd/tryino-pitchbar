<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Models\Page;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Platform-admin CRUD for custom content pages. Lets the operator
 * publish marketing pages like /p/about, /p/company without editing
 * Blade files. Pages render through a single public route + Blade
 * view that mirrors the documentation/marketing layout chrome.
 *
 * Markdown is rendered server-side (no client-side JS), and the
 * public route hides unpublished drafts behind a 404 so drafts stay
 * invisible until the operator flips the toggle.
 */
class PageController
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $query = Page::query()->orderBy('sort_order')->latest('updated_at');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $like = "%{$q}%";
                $w->where('title', 'like', $like)
                    ->orWhere('slug', 'like', $like);
            });
        }

        $paginator = $query->paginate(25)->withQueryString();

        $rows = collect($paginator->items())->map(fn (Page $p) => [
            'id' => $p->id,
            'slug' => $p->slug,
            'title' => $p->title,
            'is_published' => $p->is_published,
            'sort_order' => $p->sort_order,
            'updated_at' => $p->updated_at?->toIso8601String(),
            'public_url' => $p->is_published ? "/p/{$p->slug}" : null,
        ]);

        return Inertia::render('admin/pages/index', [
            'pages' => $rows,
            'pagination' => Pagination::meta($paginator),
            'filters' => ['q' => $q],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/pages/create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedFor($request);
        $data['slug'] = $this->ensureUniqueSlug($data['slug'] ?? $data['title']);

        $page = Page::create($data);

        return redirect()
            ->route('admin.pages.index')
            ->with('success', "Page '{$page->title}' created.");
    }

    public function edit(Page $page): Response
    {
        return Inertia::render('admin/pages/edit', [
            'page' => [
                'id' => $page->id,
                'slug' => $page->slug,
                'title' => $page->title,
                'content_markdown' => $page->content_markdown,
                'is_published' => $page->is_published,
                'sort_order' => $page->sort_order,
                'public_url' => $page->is_published ? "/p/{$page->slug}" : null,
            ],
        ]);
    }

    public function update(Request $request, Page $page): RedirectResponse
    {
        $data = $this->validatedFor($request, $page);
        $page->update($data);

        return redirect()
            ->route('admin.pages.index')
            ->with('success', "Page '{$page->title}' updated.");
    }

    public function destroy(Page $page): RedirectResponse
    {
        $title = $page->title;
        $page->delete();

        return redirect()
            ->route('admin.pages.index')
            ->with('success', "Page '{$title}' deleted.");
    }

    /**
     * Server-side Markdown → HTML preview for the admin page editor.
     * Uses the same GFM converter + HTML-escape settings as the
     * public renderer so the preview is byte-identical to what a
     * visitor will see. JSON-only — never inserts into the DB.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content_markdown' => ['required', 'string', 'max:200000'],
        ]);

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return response()->json([
            'html' => (string) $converter->convert($data['content_markdown']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFor(Request $request, ?Page $page = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => [
                'nullable',
                'string',
                'max:120',
                'alpha_dash',
                Rule::unique('pages', 'slug')->ignore($page?->id),
            ],
            'content_markdown' => ['required', 'string', 'max:200000'],
            'is_published' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ]);
    }

    private function ensureUniqueSlug(string $source): string
    {
        $base = Str::slug($source);
        if ($base === '') {
            $base = 'page-'.Str::random(6);
        }

        $slug = $base;
        $i = 2;
        while (Page::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
