<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Un mensaje de una conversación con su autor, la cita del mensaje al que responde y sus reacciones (sp_chat_open, sp_message_send). */
final class ChatMessage
{
    private function __construct(
        public readonly string $id,
        public readonly string $sentBy,
        public readonly string $senderName,
        public readonly string $senderAvatar,
        public readonly string $content,
        public readonly DateTimeImmutable $sentAt,
        public readonly ?string $replyTo,
        public readonly ?string $replySenderName,
        public readonly ?string $replyContent,
        public readonly ReactionSummary $reactions,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, ReactionSummary $reactions = new ReactionSummary()): self
    {
        $r = new RowReader(self::class, $row);

        return new self(
            $r->id('id'),
            $r->id('sent_by'),
            $r->string('sender_name', 350),
            $r->string('sender_avatar', 2048),
            (string) $r->nullableText('content'),
            $r->dateTime('sent_at'),
            $r->nullableId('reply_to'),
            $r->nullableString('reply_sender_name', 350),
            $r->nullableText('reply_content'),
            $reactions,
        );
    }

    public function isFrom(string $userId): bool
    {
        return $this->sentBy === $userId;
    }

    /** La cita del mensaje al que responde; '' si no responde o si ese mensaje se dio de baja. */
    public function replyExcerpt(int $maxChars = 120): string
    {
        return self::excerpt($this->replyContent, $maxChars);
    }

    /** El texto en una sola línea y recortado con "…" si pasa de $maxChars. */
    public static function excerpt(?string $text, int $maxChars): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return mb_strlen($text) <= $maxChars ? $text : rtrim(mb_substr($text, 0, $maxChars - 1)) . '…';
    }
}
