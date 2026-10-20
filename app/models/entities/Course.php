<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use DateTimeImmutable;

/**
 * Fila de `course`.
 *
 * - created_by es el instructor propietario.
 * - published_at NULL significa borrador.
 * - full_course_price: NULL = suma de los niveles, '0.00' = gratis, > 0 = precio de paquete.
 */
final class Course extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $title,
        protected readonly string $description,
        protected readonly string $coverImage,
        protected readonly string $onboardingVideo,
        protected readonly ?string $certificateTemplate,
        protected readonly ?string $certifierName,
        protected readonly ?string $fullCoursePrice,
        protected readonly string $currency,
        protected readonly bool $active,
        protected readonly string $createdBy,
        protected readonly DateTimeImmutable $createdAt,
        protected readonly ?string $updatedBy,
        protected readonly DateTimeImmutable $updatedAt,
        protected readonly ?DateTimeImmutable $publishedAt,
        protected readonly ?DateTimeImmutable $deactivatedAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            title: $r->string('title', 200),
            description: $r->text('description'),
            coverImage: $r->string('cover_image', 2048),
            onboardingVideo: $r->string('onboarding_video', 2048),
            certificateTemplate: $r->nullableString('certificate_template', 2048),
            certifierName: $r->nullableString('certifier_name', 300),
            fullCoursePrice: $r->nullableDecimal('full_course_price', 12, 2),
            currency: $r->string('currency', 3),
            active: $r->bool('active'),
            createdBy: $r->id('created_by'),
            createdAt: $r->dateTime('created_at'),
            updatedBy: $r->nullableId('updated_by'),
            updatedAt: $r->dateTime('updated_at'),
            publishedAt: $r->nullableDateTime('published_at'),
            deactivatedAt: $r->nullableDateTime('deactivated_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'course',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('title', 'title', 'VARCHAR(200)'),
                new Column('description', 'description', 'TEXT'),
                new Column('cover_image', 'coverImage', 'VARCHAR(2048)'),
                new Column('onboarding_video', 'onboardingVideo', 'VARCHAR(2048)'),
                new Column('certificate_template', 'certificateTemplate', 'VARCHAR(2048)', nullable: true),
                new Column('certifier_name', 'certifierName', 'VARCHAR(300)', nullable: true),
                new Column('full_course_price', 'fullCoursePrice', 'DECIMAL(12,2)', nullable: true),
                new Column('currency', 'currency', 'CHAR(3)', default: "'MXN'"),
                new Column('active', 'active', 'BOOLEAN', default: 'TRUE'),
                new Column('created_by', 'createdBy', 'BIGINT UNSIGNED'),
                new Column('created_at', 'createdAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('updated_by', 'updatedBy', 'BIGINT UNSIGNED', nullable: true),
                new Column('updated_at', 'updatedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)', onUpdate: 'CURRENT_TIMESTAMP(6)'),
                new Column('published_at', 'publishedAt', 'DATETIME(6)', nullable: true),
                new Column('deactivated_at', 'deactivatedAt', 'DATETIME(6)', nullable: true),
            ],
            primaryKey: ['id'],
            foreignKeys: [
                new ForeignKey('fk_course_creator', ['created_by'], 'app_user', ['id']),
                new ForeignKey('fk_course_updater', ['updated_by'], 'app_user', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function title(): string { return $this->title; }
    public function description(): string { return $this->description; }
    public function coverImage(): string { return $this->coverImage; }
    public function onboardingVideo(): string { return $this->onboardingVideo; }
    public function certificateTemplate(): ?string { return $this->certificateTemplate; }
    public function certifierName(): ?string { return $this->certifierName; }
    /** DECIMAL(12,2) exacto como cadena; NULL = se calcula con los niveles. */
    public function fullCoursePrice(): ?string { return $this->fullCoursePrice; }
    public function currency(): string { return $this->currency; }
    public function active(): bool { return $this->active; }
    public function createdBy(): string { return $this->createdBy; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedBy(): ?string { return $this->updatedBy; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function publishedAt(): ?DateTimeImmutable { return $this->publishedAt; }
    public function deactivatedAt(): ?DateTimeImmutable { return $this->deactivatedAt; }
}
