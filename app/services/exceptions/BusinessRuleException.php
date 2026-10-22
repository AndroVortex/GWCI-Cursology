<?php

declare(strict_types=1);

namespace App\Services\Exceptions;

use App\Database\ProcedureException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Regla de negocio incumplida, casi siempre señalada por un SP con SIGNAL.
 * Trae el código HTTP, el mensaje para el usuario y, si aplica, el campo del formulario.
 * El router de la API la convierte en respuesta JSON.
 */
final class BusinessRuleException extends RuntimeException
{
    /** código => [estado HTTP, mensaje para el usuario, campo o null] */
    private const RULES = [
        'ADMIN_EXISTS' => [409, 'Ya existe un administrador.', null],
        'EMAIL_TAKEN' => [409, 'Ya existe una cuenta con ese email.', 'email'],
        'USER_NOT_AVAILABLE' => [403, 'Tu cuenta no está disponible.', null],
        'NOT_ADMIN' => [403, 'Solo un administrador puede hacer esto.', null],
        'NOT_INSTRUCTOR' => [403, 'Solo un instructor puede hacer esto.', null],
        'CATEGORY_NAME_TAKEN' => [409, 'Ya existe una categoría con ese nombre.', 'nombre'],
        'CATEGORY_NOT_FOUND' => [404, 'La categoría no existe.', null],
        'CATEGORY_IN_USE' => [409, 'Hay cursos publicados que se quedarían sin una categoría activa. Asígnales otra antes de desactivarla.', null],
        'CATEGORY_NOT_AVAILABLE' => [422, 'Alguna de las categorías elegidas ya no está disponible.', 'categorias'],
        'COURSE_NOT_FOUND' => [404, 'El curso no existe.', null],
        'LEVEL_NOT_FOUND' => [404, 'El nivel no existe.', null],
        'CHAPTER_NOT_FOUND' => [404, 'El capítulo no existe.', null],
        'CONTENT_NOT_FOUND' => [404, 'El contenido no existe.', null],
        'LEVEL_NOT_EMPTY' => [409, 'Primero elimina los capítulos de este nivel.', null],
        'HAS_ENROLLMENTS' => [409, 'Ya hay alumnos con acceso a este contenido; no se puede eliminar.', null],
        'MAIN_VIDEO_EXISTS' => [409, 'Este capítulo ya tiene un video principal. Quítalo antes de subir otro.', 'archivo'],
    ];

    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly ?string $field,
        ?Throwable $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Traduce el código del SP; si no es una regla conocida, devuelve la excepción original. */
    public static function fromProcedure(ProcedureException $exception): RuntimeException
    {
        $rule = $exception->businessCode !== null ? (self::RULES[$exception->businessCode] ?? null) : null;
        if ($rule === null) {
            return $exception;
        }

        return new self($exception->businessCode, $rule[0], $rule[1], $rule[2], $exception);
    }

    /** La misma regla, detectada en PHP antes de llamar al SP. */
    public static function of(string $code): self
    {
        $rule = self::RULES[$code] ?? throw new LogicException(sprintf('Regla de negocio desconocida: %s', $code));

        return new self($code, $rule[0], $rule[1], $rule[2], null);
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->field === null ? [] : [$this->field => $this->getMessage()];
    }
}
