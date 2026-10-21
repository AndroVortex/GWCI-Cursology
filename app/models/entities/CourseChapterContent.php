<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Enums\ContentType;
use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use App\Models\Mapping\UniqueKey;
use DateTimeImmutable;

/**
 * Fila de `course_chapter_content`: video principal o recurso del capítulo.
 *
 * - main_slot es generada por MySQL (1 si is_main, si no NULL) y sostiene
 *   uq_content_main: como máximo un video principal por capítulo.
 * - size_bytes es BIGINT UNSIGNED y se representa como cadena decimal.
 */
final class CourseChapterContent extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $courseChapterId,
        protected readonly string $title,
        protected readonly ContentType $contentType,
        protected readonly ?string $contentUri,
        protected readonly ?string $textContent,
        protected readonly ?string $mimeType,
        protected readonly ?string $originalFilename,
        protected readonly ?string $sizeBytes,
        protected readonly ?int $durationSeconds,
        protected readonly bool $isMain,
        protected readonly ?int $mainSlot,
        protected readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            courseChapterId: $r->id('course_chapter_id'),
            title: $r->string('title', 200),
            contentType: $r->enum('content_type', ContentType::class),
            contentUri: $r->nullableString('content_uri', 2048),
            textContent: $r->nullableLongText('text_content'),
            mimeType: $r->nullableString('mime_type', 127),
            originalFilename: $r->nullableString('original_filename', 255),
            sizeBytes: $r->nullableBigintUnsigned('size_bytes'),
            durationSeconds: $r->nullableIntUnsigned('duration_seconds'),
            isMain: $r->bool('is_main'),
            mainSlot: $r->nullableTinyInt('main_slot', min: 1, max: 1),
            createdAt: $r->dateTime('created_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'course_chapter_content',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('course_chapter_id', 'courseChapterId', 'BIGINT UNSIGNED'),
                new Column('title', 'title', 'VARCHAR(200)'),
                new Column('content_type', 'contentType', 'VARCHAR(16)', enum: ContentType::class),
                new Column('content_uri', 'contentUri', 'VARCHAR(2048)', nullable: true),
                new Column('text_content', 'textContent', 'LONGTEXT', nullable: true),
                new Column('mime_type', 'mimeType', 'VARCHAR(127)', nullable: true),
                new Column('original_filename', 'originalFilename', 'VARCHAR(255)', nullable: true),
                new Column('size_bytes', 'sizeBytes', 'BIGINT UNSIGNED', nullable: true),
                new Column('duration_seconds', 'durationSeconds', 'INT UNSIGNED', nullable: true),
                new Column('is_main', 'isMain', 'BOOLEAN', default: 'FALSE'),
                new Column('main_slot', 'mainSlot', 'TINYINT', nullable: true, generated: true),
                new Column('created_at', 'createdAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_content_main', ['course_chapter_id', 'main_slot']),
            ],
            foreignKeys: [
                new ForeignKey('fk_content_chapter', ['course_chapter_id'], 'course_chapter', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function courseChapterId(): string { return $this->courseChapterId; }
    public function title(): string { return $this->title; }
    public function contentType(): ContentType { return $this->contentType; }
    public function contentUri(): ?string { return $this->contentUri; }
    public function textContent(): ?string { return $this->textContent; }
    public function mimeType(): ?string { return $this->mimeType; }
    public function originalFilename(): ?string { return $this->originalFilename; }
    /** BIGINT UNSIGNED como cadena decimal. */
    public function sizeBytes(): ?string { return $this->sizeBytes; }
    public function durationSeconds(): ?int { return $this->durationSeconds; }
    public function isMain(): bool { return $this->isMain; }
    /** Columna generada: 1 cuando is_main, NULL en los demás recursos. */
    public function mainSlot(): ?int { return $this->mainSlot; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
}
