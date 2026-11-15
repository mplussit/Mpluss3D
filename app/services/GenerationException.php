<?php
declare(strict_types=1);

/**
 * Generering mislyktes (OpenSCAD-feil, tidsavbrudd, ugyldig STL ...).
 *
 * getMessage() er trygg å vise til brukeren. details() inneholder teknisk
 * informasjon (logg fra OpenSCAD, filstier) og skal bare logges på serveren.
 */
final class GenerationException extends RuntimeException
{
    public function __construct(
        string $userMessage,
        private string $details = '',
        private int $httpStatus = 500,
        private string $reason = 'error'
    ) {
        parent::__construct($userMessage);
    }

    public function details(): string
    {
        return $this->details;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** Maskinlesbar årsak: error | timeout | busy | not_found | invalid_mesh */
    public function reason(): string
    {
        return $this->reason;
    }
}
