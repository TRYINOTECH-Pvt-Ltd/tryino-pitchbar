<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class WidgetManifestController
{
    public function __invoke(): JsonResponse
    {
        $path = public_path('widget/manifest.json');

        $payload = is_file($path)
            ? json_decode((string) file_get_contents($path), true) ?: $this->fallback()
            : $this->fallback();

        return response()
            ->json($payload)
            ->header('Cache-Control', 'public, max-age=60, must-revalidate');
    }

    /**
     * @return array<string, mixed>
     */
    private function fallback(): array
    {
        return [
            'version' => 'dev',
            'hash' => '',
            'file' => 'widget.js',
            'url' => '/widget/widget.js',
            'generated_at' => null,
        ];
    }
}
