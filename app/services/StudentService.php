<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dto\KardexEntry;
use App\Models\Entities\AppUser;
use App\Models\Entities\Purchase;
use App\Repositories\StudentRepository;
use App\Services\Validation\UserRules;
use DateTimeImmutable;

/**
 * Panel "Mi aprendizaje": cifras, cursos para continuar, kardex con filtros y compras.
 * Los filtros inválidos se ignoran (como en el catálogo).
 */
final class StudentService
{
    /** valor del filtro "estado" => p_status de sp_student_courses */
    public const STATUSES = [
        'en_progreso' => 'in_progress',
        'terminados' => 'completed',
        'activos' => 'active',
    ];

    private const CONTINUE_LIMIT = 3;

    public function __construct(private readonly StudentRepository $students)
    {
    }

    /**
     * @param array<string, mixed> $query parámetros GET: desde, hasta, categoria, estado
     * @return array{
     *     stats: array{enrolled: int, inProgress: int, completed: int},
     *     continue: list<KardexEntry>,
     *     kardex: list<KardexEntry>,
     *     filters: array{desde: string, hasta: string, categoria: string, estado: string},
     *     purchases: list<Purchase>
     * }
     */
    public function dashboard(AppUser $student, array $query): array
    {
        $filters = self::filters($query);
        $all = $this->students->courses($student->id(), null, null, null, null);
        $filtered = array_filter($filters, static fn (string $value): bool => $value !== '') === []
            ? $all
            : $this->students->courses(
                $student->id(),
                $filters['desde'] ?: null,
                $filters['hasta'] ?: null,
                $filters['categoria'] ?: null,
                self::STATUSES[$filters['estado']] ?? null,
            );
        $inProgress = array_values(array_filter($all, static fn (KardexEntry $entry): bool => !$entry->isCompleted()));

        return [
            'stats' => [
                'enrolled' => count($all),
                'inProgress' => count($inProgress),
                'completed' => count($all) - count($inProgress),
            ],
            'continue' => array_slice($inProgress, 0, self::CONTINUE_LIMIT),
            'kardex' => $filtered,
            'filters' => $filters,
            'purchases' => $this->students->purchases($student->id()),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{desde: string, hasta: string, categoria: string, estado: string} '' = sin filtro
     */
    public static function filters(array $query): array
    {
        $from = self::date(UserRules::text($query, 'desde'));
        $to = self::date(UserRules::text($query, 'hasta'));
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $category = UserRules::text($query, 'categoria');
        $status = UserRules::text($query, 'estado');

        return [
            'desde' => $from,
            'hasta' => $to,
            'categoria' => preg_match('/^[1-9][0-9]{0,19}$/D', $category) === 1 ? $category : '',
            'estado' => isset(self::STATUSES[$status]) ? $status : '',
        ];
    }

    /** "Y-m-d" válida o ''. */
    private static function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}
