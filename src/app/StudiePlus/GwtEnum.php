<?php

declare(strict_types=1);

namespace SkemaService\StudiePlus;

/** En GWT-enumværdi. Kun ordinalen sendes, ikke navnet. */
final class GwtEnum
{
    public function __construct(
        public readonly string $type,
        public readonly int $ordinal,
    ) {
    }
}
