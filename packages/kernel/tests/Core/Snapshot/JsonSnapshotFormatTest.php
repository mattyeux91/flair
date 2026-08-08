<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Snapshot;

use Flair\Kernel\Core\Ecs\EntityIdAllocator;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Snapshot\JsonSnapshotFormat;
use Flair\Kernel\Core\Snapshot\SnapshotCodec;
use Flair\Kernel\Core\Snapshot\SnapshotFormatException;
use Flair\Kernel\Core\Snapshot\TypeRegistry;
use Flair\Kernel\Core\Snapshot\WorldSnapshot;
use Flair\Kernel\Kernel;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureColour;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureComponent;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixturePoint;
use PHPUnit\Framework\TestCase;

final class JsonSnapshotFormatTest extends TestCase
{
    /**
     * Le test le plus important du format. Un flottant qui perd un bit ne
     * casse rien de visible : le monde repart, puis **diverge** au tick
     * suivant, et on cherche la cause trois saisons plus loin. On compare donc
     * les octets du double, pas les valeurs - `0.0 === -0.0` est vrai en PHP.
     *
     * Verifier ici plutot que lire `ini_get('serialize_precision')` : lire
     * l'environnement est interdit au noyau (docs/11- §1), et une machine mal
     * configuree doit faire echouer la CI, pas produire un avertissement.
     */
    public function testFloatsSurviveJsonBitForBit(): void
    {
        $adversarial = [
            0.1,
            0.1 + 0.2,
            1 / 3,
            M_PI,
            -0.0,
            1.0,
            PHP_FLOAT_EPSILON,
            PHP_FLOAT_MIN,
            PHP_FLOAT_MAX,
            1.0e-300,
            123456789.123456789,
        ];

        foreach ($adversarial as $value) {
            $restored = $this->roundTrip(self::component(ratio: $value));

            self::assertSame(
                pack('d', $value),
                pack('d', $restored->ratio),
                'Le flottant ' . var_export($value, true) . ' ne revient pas au bit pres.',
            );
        }
    }

    public function testIntegerKeyedMapsSurviveJson(): void
    {
        $restored = $this->roundTrip(self::component(points: [
            7 => new FixturePoint(1, 1),
            42 => new FixturePoint(2, 2),
        ]));

        self::assertSame([7, 42], array_keys($restored->points));
        self::assertEquals(new FixturePoint(2, 2), $restored->points[42]);
    }

    public function testTheEnvelopeCarriesWhatTheStateDoesNot(): void
    {
        $snapshot = JsonSnapshotFormat::fromJson(JsonSnapshotFormat::toJson(self::snapshot()));

        self::assertSame(1234, $snapshot->tick);
        self::assertSame(42, $snapshot->seed);
        self::assertSame('w-1', $snapshot->worldId);
        self::assertSame('ruleset-test', $snapshot->rulesetVersion);
        self::assertSame(Kernel::VERSION, $snapshot->kernelVersion);
    }

    public function testRefusesAnotherFormatVersion(): void
    {
        $raw = self::snapshot()->toArray();
        $raw['format'] = WorldSnapshot::FORMAT + 1;

        $this->expectException(SnapshotFormatException::class);
        $this->expectExceptionMessageMatches('/format/');

        JsonSnapshotFormat::fromJson((string) json_encode($raw));
    }

    /**
     * Un snapshot ecrit par un autre noyau peut contenir des composants d'une
     * autre forme. Le relire au mieux serait un rejeu deguise (docs/13- §6) :
     * une migration est une decision, pas un effet de bord du chargement.
     */
    public function testRefusesAnotherKernelVersion(): void
    {
        $raw = self::snapshot()->toArray();
        $raw['kernelVersion'] = '0.0.1-autre';

        $this->expectException(SnapshotFormatException::class);
        $this->expectExceptionMessageMatches('/migration explicite/');

        JsonSnapshotFormat::fromJson((string) json_encode($raw));
    }

    public function testRefusesIllegibleJson(): void
    {
        $this->expectException(SnapshotFormatException::class);

        JsonSnapshotFormat::fromJson('{ pas du json');
    }

    private function roundTrip(FixtureComponent $component): FixtureComponent
    {
        $world = new WorldState(new EntityIdAllocator(1));
        $world->components(FixtureComponent::class)->set(1, $component);

        $restored = JsonSnapshotFormat::fromJson(JsonSnapshotFormat::toJson(new WorldSnapshot(
            worldId: 'w-1',
            tick: 1,
            seed: 42,
            rulesetVersion: 'ruleset-test',
            state: self::codec()->encode($world),
        )))->restore(self::codec());

        $decoded = $restored->components(FixtureComponent::class)->get(1);
        self::assertInstanceOf(FixtureComponent::class, $decoded);

        return $decoded;
    }

    private static function snapshot(): WorldSnapshot
    {
        return new WorldSnapshot(
            worldId: 'w-1',
            tick: 1234,
            seed: 42,
            rulesetVersion: 'ruleset-test',
            state: self::codec()->encode(new WorldState()),
        );
    }

    private static function codec(): SnapshotCodec
    {
        return new SnapshotCodec(new TypeRegistry(
            components: ['fixture.component' => FixtureComponent::class],
        ));
    }

    /** @param array<int, FixturePoint> $points */
    private static function component(float $ratio = 0.5, array $points = []): FixtureComponent
    {
        return new FixtureComponent(
            count: 12,
            ratio: $ratio,
            label: 'douze',
            active: true,
            colour: FixtureColour::Red,
            origin: new FixturePoint(3, 4),
            points: $points === [] ? [5 => new FixturePoint(1, 2)] : $points,
            numbers: [1, 2, 3],
        );
    }
}
