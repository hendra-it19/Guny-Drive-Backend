<?php

namespace App\Http\Controllers;

use App\Enums\ThumbnailPreset;
use App\Services\ThumbnailService;
use Illuminate\Http\Response;

class ThumbnailController extends Controller
{
    public function defaultPreset(string $preset, ThumbnailService $thumbnails): Response
    {
        $resolved = ThumbnailPreset::tryFrom(strtolower($preset));
        if ($resolved === null) {
            abort(404);
        }

        return response($thumbnails->renderDefaultSvg($resolved), 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
