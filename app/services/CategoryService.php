<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Entities\AppUser;
use App\Models\Entities\Category;
use App\Repositories\CategoryRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use App\Services\Validation\UserRules;

/** Catálogo de categorías: lo administra el admin y lo usan instructores y visitantes. */
final class CategoryService
{
    private const MAX_NAME_CHARS = 100;

    public function __construct(private readonly CategoryRepository $categories)
    {
    }

    /** @return list<Category> */
    public function active(): array
    {
        return $this->categories->list(false);
    }

    /** @return list<Category> */
    public function all(): array
    {
        return $this->categories->list(true);
    }

    /**
     * @param array<string, mixed> $input {nombre, descripcion}
     * @throws ValidationException|BusinessRuleException
     */
    public function create(AppUser $admin, array $input): Category
    {
        [$name, $description] = $this->validate($input);

        return $this->categories->create($admin->id(), $name, $description);
    }

    /**
     * Cambia nombre y descripción, el estado (activa), o ambos.
     *
     * @param array<string, mixed> $input {nombre, descripcion} y/o {activa}
     * @throws ValidationException|BusinessRuleException
     */
    public function update(AppUser $admin, string $categoryId, array $input): Category
    {
        $category = null;

        if (array_key_exists('nombre', $input) || array_key_exists('descripcion', $input)) {
            [$name, $description] = $this->validate($input);
            $category = $this->categories->update($admin->id(), $categoryId, $name, $description);
        }

        if (array_key_exists('activa', $input)) {
            if (!is_bool($input['activa'])) {
                throw new ValidationException(['activa' => 'Indica si la categoría queda activa (true o false).']);
            }
            $category = $this->categories->setActive($admin->id(), $categoryId, $input['activa']);
        }

        return $category ?? throw new ValidationException(['nombre' => 'No hay cambios que guardar.']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: ?string}
     */
    private function validate(array $input): array
    {
        $name = UserRules::text($input, 'nombre');
        $description = UserRules::text($input, 'descripcion');

        $errors = array_filter([
            'nombre' => match (true) {
                $name === '' => 'Escribe el nombre de la categoría.',
                mb_strlen($name) > self::MAX_NAME_CHARS => sprintf('No puede exceder %d caracteres.', self::MAX_NAME_CHARS),
                default => null,
            },
            'descripcion' => UserRules::descriptionError($description),
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$name, $description === '' ? null : $description];
    }
}
