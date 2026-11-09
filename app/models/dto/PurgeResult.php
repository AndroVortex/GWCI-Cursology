<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;

/** Lo que sp_maintenance_purge borró (o borraría, en el ensayo) y las cuentas que quedan. */
final class PurgeResult
{
    /** Columnas de conteo del SP, en el orden en que se muestran, con su etiqueta. */
    public const LABELS = [
        'users' => 'Cuentas',
        'login_attempts' => 'Intentos de inicio de sesión',
        'account_events' => 'Eventos de cuenta',
        'courses' => 'Cursos',
        'levels' => 'Niveles',
        'chapters' => 'Capítulos',
        'contents' => 'Contenidos de capítulo',
        'questions' => 'Preguntas de examen',
        'enrollments' => 'Inscripciones',
        'purchases' => 'Compras',
        'access' => 'Accesos a niveles',
        'progress' => 'Avance por capítulo',
        'attempts' => 'Intentos de examen',
        'certificates' => 'Certificados',
        'rates' => 'Calificaciones',
        'opinions' => 'Opiniones',
        'comments' => 'Comentarios',
        'reactions' => 'Reacciones dadas',
        'chats' => 'Conversaciones',
        'messages' => 'Mensajes',
        'reports' => 'Reportes',
        'categories' => 'Categorías de prueba',
        'test_reactions' => 'Reacciones de prueba del catálogo',
    ];

    /**
     * @param array<string, int> $deleted filas por columna de LABELS
     * @param array{student: int, instructor: int, admin: int} $remaining cuentas que quedan por rol
     */
    private function __construct(
        public readonly array $deleted,
        public readonly array $remaining,
        public readonly bool $applied,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);
        $deleted = [];
        foreach (array_keys(self::LABELS) as $column) {
            $deleted[$column] = $r->intUnsigned($column);
        }

        return new self($deleted, [
            'student' => $r->intUnsigned('remaining_students'),
            'instructor' => $r->intUnsigned('remaining_instructors'),
            'admin' => $r->intUnsigned('remaining_admins'),
        ], $r->bool('applied'));
    }

    public function total(): int
    {
        return array_sum($this->deleted);
    }
}
