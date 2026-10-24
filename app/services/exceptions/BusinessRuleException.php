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
        'USER_NOT_FOUND' => [404, 'La cuenta no existe.', null],
        'USER_NOT_LOCKED' => [409, 'Esta cuenta ya no está bloqueada.', null],
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
        'PRICING_LOCKED' => [409, 'El curso ya tiene alumnos: puedes cambiar los importes, pero no la forma de cobro.', 'precio_modo'],
        'COURSE_INACTIVE' => [409, 'El curso está dado de baja. Reactívalo antes de publicarlo.', null],
        'COURSE_ALREADY_PUBLISHED' => [409, 'El curso ya está publicado.', null],
        'COURSE_NOT_PUBLISHED' => [409, 'El curso todavía es un borrador.', null],
        'COURSE_HAS_STUDENTS' => [409, 'El curso ya tiene alumnos: no puede volver a borrador. Si ya no quieres ofrecerlo, dalo de baja.', null],
        'NO_ACTIVE_CATEGORY' => [422, 'Para publicar, el curso necesita al menos una categoría activa.', null],
        'NO_LEVELS' => [422, 'Para publicar, agrega al menos un nivel.', null],
        'LEVEL_WITHOUT_CHAPTERS' => [422, 'Para publicar, cada nivel necesita al menos un capítulo.', null],
        'CHAPTER_WITHOUT_VIDEO' => [422, 'Para publicar, cada capítulo de video necesita su video principal.', null],
        'EXAM_WITHOUT_QUESTIONS' => [422, 'Para publicar, cada examen necesita al menos una pregunta.', null],
        'CHAPTER_IS_EXAM' => [409, 'Los exámenes no llevan video ni recursos: solo preguntas.', null],
        'CHAPTER_NOT_EXAM' => [409, 'Las preguntas solo van en capítulos de examen.', null],
        'QUESTION_NOT_FOUND' => [404, 'La pregunta no existe.', null],
        'INVALID_ANSWERS' => [422, 'Revisa las opciones: de 2 a 8, con al menos una correcta (exactamente una en opción única) y pesos mayores a 0 en las correctas.', 'respuestas'],
        'INVALID_PASSING_GRADE' => [422, 'La calificación mínima debe ser mayor a 0 y de máximo 100.', 'minimo'],
        'EXAM_HAS_ATTEMPTS' => [409, 'Ya hay alumnos que presentaron este examen: sus preguntas ya no se pueden cambiar.', null],
        'UNKNOWN_PAYMENT_METHOD' => [422, 'Forma de pago desconocida.', 'codigo'],
        'NOT_STUDENT' => [403, 'Solo los alumnos pueden inscribirse y comprar cursos.', null],
        'ALREADY_OWNED' => [409, 'Ya tienes acceso a esto.', null],
        'NOT_FOR_SALE' => [409, 'Esto no se vende por separado. Revisa las opciones de compra del curso.', null],
        'NOT_FREE' => [409, 'Este curso no tiene niveles gratis: compra un nivel o el curso completo.', null],
        'PAYMENT_METHOD_UNAVAILABLE' => [409, 'Esa forma de pago no está disponible por el momento.', null],
        'PURCHASE_NOT_FOUND' => [404, 'La compra no existe.', null],
        'PURCHASE_NOT_PENDING' => [409, 'Esta compra ya se cerró.', null],
        'PAYMENT_MISMATCH' => [409, 'El pago no coincide con la compra; no se dio acceso. Inténtalo de nuevo.', null],
        'CARD_DECLINED' => [402, 'La tarjeta fue rechazada. Prueba con otra.', 'numero'],
        'PAYMENT_DECLINED' => [402, 'PayPal no aprobó el pago. Prueba de nuevo o usa otra forma de pago.', null],
        'NOT_ENROLLED' => [403, 'No estás inscrito en este curso.', null],
        'NO_LEVEL_ACCESS' => [403, 'Todavía no tienes acceso a este nivel.', null],
        'EXAM_NOT_MARKABLE' => [409, 'Los exámenes se terminan al aprobarlos.', null],
        'ATTEMPT_NOT_FOUND' => [404, 'El intento no existe.', null],
        'ATTEMPT_ALREADY_SUBMITTED' => [409, 'Este intento ya se entregó. Abre uno nuevo para volver a intentarlo.', null],
        'INVALID_KARDEX_STATUS' => [422, 'Estado del kardex desconocido.', 'estado'],
        'INVALID_SELECTION' => [422, 'Hay respuestas que no son de este examen o más de una en una pregunta de opción única.', 'respuestas'],
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
