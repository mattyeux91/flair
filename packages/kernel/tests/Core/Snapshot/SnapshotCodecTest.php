<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Snapshot;

use Flair\Kernel\Core\Ecs\EntityIdAllocator;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Snapshot\SnapshotCodec;
use Flair\Kernel\Core\Snapshot\SnapshotFormatException;
use Flair\Kernel\Core\Snapshot\TypeRegistry;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureColour;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureComponent;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureEvent;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureLeakyComponent;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixturePoint;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureRank;
use Flair\Kernel\Tests\Core\Snapshot\Fixtures\FixtureSingleton;
use PHPUnit\Framework\TestCase;

final class SnapshotCodecTest extends TestCase
{
    public function testRestoresComponentsSingletonsAndTheEntityCounter(): void
    {
        $world = self::world();
        $world->components(FixtureComponent::class)->set(3, self::component());
        $world->components(FixtureComponent::class)->set(1, self::component());
        $world->setSingleton(new FixtureSingleton(99));
        $world->createEntity();

        $restored = self::codec()->decode(self::codec()->encode($world));

        self::assertEquals(
            $world->components(FixtureComponent::class)->get(3),
            $restored->components(FixtureComponent::class)->get(3),
        );
        self::assertSame([1, 3], $restored->components(FixtureComponent::class)->entities());
        self::assertEquals(new FixtureSingleton(99), $restored->singleton(FixtureSingleton::class));
        self::assertSame($world->nextEntityId(), $restored->nextEntityId());
    }

    /**
     * Le compteur d'entites doit repartir ou il s'etait arrete : le
     * reattribuer casserait l'unicite promise par docs/12- §2, et un lot
     * entier de bugs silencieux avec elle.
     */
    public function testTheRestoredWorldNeverReissuesAnAllocatedId(): void
    {
        $world = self::world(nextEntityId: 500);
        $world->createEntity();
        $world->createEntity();

        $restored = self::codec()->decode(self::codec()->encode($world));

        self::assertSame(502, $restored->createEntity());
    }

    /**
     * La moitie qu'on oublie : un evenement seulement planifie n'a emis aucun
     * Fait, donc l'event log ne le rattraperait pas.
     */
    public function testRestoresTheSchedulerWithItsSortKeys(): void
    {
        $world = self::world();
        $world->scheduler()->schedule(new FixtureEvent(7, FixtureRank::First), atTick: 40, systemIndex: 2, entityId: 7, seq: 1);
        $world->scheduler()->schedule(new FixtureEvent(9, FixtureRank::Second), atTick: 10, systemIndex: 1, entityId: 9, seq: 0);

        $restored = self::codec()->decode(self::codec()->encode($world));

        self::assertSame(2, $restored->scheduler()->count());
        self::assertEquals([], $restored->scheduler()->drainDueBy(9));
        self::assertEquals(
            [new FixtureEvent(9, FixtureRank::Second), new FixtureEvent(7, FixtureRank::First)],
            $restored->scheduler()->drainDueBy(40),
        );
    }

    public function testRestoresTheOutQueueInItsSortedOrder(): void
    {
        $world = self::world();
        $world->outQueue()->emit(new FixtureEvent(2, FixtureRank::Second), systemIndex: 5, entityId: 2, seq: 3);
        $world->outQueue()->emit(new FixtureEvent(1, FixtureRank::First), systemIndex: 1, entityId: 8, seq: 0);

        $restored = self::codec()->decode(self::codec()->encode($world));

        self::assertEquals(
            [new FixtureEvent(1, FixtureRank::First), new FixtureEvent(2, FixtureRank::Second)],
            $restored->outQueue()->drain(),
        );
    }

    /**
     * components() cree un store vide a la lecture : ecrire ces stores ferait
     * dependre le contenu d'un snapshot des lectures qui l'ont precede.
     */
    public function testAStoreThatIsOnlyEmptyIsNotWritten(): void
    {
        $world = self::world();
        $world->components(FixtureComponent::class);

        $state = self::codec()->encode($world);

        self::assertSame([], $state['components']);
    }

    public function testTwoEncodingsOfTheSameWorldAreIdentical(): void
    {
        $world = self::world();
        $world->components(FixtureComponent::class)->set(9, self::component());
        $world->components(FixtureComponent::class)->set(2, self::component());
        $world->setSingleton(new FixtureSingleton(1));
        $world->outQueue()->emit(new FixtureEvent(1, FixtureRank::First), 0, 1, 0);

        self::assertSame(
            json_encode(self::codec()->encode($world)),
            json_encode(self::codec()->encode($world)),
        );
    }

    /**
     * Un composant present dans le monde mais absent du registre est de
     * l'etat qu'on ne saurait pas relire. Le silence est le seul comportement
     * inacceptable ici.
     */
    public function testRefusesToEncodeAnUnregisteredComponent(): void
    {
        $world = self::world();
        $world->components(FixtureLeakyComponent::class)->set(1, new FixtureLeakyComponent(1));

        $this->expectException(SnapshotFormatException::class);
        $this->expectExceptionMessageMatches('/n\'est enregistree dans aucun TypeRegistry/');

        self::codec()->encode($world);
    }

    public function testRefusesToDecodeAnUnknownKey(): void
    {
        $this->expectException(SnapshotFormatException::class);
        $this->expectExceptionMessageMatches('/Aucun type enregistre/');

        self::codec()->decode([
            'nextEntityId' => 1,
            'components' => ['fixture.unknown' => [1 => []]],
        ]);
    }

    public function testRefusesAStateWithoutAnEntityCounter(): void
    {
        $this->expectException(SnapshotFormatException::class);
        $this->expectExceptionMessageMatches('/nextEntityId/');

        self::codec()->decode(['components' => []]);
    }

    private static function codec(): SnapshotCodec
    {
        return new SnapshotCodec(new TypeRegistry(
            components: ['fixture.component' => FixtureComponent::class],
            singletons: ['fixture.singleton' => FixtureSingleton::class],
            events: ['fixture.event.happened' => FixtureEvent::class],
        ));
    }

    private static function world(int $nextEntityId = 1): WorldState
    {
        return new WorldState(new EntityIdAllocator($nextEntityId));
    }

    private static function component(): FixtureComponent
    {
        return new FixtureComponent(
            count: 12,
            ratio: 0.25,
            label: 'douze',
            active: true,
            colour: FixtureColour::Red,
            origin: new FixturePoint(3, 4),
            points: [5 => new FixturePoint(1, 2)],
            numbers: [1, 2, 3],
        );
    }
}
