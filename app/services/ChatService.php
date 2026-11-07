<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dto\ChatMessage;
use App\Models\Dto\ChatSummary;
use App\Models\Dto\ChatThread;
use App\Models\Entities\AppUser;
use App\Models\Entities\Chat;
use App\Models\Enums\UserRole;
use App\Repositories\ChatRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;

/**
 * Mensajes entre el alumno inscrito y el instructor dueño del curso: una conversación por curso.
 * Solo texto; un mensaje puede responder a otro de la misma conversación. Los no leídos se
 * cuentan contra la última vez que cada participante abrió la conversación.
 */
final class ChatService
{
    public const MAX_MESSAGE_CHARS = 2000;
    /** Mensajes que trae cada apertura o sondeo. */
    public const MESSAGE_LIMIT = 100;

    public function __construct(private readonly ChatRepository $chats)
    {
    }

    /**
     * El alumno pasa {curso}; el instructor, {curso, alumno}.
     *
     * @param array<string, mixed> $input
     * @return array{0: Chat, 1: bool} [la conversación, si se creó en esta llamada]
     * @throws ValidationException|BusinessRuleException COURSE_NOT_FOUND, NOT_ENROLLED, STUDENT_NOT_ENROLLED, NOT_ALLOWED
     */
    public function start(AppUser $user, array $input): array
    {
        $course = self::inputId($input, 'curso');
        $student = $user->role() === UserRole::Instructor ? self::inputId($input, 'alumno') : null;

        $errors = array_filter([
            'curso' => $course === null ? 'Curso inválido.' : null,
            'alumno' => $user->role() === UserRole::Instructor && $student === null ? 'Alumno inválido.' : null,
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->chats->start($user->id(), (string) $course, $student);
    }

    /** @return list<ChatSummary> */
    public function list(AppUser $user): array
    {
        return $this->chats->list($user->id());
    }

    /**
     * La conversación con sus últimos mensajes, o solo los posteriores a $afterId. La marca leída.
     *
     * @throws BusinessRuleException CHAT_NOT_FOUND
     */
    public function open(AppUser $user, string $chatId, ?string $afterId = null): ChatThread
    {
        self::requireId($chatId, 'CHAT_NOT_FOUND');
        $afterId = $afterId === null || trim($afterId) === '' ? null : trim($afterId);
        if ($afterId !== null && !self::isId($afterId)) {
            throw new ValidationException(['after' => 'Mensaje inválido.']);
        }

        return $this->chats->open($user->id(), $chatId, $afterId, self::MESSAGE_LIMIT);
    }

    /**
     * @param array<string, mixed> $input {texto, responde_a?}
     * @throws ValidationException|BusinessRuleException CHAT_NOT_FOUND, MESSAGE_NOT_FOUND
     */
    public function send(AppUser $user, string $chatId, array $input): ChatMessage
    {
        self::requireId($chatId, 'CHAT_NOT_FOUND');
        [$text, $replyTo] = self::validateMessage($input);

        return $this->chats->send($user->id(), $chatId, $text, $replyTo);
    }

    /** No leídos de todas sus conversaciones; 0 para visitantes y administradores. */
    public function unreadTotal(?AppUser $user): int
    {
        if ($user === null || !in_array($user->role(), [UserRole::Student, UserRole::Instructor], true)) {
            return 0;
        }

        return $this->chats->unreadTotal($user->id());
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: ?string} [texto sin espacios a los lados, mensaje al que responde o null]
     * @throws ValidationException
     */
    public static function validateMessage(array $input): array
    {
        $text = $input['texto'] ?? null;
        $text = is_string($text) && mb_check_encoding($text, 'UTF-8') ? trim($text) : null;
        $hasReply = array_key_exists('responde_a', $input) && $input['responde_a'] !== null && $input['responde_a'] !== '';
        $replyTo = $hasReply ? self::inputId($input, 'responde_a') : null;

        $errors = array_filter([
            'texto' => match (true) {
                $text === null || $text === '' => 'Escribe tu mensaje.',
                mb_strlen($text) > self::MAX_MESSAGE_CHARS => sprintf('No puede exceder %d caracteres.', self::MAX_MESSAGE_CHARS),
                default => null,
            },
            'responde_a' => $hasReply && $replyTo === null ? 'Mensaje inválido.' : null,
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [(string) $text, $replyTo];
    }

    /**
     * Un id del cuerpo JSON, como número o como texto; null si falta o no es válido.
     *
     * @param array<string, mixed> $input
     */
    private static function inputId(array $input, string $field): ?string
    {
        $value = $input[$field] ?? null;
        $value = is_int($value) ? (string) $value : $value;

        return is_string($value) && self::isId(trim($value)) ? trim($value) : null;
    }

    private static function isId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1;
    }

    private static function requireId(string $value, string $code): void
    {
        if (!self::isId($value)) {
            throw BusinessRuleException::of($code);
        }
    }
}
