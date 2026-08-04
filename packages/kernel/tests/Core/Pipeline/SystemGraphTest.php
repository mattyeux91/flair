<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Pipeline;

use Flair\Kernel\Core\Pipeline\PipelineCycleException;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemGraph;
use Flair\Kernel\Tests\Core\Pipeline\Fixtures\DeclaredSystem;
use PHPUnit\Framework\TestCase;

/**
 * `SystemGraph` derive l'ordre d'execution des declarations au lieu de le
 * faire ecrire a la main (docs/13- §2). Exerce ici sur des systemes factices,
 * sans domaine : l'accord entre l'ordre derive et la liste football reelle
 * est verifie par `Football\PipelineInvariantsTest`.
 */
final class SystemGraphTest extends TestCase
{
    public function testAReaderIsPlacedAfterTheWriterOfWhatItReads(): void
    {
        $reader = new DeclaredSystem(id: 'reader', reads: [GraphComponentA::class]);
        $writer = new DeclaredSystem(id: 'writer', writes: [GraphComponentA::class]);

        // Fourni a l'envers : le tri doit corriger, pas seulement detecter.
        self::assertSame(['writer', 'reader'], $this->ids(SystemGraph::sort([$reader, $writer])));
    }

    public function testARemoverAlsoConstrainsItsReaders(): void
    {
        $reader = new DeclaredSystem(id: 'reader', reads: [GraphComponentA::class]);
        $remover = new DeclaredSystem(id: 'remover', removes: [GraphComponentA::class]);

        self::assertSame(['remover', 'reader'], $this->ids(SystemGraph::sort([$reader, $remover])));
    }

    /**
     * La propriete qui fait tout tenir : la ou aucune dependance ne tranche,
     * l'ordre fourni est conserve. C'est ce qui permet de deposer un nouveau
     * systeme n'importe ou sans deplacer les autres, et ce qui met le monde a
     * l'abri d'un renommage (docs/13- §4.5).
     */
    public function testIndependentSystemsKeepTheOrderTheyWereGivenIn(): void
    {
        $systems = [
            new DeclaredSystem(id: 'zulu'),
            new DeclaredSystem(id: 'alpha'),
            new DeclaredSystem(id: 'mike'),
        ];

        self::assertSame(['zulu', 'alpha', 'mike'], $this->ids(SystemGraph::sort($systems)));
    }

    public function testOnlyTheConstrainedSystemMovesTheRestStayPut(): void
    {
        $systems = [
            new DeclaredSystem(id: 'first'),
            new DeclaredSystem(id: 'reader', reads: [GraphComponentA::class]),
            new DeclaredSystem(id: 'third'),
            new DeclaredSystem(id: 'writer', writes: [GraphComponentA::class]),
        ];

        self::assertSame(['first', 'third', 'writer', 'reader'], $this->ids(SystemGraph::sort($systems)));
    }

    /**
     * `FacilitiesSystem` lit et ecrit `Facilities`, `CompetitionSystem` lit et
     * ecrit `Standings` : un systeme ne peut pas passer avant lui-meme, et une
     * arete reflexive ferait croire a un cycle.
     */
    public function testASystemThatReadsWhatItWritesIsNotACycle(): void
    {
        $selfish = new DeclaredSystem(
            id: 'selfish',
            reads: [GraphComponentA::class],
            writes: [GraphComponentA::class],
        );

        self::assertSame(['selfish'], $this->ids(SystemGraph::sort([$selfish])));
    }

    /**
     * `creates()` n'est pas une arete : le createur ne pose ses composants que
     * sur une entite qui n'existait pas quand le lecteur a itere. C'est ce qui
     * laisse `YouthIntakeSystem` en tete alors qu'il cree `Contract`, dont
     * `SquadSystem` est le writer.
     */
    public function testCreatesDoesNotConstrainTheOrder(): void
    {
        $creator = new DeclaredSystem(id: 'creator', creates: [GraphComponentA::class]);
        $reader = new DeclaredSystem(id: 'reader', reads: [GraphComponentA::class]);

        self::assertSame(['reader', 'creator'], $this->ids(SystemGraph::sort([$reader, $creator])));
    }

