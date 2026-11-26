<?php

declare(strict_types=1);

namespace SkemaService\StudiePlus;

use SkemaService\Sync\SourceUnavailable;

/**
 * Læser en GWT-RPC-objektgraf ud fra et fast typekatalog.
 *
 * GWT-formatet er ikke selvbeskrivende: et objekt er en typereference
 * efterfulgt af felterne i en fast rækkefølge. Derfor skal layoutet for
 * hver klasse kendes på forhånd. Ukendte typer (eller en ændret
 * typesignatur efter en Studie+-opdatering) giver SourceUnavailable,
 * så der aldrig gættes.
 *
 * Feltkoder i et layout:  i = tal, s = streng, o = objekt (kan være null).
 *
 * Repræsentation:
 *  - klasser med layout → GwtObject
 *  - enums              → GwtEnum
 *  - Integer/Long/Boolean/String → PHP-værdi
 *  - ArrayList          → list<mixed>
 *  - HashMap            → list<array{0: mixed, 1: mixed}> (nøgler kan være objekter)
 */
final class GwtObjectReader
{
    private const MAX_DEPTH = 32;
    private const MAX_OBJECTS = 200_000;
    private const MAX_COLLECTION = 100_000;

    private const BUILTIN = [
        'java.lang.Integer/3438268394' => 'int',
        'java.lang.Long/4227064769' => 'long',
        'java.lang.Boolean/476441737' => 'bool',
        'java.lang.String/2004016611' => 'string',
        'java.util.ArrayList/4159755760' => 'list',
        'java.util.HashMap/1797211028' => 'map',
    ];

    /** @var list<mixed> objekter i den rækkefølge de blev læst (til tilbagereferencer) */
    private array $seen = [];
    private int $depth = 0;

    /**
     * @param array<string, string> $layouts typesignatur => fx "i,s,o"
     * @param list<string>          $enums   typesignaturer for enums
     */
    public function __construct(
        private readonly GwtResponseReader $reader,
        private readonly array $layouts,
        private readonly array $enums,
    ) {
    }

    public function readObject(): mixed
    {
        $token = $this->reader->readInt();
        if ($token < 0) {
            $index = -$token - 1;
            if (!array_key_exists($index, $this->seen)) {
                throw new SourceUnavailable('gwt_bad_object_ref');
            }

            return $this->seen[$index];
        }
        if ($token === 0) {
            return null;
        }

        $type = $this->typeName($token);
        if (count($this->seen) >= self::MAX_OBJECTS || $this->depth >= self::MAX_DEPTH) {
            throw new SourceUnavailable('gwt_too_complex');
        }

        $id = count($this->seen);
        $this->seen[] = null;
        $this->depth++;
        try {
            $value = $this->readInstance($type, $id);
        } finally {
            $this->depth--;
        }
        $this->seen[$id] = $value;

        return $value;
    }

    private function readInstance(string $type, int $id): mixed
    {
        switch (self::BUILTIN[$type] ?? null) {
            case 'int':
                return $this->reader->readInt();
            case 'long':
                return $this->reader->readLong();
            case 'bool':
                return $this->reader->readBoolean();
            case 'string':
                return $this->reader->readString();
            case 'list':
                $items = [];
                $n = $this->collectionSize();
                for ($k = 0; $k < $n; $k++) {
                    $items[] = $this->readObject();
                }

                return $items;
            case 'map':
                $entries = [];
                $n = $this->collectionSize();
                for ($k = 0; $k < $n; $k++) {
                    $key = $this->readObject();
                    $entries[] = [$key, $this->readObject()];
                }

                return $entries;
        }

        if (in_array($type, $this->enums, true)) {
            return new GwtEnum($type, $this->reader->readInt());
        }

        if (isset($this->layouts[$type])) {
            // Registreres før felterne læses, så felter kan referere tilbage til objektet.
            $object = new GwtObject($type);
            $this->seen[$id] = $object;
            foreach (explode(',', $this->layouts[$type]) as $kind) {
                $object->fields[] = match ($kind) {
                    'i' => $this->reader->readInt(),
                    's' => $this->reader->readString(),
                    'o' => $this->readObject(),
                    default => throw new \LogicException("Ukendt feltkode '$kind'."),
                };
            }

            return $object;
        }

        throw new SourceUnavailable('gwt_unknown_type');
    }

    private function typeName(int $token): string
    {
        $strings = $this->reader->strings();
        $type = $strings[$token - 1] ?? null;
        if ($type === null) {
            throw new SourceUnavailable('gwt_bad_string_ref');
        }

        return $type;
    }

    private function collectionSize(): int
    {
        $n = $this->reader->readInt();
        if ($n < 0 || $n > self::MAX_COLLECTION) {
            throw new SourceUnavailable('gwt_bad_collection_size');
        }

        return $n;
    }
}
