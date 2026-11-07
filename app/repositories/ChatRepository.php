<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Dto\ChatMessage;
use App\Models\Dto\ChatSummary;
use App\Models\Dto\ChatThread;
use App\Models\Entities\Chat;

/** Conversaciones entre alumno e instructor y sus mensajes, exclusivamente mediante SP. */
final class ChatRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    /**
     * @param ?string $studentId null cuando la inicia el alumno
     * @return array{0: Chat, 1: bool} [la conversación, si se creó en esta llamada]
     */
    public function start(string $userId, string $courseId, ?string $studentId): array
    {
        $row = $this->requiredRow('sp_chat_start', [$userId, $courseId, $studentId]);
        $created = (bool) $row['created'];
        unset($row['created']);

        return [Chat::fromRow($row), $created];
    }

    /** @return list<ChatSummary> */
    public function list(string $userId): array
    {
        return array_map(ChatSummary::fromRow(...), $this->resultSets('sp_chat_list', [$userId])[0] ?? []);
    }

    public function open(string $userId, string $chatId, ?string $afterId, int $limit): ChatThread
    {
        return ChatThread::fromResultSets($this->resultSets('sp_chat_open', [$userId, $chatId, $afterId, $limit]));
    }

    public function send(string $userId, string $chatId, string $content, ?string $replyTo): ChatMessage
    {
        return ChatMessage::fromRow($this->requiredRow('sp_message_send', [$userId, $chatId, $content, $replyTo]));
    }

    public function unreadTotal(string $userId): int
    {
        return (int) $this->requiredRow('sp_chat_unread_total', [$userId])['unread_total'];
    }
}
