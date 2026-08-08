<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Snapshot;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Kernel;

/**
 * L'enveloppe d'un snapshot : l'etat, plus tout ce qu'il faut savoir pour le
 * relire et le remettre en marche.
 *
 * L'enveloppe existe parce que **le tick n'est pas dans le WorldState**.
 * docs/13- §8 ecrit `$state->tick + 1` ; en realite le tick vit dans
 * TickContext, comme la graine, et un monde recharge sans eux ne sait pas
 * quel jour on est ni comment tirer ses aleas. Les stocker a cote de l'etat
 * est le seul moyen qu'un snapshot soit auto-suffisant.
 *
 * `format` versionne la structure du snapshot lui-meme, `kernelVersion` la
 * forme de l'etat qu'il contient (docs/12- §6). Les deux sont verifies au
 * chargement et une divergence **leve** : un monde vivant se fait migrer
 * explicitement, jamais rejouer (docs/13- §6).
 */
final readonly class WorldSnapshot
{
    public const int FORMAT = 1;

    /** @param array<string, mixed> $state la sortie de SnapshotCodec::encode() */
    public function __construct(
        public string $worldId,
        public int $tick,
        public int $seed,
        public string $rulesetVersion,
        public array $state,
        public string $kernelVersion = Kernel::VERSION,
        public int $format = self::FORMAT,
    ) {
    }

    public static function capture(
        SnapshotCodec $codec,
        WorldState $world,
        string $worldId,
        int $tick,
        int $seed,
        string $rulesetVersion,
    ): self {
        return new self($worldId, $tick, $seed, $rulesetVersion, $codec->encode($world));
    }

    public function restore(SnapshotCodec $codec): WorldState
    {
        return $codec->decode($this->state);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'kernelVersion' => $this->kernelVersion,
            'rulesetVersion' => $this->rulesetVersion,
            'worldId' => $this->worldId,
            'tick' => $this->tick,
            'seed' => $this->seed,
            'state' => $this->state,
        ];
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $format = $raw['format'] ?? null;
        if ($format !== self::FORMAT) {
            throw SnapshotFormatException::incompatibleFormat(is_int($format) ? $format : 0, self::FORMAT);
        }

        $kernelVersion = self::stringAt($raw, 'kernelVersion');
        if ($kernelVersion !== Kernel::VERSION) {
            throw SnapshotFormatException::incompatibleKernel($kernelVersion, Kernel::VERSION);
        }

        $state = $raw['state'] ?? null;
        if (!is_array($state)) {
            throw SnapshotFormatException::malformed('"state" absent ou non structure');
        }

        /** @var array<string, mixed> $state */
        return new self(
            worldId: self::stringAt($raw, 'worldId'),
            tick: self::intAt($raw, 'tick'),
            seed: self::intAt($raw, 'seed'),
            rulesetVersion: self::stringAt($raw, 'rulesetVersion'),
            state: $state,
            kernelVersion: $kernelVersion,
            format: $format,
        );
    }

    /** @param array<string, mixed> $raw */
    private static function stringAt(array $raw, string $key): string
    {
        $value = $raw[$key] ?? null;

        return is_string($value)
            ? $value
            : throw SnapshotFormatException::malformed("\"{$key}\" absent ou non textuel");
    }

    /** @param array<string, mixed> $raw */
    private static function intAt(array $raw, string $key): int
    {
        $value = $raw[$key] ?? null;

        return is_int($value)
            ? $value
            : throw SnapshotFormatException::malformed("\"{$key}\" absent ou non entier");
    }
}
