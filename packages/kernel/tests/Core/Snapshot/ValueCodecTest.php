<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Snapshot;

use Flair\Kernel\Core\Snapshot\SnapshotFormatException;
use Flair\Kernel\Core\Snapshot\ValueCodec;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureColour;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureComponent;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureLeakyComponent;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixturePoint;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureRank;
use PHPUnit\Framework\TestCase;

final class ValueCodecTest extends TestCase
{
    public function testRoundTripsEveryShapeOfTheContract(): void
    {
        $codec = new ValueCodec();
        $original = self::component();

        $decoded = $codec->decode(FixtureComponent::class, $codec->encode($original));

        self::assertEquals($original, $decoded);
    }

    public function testEncodesInDeclarationOrder(): void
    {
        $encoded = (new ValueCodec())->encode(new FixturePoint(1, 2));

        self::assertSame(['x' => 1, 'y' => 2], $encoded);
    }

    public function testKeepsIntegerKeysOfAMap(): void
    {
        $codec = new ValueCodec();
        $original = self::component(points: [7 => new FixturePoint(1, 1), 42 => new FixturePoint(2, 2)]);

        $decoded = $codec->decode(FixtureComponent::class, $codec->encode($original));

        self::assertInstanceOf(FixtureComponent::class, $decoded);
        self::assertSame([7, 42], array_keys($decoded->points));
    }

    public function testEncodesABackedEnumToItsValue(): void
    {
        $codec = new ValueCodec();

        self::assertSame('blue', $codec->encode(FixtureColour::Blue));
        self::assertSame(2, $codec->encode(FixtureRank::Second));
        self::assertSame(FixtureColour::Blue, $codec->decode(FixtureColour::class, 'blue'));
    }

    /**
     * Un flottant de valeur entiere peut revenir en `int` d'un JSON ecrit sans
     * JSON_PRESERVE_ZERO_FRACTION. Le codec le recadre plutot que de laisser
     * strict_types lever un TypeError au constructeur, illisible et loin de la
     * cause.
     */
    public function testAcceptsAnIntegerWhereAFloatIsExpected(): void
    {
        $decoded = (new ValueCodec())->decode(FixtureComponent::class, self::encodedComponent(['ratio' => 3]));

        self::assertInstanceOf(FixtureComponent::class, $decoded);
        self::assertSame(3.0, $decoded->ratio);
    }

    public function testRefusesAClassThatWouldLoseState(): void
    {
        $this->expectException(SnapshotFormatException::class);
        $this->expectExceptionMessageMatches('/non publique ou non promue/');

        (new ValueCodec())->encode(new FixtureLeakyComponent(1));
    }

    public function testRefusesNonFiniteFloats(): void
    {
        $this->expectException(SnapshotFormatException::class);
        $this->expectExceptionMessageMatches('/NAN ou infini/');

        (new ValueCodec())->encode(self::component(ratio: NAN));
    }

    public function testRefusesAMissingField(): void
    {
        $raw = self::encodedComponent();
        unset($raw['label']);

        $this->expectException(SnapshotFormatException::class);
        $this->expectExceptionMessageMatches('/\$label absent/');

        (new ValueCodec())->decode(FixtureComponent::class, $raw);
    }

    /**
     * Un champ inconnu signale un snapshot ecrit par une autre forme du monde.
     * L'ignorer laisserait un monde repartir avec de l'etat manquant ailleurs :
     * c'est une migration, donc une decision explicite (docs/13- §6).
     */
    public function testRefusesAnUnknownField(): void
    {
        $this->expectException(SnapshotFormatException::class);
        $this->expectExceptionMessageMatches('/champs inconnus obsolete/');

        (new ValueCodec())->decode(FixtureComponent::class, self::encodedComponent(['obsolete' => 1]));
    }

    public function testRefusesNullOnANonNullableField(): void
    {
        $this->expectException(SnapshotFormatException::class);
        $this->expectExceptionMessageMatches('/\$count est null/');

        (new ValueCodec())->decode(FixtureComponent::class, self::encodedComponent(['count' => null]));
    }

    public function testRefusesAMistypedField(): void
    {
        $this->expectException(SnapshotFormatException::class);
        $this->expectExceptionMessageMatches('/\$count attend un entier/');

        (new ValueCodec())->decode(FixtureComponent::class, self::encodedComponent(['count' => 'douze']));
    }

    /** @param array<int, FixturePoint> $points */
    private static function component(array $points = [], float $ratio = 0.5): FixtureComponent
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
            optional: null,
            maybe: 9,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function encodedComponent(array $overrides = []): array
    {
        $encoded = (new ValueCodec())->encode(self::component());
        self::assertIsArray($encoded);

        return [...$encoded, ...$overrides];
    }
}
