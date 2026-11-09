<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dto\ReactionSummary;
use App\Models\Entities\AppUser;
use App\Models\Entities\Reaction;
use App\Repositories\ReactionRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use App\Services\Validation\UserRules;

/**
 * Catálogo de reacciones (lo administra el admin) y reacciones a opiniones y mensajes. Las de
 * los comentarios van por CommentService. Una por persona y elemento: elegir otra la cambia y
 * mandar null la quita.
 */
final class ReactionService
{
    public const MAX_EMOJI_CHARS = 8;
    public const MAX_NAME_CHARS = 60;
    /** Las que agrega "Agregar las sugeridas" con el catálogo vacío. */
    public const DEFAULTS = [
        ['emoji' => '👍', 'nombre' => 'Me gusta'],
        ['emoji' => '❤️', 'nombre' => 'Me encanta'],
        ['emoji' => '😂', 'nombre' => 'Me divierte'],
        ['emoji' => '😮', 'nombre' => 'Me sorprende'],
        ['emoji' => '💡', 'nombre' => 'Me sirvió'],
    ];

    public function __construct(private readonly ReactionRepository $reactions)
    {
    }

    /** Las activas, para el selector de reacciones. @return list<Reaction> */
    public function active(): array
    {
        return $this->reactions->list(true);
    }

    /** Todas, para la vista del admin. @return list<Reaction> */
    public function all(): array
    {
        return $this->reactions->list(false);
    }

    /**
     * @param array<string, mixed> $input {emoji, nombre}
     * @throws ValidationException|BusinessRuleException NOT_ADMIN, REACTION_NAME_TAKEN
     */
    public function create(AppUser $admin, array $input): Reaction
    {
        [$emoji, $name] = self::validate($input);

        return $this->reactions->create($admin->id(), $emoji, $name);
    }

    /**
     * Cambia emoji y nombre, el estado (activa), o ambos.
     *
     * @param array<string, mixed> $input {emoji, nombre} y/o {activa}
     * @throws ValidationException|BusinessRuleException NOT_ADMIN, REACTION_NOT_FOUND, REACTION_NAME_TAKEN
     */
    public function update(AppUser $admin, string $reactionId, array $input): Reaction
    {
        self::requireId($reactionId, 'REACTION_NOT_FOUND');
        $reaction = null;

        if (array_key_exists('emoji', $input) || array_key_exists('nombre', $input)) {
            [$emoji, $name] = self::validate($input);
            $reaction = $this->reactions->update($admin->id(), $reactionId, $emoji, $name);
        }

        if (array_key_exists('activa', $input)) {
            if (!is_bool($input['activa'])) {
                throw new ValidationException(['activa' => 'Indica si la reacción queda activa (true o false).']);
            }
            $reaction = $this->reactions->setActive($admin->id(), $reactionId, $input['activa']);
        }

        return $reaction ?? throw new ValidationException(['nombre' => 'No hay cambios que guardar.']);
    }

    /**
     * Agrega las sugeridas cuyo nombre todavía no está en el catálogo.
     *
     * @return list<Reaction> las que agregó
     * @throws BusinessRuleException NOT_ADMIN
     */
    public function addDefaults(AppUser $admin): array
    {
        $taken = array_map(static fn (Reaction $reaction): string => mb_strtolower($reaction->reactionName()), $this->all());
        $added = [];
        foreach (self::DEFAULTS as $default) {
            if (!in_array(mb_strtolower($default['nombre']), $taken, true)) {
                $added[] = $this->reactions->create($admin->id(), $default['emoji'], $default['nombre']);
            }
        }

        return $added;
    }

    /**
     * @param array<string, mixed> $input {reaccion: id | null}
     * @throws ValidationException|BusinessRuleException OPINION_NOT_FOUND, REACTION_NOT_FOUND
     */
    public function reactToOpinion(AppUser $user, string $opinionId, array $input): ReactionSummary
    {
        self::requireId($opinionId, 'OPINION_NOT_FOUND');

        return $this->reactions->reactToOpinion($user->id(), $opinionId, self::reactionInput($input));
    }

    /**
     * @param array<string, mixed> $input {reaccion: id | null}
     * @throws ValidationException|BusinessRuleException MESSAGE_NOT_FOUND, REACTION_NOT_FOUND
     */
    public function reactToMessage(AppUser $user, string $messageId, array $input): ReactionSummary
    {
        self::requireId($messageId, 'MESSAGE_NOT_FOUND');

        return $this->reactions->reactToMessage($user->id(), $messageId, self::reactionInput($input));
    }

    /**
     * La reacción elegida: su id, o null para quitarla. El campo "reaccion" es obligatorio.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public static function reactionInput(array $input): ?string
    {
        if (!array_key_exists('reaccion', $input)) {
            throw new ValidationException(['reaccion' => 'Elige una reacción.']);
        }
        $value = $input['reaccion'];
        $value = is_int($value) ? (string) $value : $value;
        if ($value === null) {
            return null;
        }

        return is_string($value) && self::isId(trim($value)) ? trim($value) : throw new ValidationException(['reaccion' => 'Reacción inválida.']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: string} [emoji, nombre]
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $emoji = UserRules::text($input, 'emoji');
        $name = UserRules::text($input, 'nombre');

        $errors = array_filter([
            'emoji' => self::emojiError($emoji),
            'nombre' => match (true) {
                $name === '' => 'Escribe el nombre de la reacción.',
                mb_strlen($name) > self::MAX_NAME_CHARS => sprintf('No puede exceder %d caracteres.', self::MAX_NAME_CHARS),
                default => null,
            },
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$emoji, $name];
    }

    /**
     * Un emoji: de 1 a 8 caracteres, solo símbolos (con sus modificadores, como el tono de piel o
     * la variante U+FE0F de "❤️"), sin letras, números ni espacios.
     */
    public static function emojiError(string $emoji): ?string
    {
        return match (true) {
            $emoji === '' => 'Escribe o pega el emoji.',
            mb_strlen($emoji) > self::MAX_EMOJI_CHARS => 'Usa un solo emoji.',
            preg_match('/^[\p{So}\p{Sk}\p{Mn}\p{Me}\p{Cf}]+$/u', $emoji) !== 1, preg_match('/\p{So}/u', $emoji) !== 1 => 'Usa un emoji (sin letras, números ni espacios).',
            default => null,
        };
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
