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
 * Fila de `course_opinion`: una opinión final editable por alumno y curso.
 * Baja lógica con active/deactivated_by/deactivated_at/reason: la fila se
 * conserva para mostrar "Comentario eliminado" sin publicar su contenido.
 */
final class CourseOpinion extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $courseId,
        protected readonly string $commentedBy,
        protected readonly ?string $opinion,
        protected readonly ?string $multimediaContent,
        protected readonly DateTimeImmutable $commentedAt,
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
            courseId: $r->id('course_id'),
            commentedBy: $r->id('commented_by'),
            opinion: $r->nullableText('opinion'),
            multimediaContent: $r->nullableString('multimedia_content', 2048),
            commentedAt: $r->dateTime('commented_at'),
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
            table: 'course_opinion',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('course_id', 'courseId', 'BIGINT UNSIGNED'),
                new Column('commented_by', 'commentedBy', 'BIGINT UNSIGNED'),
                new Column('opinion', 'opinion', 'TEXT', nullable: true),
                new Column('multimedia_content', 'multimediaContent', 'VARCHAR(2048)', nullable: true),
                new Column('commented_at', 'commentedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('updated_at', 'updatedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)', onUpdate: 'CURRENT_TIMESTAMP(6)'),
                new Column('active', 'active', 'BOOLEAN', default: 'TRUE'),
                new Column('deactivated_by', 'deactivatedBy', 'BIGINT UNSIGNED', nullable: true),
                new Column('deactivated_at', 'deactivatedAt', 'DATETIME(6)', nullable: true),
                new Column('reason', 'reason', 'VARCHAR(500)', nullable: true),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_opinion_user', ['commented_by', 'course_id']),
            ],
            foreignKeys: [
                new ForeignKey('fk_opinion_enrollment', ['commented_by', 'course_id'], 'user_courses', ['user_id', 'course_id']),
                new ForeignKey('fk_opinion_deactivator', ['deactivated_by'], 'app_user', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function courseId(): string { return $this->courseId; }
    public function commentedBy(): string { return $this->commentedBy; }
    public function opinion(): ?string { return $this->opinion; }
    public function multimediaContent(): ?string { return $this->multimediaContent; }
    public function commentedAt(): DateTimeImmutable { return $this->commentedAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function active(): bool { return $this->active; }
    public function deactivatedBy(): ?string { return $this->deactivatedBy; }
    public function deactivatedAt(): ?DateTimeImmutable { return $this->deactivatedAt; }
    public function reason(): ?string { return $this->reason; }
}
