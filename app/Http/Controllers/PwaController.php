<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class PwaController extends Controller
{
    public function shell(): View
    {
        $path = public_path('build/manifest.json');

        return view('offline', ['buildVersion' => is_file($path) ? hash_file('sha256', $path) : 'development']);
    }

    public function manifest(): JsonResponse
    {
        return response()->json([
            'id' => '/offline', 'name' => 'Nthanda Lesson Studio', 'short_name' => 'Nthanda',
            'description' => 'Download, learn and create lessons offline. Review your changes before syncing.',
            'start_url' => '/offline', 'scope' => '/', 'display' => 'standalone',
            'background_color' => '#fafbf9', 'theme_color' => '#216851',
            'icons' => [['src' => '/branding/nthanda-logo.png', 'sizes' => '1920x1920', 'type' => 'image/png', 'purpose' => 'any']],
        ])->header('Content-Type', 'application/manifest+json');
    }

    public function assets(): JsonResponse
    {
        $path = public_path('build/manifest.json');
        abort_unless(is_file($path), 503, 'Build the frontend before preparing the offline app.');
        $manifest = json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        $seen = [];
        $urls = ['/offline', '/manifest.webmanifest', '/branding/nthanda-logo.png', '/offline-range.js'];
        $collect = function (string $key) use (&$collect, &$seen, &$urls, $manifest): void {
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $entry = $manifest[$key] ?? null;
            abort_unless(is_array($entry), 503, 'The offline frontend is not in this build.');
            $urls[] = '/build/'.$entry['file'];
            foreach ($entry['css'] ?? [] as $css) {
                $urls[] = '/build/'.$css;
            }
            foreach (array_merge($entry['imports'] ?? [], $entry['dynamicImports'] ?? []) as $import) {
                $collect($import);
            }
        };
        $collect('resources/js/offline.tsx');

        return response()->json(['version' => hash_file('sha256', $path), 'urls' => array_values(array_unique($urls))])->header('Cache-Control', 'no-store');
    }
}
