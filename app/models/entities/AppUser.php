<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Enums\AccountStatus;
use App\Models\Enums\UserRole;
use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use App\Models\Mapping\UniqueKey;
use DateTimeImmutable;

/**
 * Fila de `app_user`.
 *
 * - active es generada por MySQL a partir de account_status: solo lectura.
 * - ban es independiente de account_status; el acceso exige active y ban = false.
 * - password_hash es de uso interno y aparece oculto en var_dump()/print_r().
 */
final class AppUser extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $name,
        protected readonly string $fatherLastName,
        protected readonly ?string $motherLastName,
        protected readonly string $gender,
        protected readonly string $birthday,
        protected readonly string $avatar,
        protected readonly string $email,
        protected readonly string $passwordHash,
        protected readonly UserRole $role,
        protected readonly ?string $description,
        protected readonly AccountStatus $accountStatus,
        protected readonly bool $active,
        protected readonly int $consecutiveFailedLogins,
        protected readonly DateTimeImmutable $statusChangedAt,
        protected readonly bool $ban,
        protected readonly ?string $bannedBy,
        protected readonly ?DateTimeImmutable $bannedAt,
        protected readonly ?string $banReason,
        protected readonly DateTimeImmutable $createdAt,
        protected readonly DateTimeImmutable $updatedAt,
        protected readonly ?DateTimeImmutable $lastLoginAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            name: $r->string('name', 100),
            fatherLastName: $r->string('father_last_name', 100),
            motherLastName: $r->nullableString('mother_last_name', 100),
            gender: $r->string('gender', 40),
            birthday: $r->date('birthday'),
            avatar: $r->string('avatar', 2048),
            email: $r->string('email', 254),
            passwordHash: $r->string('password_hash', 255),
            role: $r->enum('role', UserRole::class),
            description: $r->nullableText('description'),
            accountStatus: $r->enum('account_status', AccountStatus::class),
            active: $r->bool('active'),
            consecutiveFailedLogins: $r->tinyIntUnsigned('consecutive_failed_logins'),
            statusChangedAt: $r->dateTime('status_changed_at'),
            ban: $r->bool('ban'),
            bannedBy: $r->nullableId('banned_by'),
            bannedAt: $r->nullableDateTime('banned_at'),
            banReason: $r->nullableString('ban_reason', 500),
            createdAt: $r->dateTime('created_at'),
            updatedAt: $r->dateTime('updated_at'),
            lastLoginAt: $r->nullableDateTime('last_login_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'app_user',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('name', 'name', 'VARCHAR(100)'),
                new Column('father_last_name', 'fatherLastName', 'VARCHAR(100)'),
                new Column('mother_last_name', 'motherLastName', 'VARCHAR(100)', nullable: true),
                new Column('gender', 'gender', 'VARCHAR(40)'),
                new Column('birthday', 'birthday', 'DATE'),
                new Column('avatar', 'avatar', 'VARCHAR(2048)'),
                new Column('email', 'email', 'VARCHAR(254)'),
                new Column('password_hash', 'passwordHash', 'VARCHAR(255)'),
                new Column('role', 'role', 'VARCHAR(16)', default: "'student'", enum: UserRole::class),
                new Column('description', 'description', 'TEXT', nullable: true),
                new Column('account_status', 'accountStatus', 'VARCHAR(16)', default: "'active'", enum: AccountStatus::class),
                new Column('active', 'active', 'BOOLEAN', nullable: true, generated: true),
                new Column('consecutive_failed_logins', 'consecutiveFailedLogins', 'TINYINT UNSIGNED', default: '0'),
                new Column('status_changed_at', 'statusChangedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('ban', 'ban', 'BOOLEAN', default: 'FALSE'),
                new Column('banned_by', 'bannedBy', 'BIGINT UNSIGNED', nullable: true),
                new Column('banned_at', 'bannedAt', 'DATETIME(6)', nullable: true),
                new Column('ban_reason', 'banReason', 'VARCHAR(500)', nullable: true),
                new Column('created_at', 'createdAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('updated_at', 'updatedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)', onUpdate: 'CURRENT_TIMESTAMP(6)'),
                new Column('last_login_at', 'lastLoginAt', 'DATETIME(6)', nullable: true),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_user_email', ['email']),
            ],
            foreignKeys: [
                new ForeignKey('fk_user_banned_by', ['banned_by'], 'app_user', ['id']),
            ],
            redactedColumns: ['password_hash'],
        );
    }

    public function id(): string { return $this->id; }
    public function name(): string { return $this->name; }
    public function fatherLastName(): string { return $this->fatherLastName; }
    public function motherLastName(): ?string { return $this->motherLastName; }
    public function gender(): string { return $this->gender; }
    public function birthday(): string { return $this->birthday; }
    public function avatar(): string { return $this->avatar; }
    public function email(): string { return $this->email; }
    /** Solo para password_verify(); nunca se envía al cliente. */
    public function passwordHash(): string { return $this->passwordHash; }
    public function role(): UserRole { return $this->role; }
    public function description(): ?string { return $this->description; }
    public function accountStatus(): AccountStatus { return $this->accountStatus; }
    /** Columna generada: account_status = 'active'. */
    public function active(): bool { return $this->active; }
    public function consecutiveFailedLogins(): int { return $this->consecutiveFailedLogins; }
    public function statusChangedAt(): DateTimeImmutable { return $this->statusChangedAt; }
    public function ban(): bool { return $this->ban; }
    public function bannedBy(): ?string { return $this->bannedBy; }
    public function bannedAt(): ?DateTimeImmutable { return $this->bannedAt; }
    public function banReason(): ?string { return $this->banReason; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function lastLoginAt(): ?DateTimeImmutable { return $this->lastLoginAt; }
}
