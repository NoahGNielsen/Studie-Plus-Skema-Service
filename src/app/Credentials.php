<?php

declare(strict_types=1);

namespace SkemaService;

/**
 * Studie+-loginoplysninger i hukommelsen. Kan ikke konverteres til streng,
 * og skjules ved var_dump/print_r og i stack traces.
 */
final class Credentials
{
    public function __construct(
        public readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
    ) {
    }

    public function password(): string
    {
        return $this->password;
    }

    public function __debugInfo(): array
    {
        return ['username' => '[skjult]', 'password' => '[skjult]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Credentials må ikke serialiseres.');
    }
}
