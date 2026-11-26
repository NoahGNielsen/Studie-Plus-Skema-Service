<?php

declare(strict_types=1);

namespace SkemaService\StudiePlus;

/** Et deserialiseret GWT-objekt: typesignatur og felter i serialiseringsrækkefølge. */
final class GwtObject
{
    /** @var list<mixed> */
    public array $fields = [];

    public function __construct(public readonly string $type)
    {
    }
}
