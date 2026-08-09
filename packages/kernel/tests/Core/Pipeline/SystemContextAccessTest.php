<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Pipeline;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\OutQueue;
use Flair\Kernel\Core\Messaging\RequestQueue;
use Flair\Kernel\Core\Messaging\Scheduler;
use Flair\Kernel\Core\Pipeline\SeqCounter;
use Flair\Kernel\Core\Pipeline\SystemAccess;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Pipeline\UndeclaredAccessException;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Tests\Core\Pipeline\Fixtures\DeclaredSystem;
use PHPUnit\Framework\TestCase;

/**
 * Les declarations `reads`/`writes`/`creates`/`removes` d'un `System`
 * (docs/13- §2) sont opposables, pas documentaires.
 *
 * `Football\PipelineInvariantsTest` verifie les invariants *entre*
 * declarations (un seul writer, aucune lecture d'un composant ecrit plus
 * loin) ; il ne peut structurellement pas voir un acces non declare. C'est
 * ce trou-la que ce cas de test ferme.
 */
final class SystemContextAccessTest extends TestCase
{
    public function testReadingAnUndeclaredComponentIsRefused(): void
    {
        $ctx = $this->context(new DeclaredSystem(id: 'reader'));

        $this->expectException(UndeclaredAccessException::class);
        $this->expectExceptionMessage('ajoute-le a reads()');

        $ctx->read(AccessTestComponent::class);
    }

    public function testMutatingAnUndeclaredComponentIsRefused(): void
    {
        $ctx = $this->context(new DeclaredSystem(
            id: 'reader',
            reads: [AccessTestComponent::class],
        ));

        $this->expectException(UndeclaredAccessException::class);
        $this->expectExceptionMessage('ajoute-le a writes(), creates() ou removes()');

        $ctx->write(AccessTestComponent::class);
    }

    public function testDeclaringAComponentGrantsBothHandles(): void
    {
        $world = new WorldState();
        $ctx = $this->context(
            new DeclaredSystem(
                id: 'owner',
                reads: [AccessTestComponent::class],
                writes: [AccessTestComponent::class],
            ),
            $world,
        );

        $ctx->write(AccessTestComponent::class)->set(7, new AccessTestComponent(3));

        self::assertSame(3, $ctx->read(AccessTestComponent::class)->get(7)?->value);
        self::assertSame([7], $ctx->read(AccessTestComponent::class)->entities());
    }

    /**
     * `RetirementSystem` est exactement ce cas : `writes(): []`, uniquement
     * des `removes()`. Le handle doit lui etre accorde sur cette seule base,
     * mais sans lui ouvrir `set()`.
     */
    public function testARemoverGetsTheHandleWithoutGainingTheRightToSet(): void
    {
        $world = new WorldState();
        $world->components(AccessTestComponent::class)->set(7, new AccessTestComponent(3));

        $ctx = $this->context(
            new DeclaredSystem(id: 'remover', removes: [AccessTestComponent::class]),
            $world,
        );

        $ctx->write(AccessTestComponent::class)->remove(7);
        self::assertNull($world->components(AccessTestComponent::class)->get(7));

        $this->expectException(UndeclaredAccessException::class);
        $this->expectExceptionMessage('ne l\'a declare qu\'en removes()');

        $ctx->write(AccessTestComponent::class)->set(7, new AccessTestComponent(1));
    }

    public function testAWriterCannotRemoveWithoutDeclaringIt(): void
    {
        $ctx = $this->context(new DeclaredSystem(
            id: 'writer',
            writes: [AccessTestComponent::class],
        ));

        $this->expectException(UndeclaredAccessException::class);
        $this->expectExceptionMessage('ajoute-le a removes()');

        $ctx->write(AccessTestComponent::class)->remove(7);
    }

