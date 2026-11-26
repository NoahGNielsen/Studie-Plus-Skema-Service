<?php

declare(strict_types=1);

namespace SkemaService\Sync;

use RuntimeException;

/**
 * Skemaet kunne ikke hentes (netværk, vedligehold, uventet format …).
 * $errorCode er en kort, ufarlig kode der kan gemmes og vises.
 */
final class SourceUnavailable extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
