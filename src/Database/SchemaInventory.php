<?php

declare(strict_types=1);

namespace Quraba\Backup\Database;

use InvalidArgumentException;

/**
 * A deterministic inventory of the schema objects of ONE database: base
 * tables, views, sequences, procedures, functions, events and triggers.
 *
 * The same inventory is recorded in the restore journal, drives the exact
 * clearing of a database (every dropped object comes from it, nothing is
 * selected by pattern) and is compared again during verification.
 */
final readonly class SchemaInventory
{
    public const string TABLE = 'BASE TABLE';

    public const string VIEW = 'VIEW';

    public const string SEQUENCE = 'SEQUENCE';

    public const string PROCEDURE = 'PROCEDURE';

    public const string FUNCTION = 'FUNCTION';

    public const string EVENT = 'EVENT';

    public const string TRIGGER = 'TRIGGER';

    /**
     * Drop order. Triggers are not dropped on their own: they belong to
     * their table and disappear with it.
     */
    private const array ORDER = [self::EVENT => 0, self::TRIGGER => 1, self::VIEW => 2, self::PROCEDURE => 3, self::FUNCTION => 3, self::TABLE => 4, self::SEQUENCE => 5];

    /** @var list<array{type: string, name: string}> */
    public array $objects;

    /**
     * @param  list<array{type: string, name: string}>  $objects
     */
    public function __construct(array $objects)
    {
        foreach ($objects as $object) {
            if (! isset(self::ORDER[$object['type']]) || $object['name'] === '' || str_contains($object['name'], "\0")) {
                throw new InvalidArgumentException(sprintf('Unsupported schema object type [%s]; refusing to build an incomplete inventory.', $object['type']));
            }
        }

        usort($objects, static fn (array $a, array $b): int => [self::ORDER[$a['type']], $a['type'], $a['name']] <=> [self::ORDER[$b['type']], $b['type'], $b['name']]);

        $this->objects = $objects;
    }

    public function isEmpty(): bool
    {
        return $this->objects === [];
    }

    public function count(): int
    {
        return count($this->objects);
    }

    /**
     * @return list<string> sorted names of one object type
     */
    public function names(string $type): array
    {
        $names = [];

        foreach ($this->objects as $object) {
            if ($object['type'] === $type) {
                $names[] = $object['name'];
            }
        }

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Objects in the order they must be dropped (triggers excluded).
     *
     * @return list<array{type: string, name: string}>
     */
    public function droppable(): array
    {
        return array_values(array_filter($this->objects, static fn (array $object): bool => $object['type'] !== self::TRIGGER));
    }

    public function fingerprint(): string
    {
        return 'sha256:'.hash('sha256', implode("\n", array_map(static fn (array $object): string => $object['type'].'|'.$object['name'], $this->objects)));
    }

    /**
     * @return array{objects: int, by_type: array<string, int>, fingerprint: string}
     */
    public function summary(): array
    {
        $byType = [];

        foreach ($this->objects as $object) {
            $byType[$object['type']] = ($byType[$object['type']] ?? 0) + 1;
        }

        ksort($byType);

        return ['objects' => $this->count(), 'by_type' => $byType, 'fingerprint' => $this->fingerprint()];
    }
}
