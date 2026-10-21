<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Enums\CertificateStatus;
use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use App\Models\Mapping\UniqueKey;
use DateTimeImmutable;

/**
 * Fila de `certificate`: diploma con los datos congelados al terminar el curso.
 * completed_at_snapshot (fecha de terminación) no es lo mismo que issued_at
 * (fecha de emisión del documento).
 */
final class Certificate extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $userId,
        protected readonly string $courseId,
        protected readonly string $issuedBy,
        protected readonly string $verificationCode,
        protected readonly string $studentNameSnapshot,
        protected readonly string $courseTitleSnapshot,
        protected readonly string $certifierNameSnapshot,
        protected readonly DateTimeImmutable $completedAtSnapshot,
        protected readonly string $templateSnapshot,
        protected readonly CertificateStatus $status,
        protected readonly ?string $documentUri,
        protected readonly DateTimeImmutable $createdAt,
        protected readonly ?DateTimeImmutable $issuedAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            userId: $r->id('user_id'),
            courseId: $r->id('course_id'),
            issuedBy: $r->id('issued_by'),
            verificationCode: $r->string('verification_code', 36),
            studentNameSnapshot: $r->string('student_name_snapshot', 350),
            courseTitleSnapshot: $r->string('course_title_snapshot', 200),
            certifierNameSnapshot: $r->string('certifier_name_snapshot', 300),
            completedAtSnapshot: $r->dateTime('completed_at_snapshot'),
            templateSnapshot: $r->string('template_snapshot', 2048),
            status: $r->enum('status', CertificateStatus::class),
            documentUri: $r->nullableString('document_uri', 2048),
            createdAt: $r->dateTime('created_at'),
            issuedAt: $r->nullableDateTime('issued_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'certificate',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('user_id', 'userId', 'BIGINT UNSIGNED'),
                new Column('course_id', 'courseId', 'BIGINT UNSIGNED'),
                new Column('issued_by', 'issuedBy', 'BIGINT UNSIGNED'),
                new Column('verification_code', 'verificationCode', 'CHAR(36)'),
                new Column('student_name_snapshot', 'studentNameSnapshot', 'VARCHAR(350)'),
                new Column('course_title_snapshot', 'courseTitleSnapshot', 'VARCHAR(200)'),
                new Column('certifier_name_snapshot', 'certifierNameSnapshot', 'VARCHAR(300)'),
                new Column('completed_at_snapshot', 'completedAtSnapshot', 'DATETIME(6)'),
                new Column('template_snapshot', 'templateSnapshot', 'VARCHAR(2048)'),
                new Column('status', 'status', 'VARCHAR(12)', default: "'pending'", enum: CertificateStatus::class),
                new Column('document_uri', 'documentUri', 'VARCHAR(2048)', nullable: true),
                new Column('created_at', 'createdAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('issued_at', 'issuedAt', 'DATETIME(6)', nullable: true),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_certificate_enrollment', ['user_id', 'course_id']),
                new UniqueKey('uq_certificate_code', ['verification_code']),
            ],
            foreignKeys: [
                new ForeignKey('fk_certificate_enrollment', ['user_id', 'course_id'], 'user_courses', ['user_id', 'course_id']),
                new ForeignKey('fk_certificate_issuer', ['issued_by'], 'app_user', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function userId(): string { return $this->userId; }
    public function courseId(): string { return $this->courseId; }
    public function issuedBy(): string { return $this->issuedBy; }
    public function verificationCode(): string { return $this->verificationCode; }
    public function studentNameSnapshot(): string { return $this->studentNameSnapshot; }
    public function courseTitleSnapshot(): string { return $this->courseTitleSnapshot; }
    public function certifierNameSnapshot(): string { return $this->certifierNameSnapshot; }
    public function completedAtSnapshot(): DateTimeImmutable { return $this->completedAtSnapshot; }
    public function templateSnapshot(): string { return $this->templateSnapshot; }
    public function status(): CertificateStatus { return $this->status; }
    public function documentUri(): ?string { return $this->documentUri; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function issuedAt(): ?DateTimeImmutable { return $this->issuedAt; }
}