    /**
     * Le coeur de la dérogation `creates()` : elle n'autorise `set()` que sur
     * une entite que ce systeme a creee dans ce tick. C'est ce qui permet a
     * `YouthIntakeSystem` de poser `Contract` sans entrer en conflit avec
     * `SquadSystem`, son writer.
     */
    public function testACreatorMaySetOnlyOnAnEntityItCreatedInThisTick(): void
    {
        $world = new WorldState();
        $preexisting = $world->createEntity();

        $ctx = $this->context(
            new DeclaredSystem(id: 'creator', creates: [AccessTestComponent::class]),
            $world,
        );

        $created = $ctx->createEntity();
        $ctx->write(AccessTestComponent::class)->set($created, new AccessTestComponent(1));
        self::assertSame(1, $world->components(AccessTestComponent::class)->get($created)?->value);

        $this->expectException(UndeclaredAccessException::class);
        $this->expectExceptionMessage('qu\'il n\'a pas creee dans ce tick');

        $ctx->write(AccessTestComponent::class)->set($preexisting, new AccessTestComponent(2));
    }

    /**
     * La portee de `creates()` est "ce systeme, ce tick" : `Pipeline::tick()`
     * construit un `SystemContext` par systeme et par tick, donc une entite
     * creee au tick precedent n'est plus couverte.
     */
    public function testTheCreationRegisterDoesNotLeakAcrossContexts(): void
    {
        $world = new WorldState();
        $system = new DeclaredSystem(id: 'creator', creates: [AccessTestComponent::class]);

        $previousTick = $this->context($system, $world);
        $created = $previousTick->createEntity();

        $thisTick = $this->context($system, $world);

        $this->expectException(UndeclaredAccessException::class);

        $thisTick->write(AccessTestComponent::class)->set($created, new AccessTestComponent(1));
    }

    /**
     * Un composant a la fois en `writes()` et en `creates()` reste libre :
     * `writes()` couvre deja les entites preexistantes.
     */
    public function testDeclaringBothWritesAndCreatesLiftsTheCreatedEntityConstraint(): void
    {
        $world = new WorldState();
        $preexisting = $world->createEntity();

        $ctx = $this->context(
            new DeclaredSystem(
                id: 'writer-creator',
                writes: [AccessTestComponent::class],
                creates: [AccessTestComponent::class],
            ),
            $world,
        );

        $ctx->write(AccessTestComponent::class)->set($preexisting, new AccessTestComponent(9));

        self::assertSame(9, $world->components(AccessTestComponent::class)->get($preexisting)?->value);
    }

    public function testSingletonsGoThroughTheSameDeclarations(): void
    {
        $ctx = $this->context(new DeclaredSystem(id: 'silent'));

        $this->expectException(UndeclaredAccessException::class);

        $ctx->singleton(AccessTestComponent::class);
    }

    public function testSettingAnUndeclaredSingletonIsRefused(): void
    {
        $ctx = $this->context(new DeclaredSystem(
            id: 'silent',
            reads: [AccessTestComponent::class],
        ));

        $this->expectException(UndeclaredAccessException::class);

        $ctx->setSingleton(new AccessTestComponent(1));
    }

    public function testADeclaredSingletonRoundTrips(): void
    {
        $ctx = $this->context(new DeclaredSystem(
            id: 'accountant',
            reads: [AccessTestComponent::class],
            writes: [AccessTestComponent::class],
        ));

        $ctx->setSingleton(new AccessTestComponent(4));

        self::assertSame(4, $ctx->singleton(AccessTestComponent::class)?->value);
    }

    public function testTheMessageNamesTheSystemAndTheComponent(): void
    {
        $ctx = $this->context(new DeclaredSystem(id: 'guilty-system'));

        try {
            $ctx->read(AccessTestComponent::class);
            self::fail('Un acces non declare aurait du lever.');
        } catch (UndeclaredAccessException $e) {
            self::assertStringContainsString('guilty-system', $e->getMessage());
            self::assertStringContainsString(AccessTestComponent::class, $e->getMessage());
        }
    }

    private function context(DeclaredSystem $system, ?WorldState $world = null): SystemContext
    {
        return new SystemContext(
            tick: 1,
            systemIndex: 0,
            access: SystemAccess::of($system),
            worldSeed: 1,
            ruleset: new Ruleset('test'),
            intents: [],
            world: $world ?? new WorldState(),
            scheduler: new Scheduler(),
            outQueue: new OutQueue(),
            requests: new RequestQueue(),
            seq: new SeqCounter(),
        );
    }
}

final class AccessTestComponent
{
    public function __construct(public int $value)
    {
    }
}
