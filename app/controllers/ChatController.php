<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Presenters\ChatPresenter;
use App\Http\Request;
use App\Models\Entities\AppUser;
use App\Services\ChatService;

/** Mensajes entre alumno e instructor: lista, inicio, lectura (con sondeo) y envío. */
final class ChatController
{
    public function __construct(private readonly ChatService $chats)
    {
    }

    /** GET /api/chats */
    public function list(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return JsonResponse::data([
            'chats' => array_map(ChatPresenter::summary(...), $this->chats->list($user)),
            'unreadTotal' => $this->chats->unreadTotal($user),
        ]);
    }

    /** POST /api/chats ({curso}; el instructor también {alumno}): 201 si la crea, 200 si ya existía */
    public function start(Request $request): JsonResponse
    {
        [$chat, $created] = $this->chats->start($this->user($request), $request->json());

        return JsonResponse::data(['chat' => ['id' => $chat->id(), 'url' => ChatPresenter::url($chat->id())]], $created ? 201 : 200);
    }

    /** GET /api/chats/{id}/messages?after= : los mensajes (o solo los posteriores a after) y la marca leída */
    public function messages(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $after = $request->queryParams()['after'] ?? null;
        $thread = $this->chats->open($user, (string) $request->attribute('id'), is_string($after) ? $after : null);

        return JsonResponse::data(ChatPresenter::thread($thread, $user->id()));
    }

    /** POST /api/chats/{id}/messages ({texto, responde_a?}) */
    public function send(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $message = $this->chats->send($user, (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['message' => ChatPresenter::message($message, $user->id())], 201);
    }

    private function user(Request $request): AppUser
    {
        /** @var AppUser */
        return $request->attribute('user');
    }
}
