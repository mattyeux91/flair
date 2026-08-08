<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Snapshot;

use Flair\Kernel\Core\Snapshot\SnapshotFormatException;
use Flair\Kernel\Core\Snapshot\TypeRegistry;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureComponent;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureEvent;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixturePoint;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureSingleton;
use PHPUnit\Framework\TestCase;

final class TypeRegistryTest extends TestCase
{
    /**
     * Les cles sont uniques toutes familles confondues, sans quoi classFor()
     * serait ambigu - et une ambiguite de format se decouvre au chargement
     * d'un monde, c'est-a-dire trop tard.
     */
    public function testRefusesTheSameKeyTwiceAcrossFamilies(): void
    {
        $this->expectException(SnapshotFormatException::class);
        $this->expectExceptionMessageMatches('/declaree deux fois/');

        new TypeRegistry(
            components: ['fixture.thing' => FixtureComponent::class],
            events: ['fixture.thing' => FixtureEvent::class],
        );
    }

    /**
     * Le tri par cle, et non l'ordre de declaration : c'est lui qui rend deux
     * snapshots du meme monde identiques octet pour octet.
     */
    public function testOrdersClassesByKeyNotByDeclaration(): void
    {
        $registry = new TypeRegistry(components: [
            'fixture.z' => FixtureComponent::class,
            'fixture.a' => FixturePoint::class,
        ]);

        self::assertSame([FixturePoint::class, FixtureComponent::class], $registry->componentClasses());
    }

    public function testRefusesAnUnregisteredClass(): void
    {
        $this->expectException(SnapshotFormatException::class);

        (new TypeRegistry())->keyFor(FixtureSingleton::class);
    }

    public function testKnowsWhatItHolds(): void
    {
        $registry = new TypeRegistry(singletons: ['fixture.singleton' => FixtureSingleton::class]);

        self::assertTrue($registry->knows(FixtureSingleton::class));
        self::assertFalse($registry->knows(FixtureComponent::class));
    }
}
