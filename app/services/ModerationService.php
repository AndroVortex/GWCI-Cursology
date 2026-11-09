<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dto\ModerationPage;
use App\Models\Entities\AppUser;
use App\Models\Enums\ReportReason;
use App\Models\Enums\ReportTarget;
use App\Repositories\ModerationRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use App\Services\Validation\UserRules;

/**
 * Moderación de comentarios y opiniones: los usuarios reportan; el admin revisa la cola de
 * reportes y todo el contenido, oculta (con las rutas de ocultar que ya existen), descarta
 * reportes y restaura lo que se ocultó.
 */
final class ModerationService
{
    public const PAGE_SIZE = 20;
    public const SUMMARY_SIZE = 3;
    public const MIN_DETAILS_CHARS = 3;
    public const MAX_DETAILS_CHARS = 500;
    public const MAX_SEARCH_CHARS = 100;
    private const MAX_PAGE = 1000;

    public function __construct(private readonly ModerationRepository $moderation)
    {
    }

    /**
     * @param array<string, mixed> $input {motivo: spam|offensive|answers|other, detalle?}
     * @return bool true si es un reporte nuevo; false si ya lo había reportado
     * @throws ValidationException|BusinessRuleException COMMENT_NOT_FOUND, OPINION_NOT_FOUND, CANNOT_REPORT_OWN
     */
    public function report(AppUser $user, ReportTarget $target, string $targetId, array $input): bool
    {
        self::requireId($targetId, self::notFound($target));
        [$reason, $details] = self::validateReport($input);

        return $this->moderation->report($user->id(), $target, $targetId, $reason, $details);
    }

    /**
     * La cola: contenido con reportes pendientes, del reporte más reciente al más antiguo.
     *
     * @param array<string, mixed> $query {pagina?}
     * @throws BusinessRuleException NOT_ADMIN
     */
    public function reports(AppUser $admin, array $query = []): ModerationPage
    {
        $page = self::page($query);
        [$items, $total, $reportTotal] = $this->moderation->reports($admin->id(), self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        return new ModerationPage($items, $total, $page, ModerationPage::pagesFor($total, self::PAGE_SIZE), [], $reportTotal);
    }

    /** Los 3 más recientes de la cola, para el panel del admin. @throws BusinessRuleException NOT_ADMIN */
    public function summary(AppUser $admin): ModerationPage
    {
        [$items, $total, $reportTotal] = $this->moderation->reports($admin->id(), self::SUMMARY_SIZE, 0);

        return new ModerationPage($items, $total, 1, ModerationPage::pagesFor($total, self::SUMMARY_SIZE), [], $reportTotal);
    }

    /**
     * Todo el contenido con los filtros {tipo, estado, q, pagina}.
     *
     * @param array<string, mixed> $query
     * @throws BusinessRuleException NOT_ADMIN
     */
    public function content(AppUser $admin, array $query = []): ModerationPage
    {
        $filters = self::contentFilters($query);
        $page = self::page($query);
        [$items, $total] = $this->moderation->content(
            $admin->id(),
            $filters['tipo'] === '' ? null : ReportTarget::from($filters['tipo']),
            match ($filters['estado']) {
                'visibles' => 'visible',
                'ocultos' => 'hidden',
                default => null,
            },
            self::likePattern($filters['q']),
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE,
        );

        return new ModerationPage($items, $total, $page, ModerationPage::pagesFor($total, self::PAGE_SIZE), $filters);
    }

    /**
     * Deja visible un contenido reportado: sus reportes pendientes quedan descartados.
     *
     * @throws BusinessRuleException NOT_ADMIN
     */
    public function dismiss(AppUser $admin, ReportTarget $target, string $targetId): int
    {
        self::requireId($targetId, self::notFound($target));

        return $this->moderation->dismiss($admin->id(), $target, $targetId);
    }

    /** @throws BusinessRuleException NOT_ADMIN, COMMENT_NOT_FOUND, OPINION_NOT_FOUND, NOT_RESTORABLE */
    public function restore(AppUser $admin, ReportTarget $target, string $targetId): void
    {
        self::requireId($targetId, self::notFound($target));
        match ($target) {
            ReportTarget::Comment => $this->moderation->restoreComment($admin->id(), $targetId),
            ReportTarget::Opinion => $this->moderation->restoreOpinion($admin->id(), $targetId),
        };
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: ReportReason, 1: ?string} [motivo, detalle o null]
     * @throws ValidationException
     */
    public static function validateReport(array $input): array
    {
        $reason = ReportReason::tryFrom(UserRules::text($input, 'motivo'));
        $details = UserRules::text($input, 'detalle');
        $length = mb_strlen($details);

        $errors = array_filter([
            'motivo' => $reason === null ? 'Elige el motivo del reporte.' : null,
            'detalle' => match (true) {
                $reason === ReportReason::Other && $length === 0 => 'Cuéntanos qué pasa (de 3 a 500 caracteres).',
                $length > 0 && ($length < self::MIN_DETAILS_CHARS || $length > self::MAX_DETAILS_CHARS) => sprintf('Escribe de %d a %d caracteres.', self::MIN_DETAILS_CHARS, self::MAX_DETAILS_CHARS),
                default => null,
            },
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$reason, $details === '' ? null : $details];
    }

    /**
     * Filtros válidos de "Todo el contenido"; los inválidos se ignoran.
     *
     * @param array<string, mixed> $query
     * @return array{tipo: string, estado: string, q: string}
     */
    public static function contentFilters(array $query): array
    {
        $type = UserRules::text($query, 'tipo');
        $state = UserRules::text($query, 'estado');
        $search = UserRules::text($query, 'q');

        return [
            'tipo' => ReportTarget::tryFrom($type) !== null ? $type : '',
            'estado' => in_array($state, ['visibles', 'ocultos'], true) ? $state : '',
            'q' => mb_substr($search, 0, self::MAX_SEARCH_CHARS),
        ];
    }

    /** "%texto%" para LIKE, con %, _ y \ escapados; null si no hay búsqueda. */
    public static function likePattern(string $search): ?string
    {
        $search = trim($search);

        return $search === '' ? null : '%' . addcslashes($search, '%_\\') . '%';
    }

    /** @param array<string, mixed> $query */
    private static function page(array $query): int
    {
        $page = UserRules::text($query, 'pagina');

        return preg_match('/^[1-9][0-9]{0,3}$/D', $page) === 1 ? min((int) $page, self::MAX_PAGE) : 1;
    }

    private static function notFound(ReportTarget $target): string
    {
        return $target === ReportTarget::Comment ? 'COMMENT_NOT_FOUND' : 'OPINION_NOT_FOUND';
    }

    private static function requireId(string $value, string $code): void
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $value) !== 1) {
            throw BusinessRuleException::of($code);
        }
    }
}
