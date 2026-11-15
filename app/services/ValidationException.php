<?php
declare(strict_types=1);

/**
 * Kastes når brukerens parametere ikke er gyldige.
 * $errors er en liste med ['field' => ..., 'message' => ...].
 */
final class ValidationException extends RuntimeException
{
    /** @param list<array{field:string,message:string}> $errors */
    public function __construct(private array $errors)
    {
        parent::__construct(implode(' ', array_column($errors, 'message')));
    }

    /** @return list<array{field:string,message:string}> */
    public function errors(): array
    {
        return $this->errors;
    }
}
