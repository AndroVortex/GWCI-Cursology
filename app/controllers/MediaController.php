<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Models\Entities\AppUser;
use App\Services\MediaService;

/** POST /api/media/upload-ticket: firma una subida directa del navegador a Cloudinary. */
final class MediaController
{
    public function __construct(private readonly MediaService $media)
    {
    }

    public function ticket(Request $request): JsonResponse
    {
        $body = $request->json();
        /** @var AppUser $user */
        $user = $request->attribute('user');

        return JsonResponse::data($this->media->ticket($user, is_string($body['purpose'] ?? null) ? $body['purpose'] : ''));
    }
}