    public function testATransitiveChainIsFullyOrdered(): void
    {
        $third = new DeclaredSystem(id: 'third', reads: [GraphComponentB::class]);
        $second = new DeclaredSystem(id: 'second', reads: [GraphComponentA::class], writes: [GraphComponentB::class]);
        $first = new DeclaredSystem(id: 'first', writes: [GraphComponentA::class]);

        self::assertSame(['first', 'second', 'third'], $this->ids(SystemGraph::sort([$third, $second, $first])));
    }

    public function testACycleIsRefusedAndNamesTheSystemsInvolved(): void
    {
        $a = new DeclaredSystem(id: 'ping', reads: [GraphComponentB::class], writes: [GraphComponentA::class]);
        $b = new DeclaredSystem(id: 'pong', reads: [GraphComponentA::class], writes: [GraphComponentB::class]);

        try {
            SystemGraph::sort([$a, $b]);
            self::fail('Un cycle de dependances aurait du lever.');
        } catch (PipelineCycleException $e) {
            self::assertStringContainsString('ping', $e->getMessage());
            self::assertStringContainsString('pong', $e->getMessage());
            // Le contrat est de citer *une* arete du cycle en exemple, pas une
            // en particulier : les deux sont des points d'entree valables.
            self::assertMatchesRegularExpression(
                '/' . preg_quote(GraphComponentA::class, '/') . '|' . preg_quote(GraphComponentB::class, '/') . '/',
                $e->getMessage(),
            );
            // Le message doit orienter vers la correction, pas seulement constater.
            self::assertStringContainsString('evenement', $e->getMessage());
        }
    }

    public function testASystemOutsideTheCycleIsNotBlamedForIt(): void
    {
        $innocent = new DeclaredSystem(id: 'innocent');
        $a = new DeclaredSystem(id: 'ping', reads: [GraphComponentB::class], writes: [GraphComponentA::class]);
        $b = new DeclaredSystem(id: 'pong', reads: [GraphComponentA::class], writes: [GraphComponentB::class]);

        try {
            SystemGraph::sort([$innocent, $a, $b]);
            self::fail('Un cycle de dependances aurait du lever.');
        } catch (PipelineCycleException $e) {
            self::assertStringNotContainsString('innocent', $e->getMessage());
        }
    }

    public function testAnEmptyPipelineSortsToNothing(): void
    {
        self::assertSame([], SystemGraph::sort([]));
    }

    public function testSortingIsIdempotent(): void
    {
        $systems = [
            new DeclaredSystem(id: 'reader', reads: [GraphComponentA::class]),
            new DeclaredSystem(id: 'writer', writes: [GraphComponentA::class]),
            new DeclaredSystem(id: 'loner'),
        ];

        $once = SystemGraph::sort($systems);

        self::assertSame($this->ids($once), $this->ids(SystemGraph::sort($once)));
    }

    /**
     * Deux writers du meme composant sont interdits par
     * `Football\PipelineInvariantsTest`, mais `SystemGraph` est generique et ne
     * peut pas le presupposer : il doit alors placer le lecteur apres les deux.
     */
    public function testAReaderIsPlacedAfterEveryWriterOfTheComponent(): void
    {
        $reader = new DeclaredSystem(id: 'reader', reads: [GraphComponentA::class]);
        $writerA = new DeclaredSystem(id: 'writer-a', writes: [GraphComponentA::class]);
        $writerB = new DeclaredSystem(id: 'writer-b', removes: [GraphComponentA::class]);

        self::assertSame(
            ['writer-a', 'writer-b', 'reader'],
            $this->ids(SystemGraph::sort([$reader, $writerA, $writerB])),
        );
    }

    /**
     * @param list<System> $systems
     * @return list<string>
     */
    private function ids(array $systems): array
    {
        return array_map(static fn (System $system): string => $system->id(), $systems);
    }
}

final class GraphComponentA
{
}

final class GraphComponentB
{
}
