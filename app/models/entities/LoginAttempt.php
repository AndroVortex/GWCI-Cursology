<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Enums\LoginOutcome;
use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use DateTimeImmutable;

/**
 * Fila de `login_attempt`: bitácora de auditoría de intentos de acceso.
 * user_id es NULL cuando el email no corresponde a ninguna cuenta (unknown_user).
 */
final class LoginAttempt extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly ?string $userId,
        protected readonly DateTimeImmutable $attemptedAt,
        protected readonly LoginOutcome $outcome,
        protected readonly int $consecutiveFailures,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            userId: $r->nullableId('user_id'),
            attemptedAt: $r->dateTime('attempted_at'),
            outcome: $r->enum('outcome', LoginOutcome::class),
            consecutiveFailures: $r->tinyIntUnsigned('consecutive_failures'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'login_attempt',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('user_id', 'userId', 'BIGINT UNSIGNED', nullable: true),
                new Column('attempted_at', 'attemptedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('outcome', 'outcome', 'VARCHAR(20)', enum: LoginOutcome::class),
                new Column('consecutive_failures', 'consecutiveFailures', 'TINYINT UNSIGNED', default: '0'),
            ],
            primaryKey: ['id'],
            foreignKeys: [
                new ForeignKey('fk_login_user', ['user_id'], 'app_user', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function userId(): ?string { return $this->userId; }
    public function attemptedAt(): DateTimeImmutable { return $this->attemptedAt; }
    public function outcome(): LoginOutcome { return $this->outcome; }
    public function consecutiveFailures(): int { return $this->consecutiveFailures; }
}
