<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Super-admin distribution surface for the WordPress companion
 * plugin. Lists every built zip in `storage/app/private/wp-plugin-builds/`,
 * streams a specific version on download, and triggers the
 * `pitchbar:build-wp-plugin` artisan command on demand so operators
 * can produce a fresh artifact without ssh.
 *
 * Gated by the `super_admin` middleware in routes/web.php — tenants
 * never reach these endpoints.
 */
class WordPressDistributionController extends Controller
{
    private const VERSION_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.]+)?$/';

    public function index(): Response
    {
        return Inertia::render('admin/integrations/wordpress', [
            'builds' => $this->listBuilds(),
            'source_version' => $this->sourceVersion(),
        ]);
    }

    public function build(Request $request): RedirectResponse
    {
        $exitCode = Artisan::call('pitchbar:build-wp-plugin');

        if ($exitCode !== 0) {
            return back()->with('error', 'Build failed. Check the application log for details.');
        }

        return back()->with('success', 'Plugin packaged. Download it below.');
    }

    public function download(string $version): BinaryFileResponse
    {
        abort_unless((bool) preg_match(self::VERSION_PATTERN, $version), 404);

        $path = $this->buildsDir().DIRECTORY_SEPARATOR.'pitchbar-'.$version.'.zip';
        abort_unless(is_file($path), 404);

        return response()->download($path, 'pitchbar-'.$version.'.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * @return list<array{version: string, filename: string, size: int, modified_at: string, download_url: string}>
     */
    private function listBuilds(): array
    {
        $dir = $this->buildsDir();
        if (! is_dir($dir)) {
            return [];
        }

        $rows = [];
        foreach (File::files($dir) as $file) {
            $name = $file->getFilename();
            if (! preg_match('/^pitchbar-([0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.]+)?)\.zip$/', $name, $matches)) {
                continue;
            }
            $version = $matches[1];
            $rows[] = [
                'version' => $version,
                'filename' => $name,
                'size' => $file->getSize(),
                'modified_at' => date(DATE_ATOM, $file->getMTime()),
                'download_url' => route('admin.integrations.wordpress.download', ['version' => $version]),
            ];
        }

        usort($rows, fn ($a, $b) => version_compare($b['version'], $a['version']));

        return $rows;
    }

    private function buildsDir(): string
    {
        return storage_path('app/private/wp-plugin-builds');
    }

    private function sourceVersion(): ?string
    {
        $mainFile = base_path('wp-plugin/pitchbar/pitchbar.php');
        if (! is_file($mainFile)) {
            return null;
        }
        $contents = (string) file_get_contents($mainFile);
        if (preg_match('/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.]+)?)/m', $contents, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
