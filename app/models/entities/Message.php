<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use App\Models\Mapping\UniqueKey;
use DateTimeImmutable;

/**
 * Fila de `message`: mensaje dentro de un chat.
 *
 * reply_to es solo un ID: la entidad no carga el mensaje original ni arma hilos.
 * fk_message_reply obliga a que la respuesta esté en el mismo chat.
 * Baja lógica con active/deactivated_by/deactivated_at/reason.
 */
final class Message extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $chatId,
        protected readonly string $sentBy,
        protected readonly ?string $content,
        protected readonly ?string $multimediaContent,
        protected readonly ?string $replyTo,
        protected readonly DateTimeImmutable $sentAt,
        protected readonly DateTimeImmutable $updatedAt,
        protected readonly bool $active,
        protected readonly ?string $deactivatedBy,
        protected readonly ?DateTimeImmutable $deactivatedAt,
        protected readonly ?string $reason,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            chatId: $r->id('chat_id'),
            sentBy: $r->id('sent_by'),
            content: $r->nullableText('content'),
            multimediaContent: $r->nullableString('multimedia_content', 2048),
            replyTo: $r->nullableId('reply_to'),
            sentAt: $r->dateTime('sent_at'),
            updatedAt: $r->dateTime('updated_at'),
            active: $r->bool('active'),
            deactivatedBy: $r->nullableId('deactivated_by'),
            deactivatedAt: $r->nullableDateTime('deactivated_at'),
            reason: $r->nullableString('reason', 500),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'message',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('chat_id', 'chatId', 'BIGINT UNSIGNED'),
                new Column('sent_by', 'sentBy', 'BIGINT UNSIGNED'),
                new Column('content', 'content', 'TEXT', nullable: true),
                new Column('multimedia_content', 'multimediaContent', 'VARCHAR(2048)', nullable: true),
                new Column('reply_to', 'replyTo', 'BIGINT UNSIGNED', nullable: true),
                new Column('sent_at', 'sentAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('updated_at', 'updatedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)', onUpdate: 'CURRENT_TIMESTAMP(6)'),
                new Column('active', 'active', 'BOOLEAN', default: 'TRUE'),
                new Column('deactivated_by', 'deactivatedBy', 'BIGINT UNSIGNED', nullable: true),
                new Column('deactivated_at', 'deactivatedAt', 'DATETIME(6)', nullable: true),
                new Column('reason', 'reason', 'VARCHAR(500)', nullable: true),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_message_chat', ['id', 'chat_id']),
            ],
            foreignKeys: [
                new ForeignKey('fk_message_chat', ['chat_id'], 'chat', ['id']),
                new ForeignKey('fk_message_sender', ['sent_by'], 'app_user', ['id']),
                new ForeignKey('fk_message_reply', ['reply_to', 'chat_id'], 'message', ['id', 'chat_id']),
                new ForeignKey('fk_message_deactivator', ['deactivated_by'], 'app_user', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function chatId(): string { return $this->chatId; }
    public function sentBy(): string { return $this->sentBy; }
    public function content(): ?string { return $this->content; }
    public function multimediaContent(): ?string { return $this->multimediaContent; }
    public function replyTo(): ?string { return $this->replyTo; }
    public function sentAt(): DateTimeImmutable { return $this->sentAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function active(): bool { return $this->active; }
    public function deactivatedBy(): ?string { return $this->deactivatedBy; }
    public function deactivatedAt(): ?DateTimeImmutable { return $this->deactivatedAt; }
    public function reason(): ?string { return $this->reason; }
}
