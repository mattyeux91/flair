<?php

declare(strict_types=1);

namespace Flair\Host\Store;

use Flair\Host\Database\Database;
use Illuminate\Database\Connection;

/**
 * Les mondes connus du Host. Volontairement minimal : creer, retrouver,
 * lister, et avancer le tick de commodite.
 */
final class WorldRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(WorldRecord $world): void
    {
        $now = date('c');

        $this->connection()->table('worlds')->insert([
            'id' => $world->id,
            'seed' => $world->seed,
            'kernel_version' => $world->kernelVersion,
            'ruleset_version' => $world->rulesetVersion,
            'tick' => $world->tick,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function find(string $worldId): ?WorldRecord
    {
        $row = $this->connection()->table('worlds')->where('id', $worldId)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function exists(string $worldId): bool
    {
        return $this->connection()->table('worlds')->where('id', $worldId)->exists();
    }

    /** @return list<WorldRecord> */
    public function all(): array
    {
        $rows = $this->connection()->table('worlds')->orderBy('id')->get();

        $worlds = [];
        foreach ($rows as $row) {
            $worlds[] = self::hydrate($row);
        }

        return $worlds;
    }

    public function recordTick(string $worldId, int $tick): void
    {
        $this->connection()->table('worlds')
            ->where('id', $worldId)
            ->update(['tick' => $tick, 'updated_at' => date('c')]);
    }

    private function connection(): Connection
    {
        return $this->database->connection();
    }

    private static function hydrate(object $row): WorldRecord
    {
        return new WorldRecord(
            id: Row::string($row, 'id'),
            seed: Row::int($row, 'seed'),
            kernelVersion: Row::string($row, 'kernel_version'),
            rulesetVersion: Row::string($row, 'ruleset_version'),
            tick: Row::int($row, 'tick'),
        );
    }
}
