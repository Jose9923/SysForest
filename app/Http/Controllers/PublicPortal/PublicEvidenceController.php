<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicPortal;

use App\Services\PublicPortal\PublicEvidenceDeliveryService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Controllers\Controller;

class PublicEvidenceController extends Controller
{
    public function __invoke(Request $request, string $evidence, PublicEvidenceDeliveryService $delivery): Response
    {
        $image = $delivery->image($evidence);
        $response = response($image['bytes'], 200, ['Content-Type' => 'image/webp',
            'Content-Disposition' => 'inline; filename="arbol.webp"',
            'Cache-Control' => 'public, max-age=0, must-revalidate']);
        $response->setEtag($image['etag']);
        $response->isNotModified($request);

        return $response;
    }
}
