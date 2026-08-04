<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football;

use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemGraph;
use Flair\Kernel\Football\FootballPipeline;
use Flair\Kernel\Football\Systems\CalendarSystem;
use Flair\Kernel\Football\Systems\CompetitionSystem;
use Flair\Kernel\Football\Systems\ContractSystem;
use Flair\Kernel\Football\Systems\FacilitiesSystem;
use Flair\Kernel\Football\Systems\FinanceSystem;
use Flair\Kernel\Football\Systems\MatchSystem;
use Flair\Kernel\Football\Systems\PlayerDevelopmentSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;
use Flair\Kernel\Football\Systems\SquadSystem;
use Flair\Kernel\Football\Systems\TrainingSystem;
use Flair\Kernel\Football\Systems\YouthIntakeSystem;
use PHPUnit\Framework\TestCase;

/**
 * Verifie mecaniquement les deux invariants promis par docs/13- §2 :
 * au plus un writer/remover par composant, et aucune lecture d'un
 * composant ecrit ou retire plus loin dans le pipeline.
 *
 * Porte aussi l'**assertion doree** sur la composition du pipeline. Le
 * registre canonique (`Football\FootballPipeline`) etant l'unique ecriture de
 * cette liste, ce fichier en est la seconde - c'est exactement le role d'un
 * test dore : rendre bruyant un changement de composition ou d'ordre, tous
 * deux correctness-sensitive.
 *
 * Cette assertion est devenue reellement necessaire maintenant que l'ordre
 * est **derive** (`Core\Pipeline\SystemGraph`) : un ordre qui emerge d'un tri
 * peut se decaler en silence sur une simple edition de `reads()`, sans qu'un
 * seul diff de la liste ne le montre. C'est ce qu'elle attrape.
 */
final class PipelineInvariantsTest extends TestCase
{
    /**
     * L'ordre canonique attendu. Doubler la liste du registre est
     * intentionnel : une assertion doree qui lirait sa propre reference ne
     * verifierait rien.
     *
     * @return list<class-string<System>>
     */
    private function expectedOrder(): array
    {
        return [
            FacilitiesSystem::class,
            YouthIntakeSystem::class,
            SquadSystem::class,
            TrainingSystem::class,
            RetirementSystem::class,
            FinanceSystem::class,
            PlayerDevelopmentSystem::class,
            CalendarSystem::class,
            MatchSystem::class,
            CompetitionSystem::class,
            ContractSystem::class,
        ];
    }

    /** @return list<System> */
    private function pipeline(): array
    {
        return FootballPipeline::systems();
    }

    public function testTheCanonicalPipelineHasExactlyTheExpectedSystemsInOrder(): void
    {
        $actual = array_map(
            static fn (System $system): string => $system::class,
            $this->pipeline(),
        );

        self::assertSame($this->expectedOrder(), $actual);
    }

    /**
     * L'ordre ecrit a la main et l'ordre derive doivent coincider.
     *
     * Le tri etant stable, ce n'est pas automatique : il ne deplace un systeme
     * que si une dependance l'exige, donc une declaration mal ordonnee serait
     * silencieusement corrigee a l'execution. Ce test rend la correction
     * bruyante - le monde continuerait de tourner juste, mais la liste ne
     * dirait plus la verite sur ce qui s'execute, et c'est precisement ce
     * mensonge-la qu'on vient de supprimer partout ailleurs.
     */
    public function testTheHandWrittenDeclarationIsAlreadyAValidExecutionOrder(): void
    {
        $declared = array_map(
            static fn (System $system): string => $system::class,
            FootballPipeline::declaration(),
        );
        $derived = array_map(
            static fn (System $system): string => $system::class,
            SystemGraph::sort(FootballPipeline::declaration()),
        );

        self::assertSame($declared, $derived);
    }

    /**
     * `id()` n'est pas cosmetique : c'est de lui que se derive le flux RNG
     * d'un systeme (docs/13- §4.1). Deux systemes qui le partageraient
     * tireraient la meme sequence pour la meme entite au meme tick - une
     * correlation invisible, qu'aucun test de determinisme n'attraperait
     * puisque le monde resterait parfaitement reproductible.
     */
    public function testEverySystemHasAUniqueId(): void
    {
        $ids = array_map(static fn (System $system): string => $system->id(), $this->pipeline());

        self::assertSame($ids, array_values(array_unique($ids)));
    }

    public function testAtMostOneSystemWritesEachComponent(): void
    {
        $writers = [];
        foreach ($this->pipeline() as $system) {
            foreach ($system->writes() as $component) {
                self::assertArrayNotHasKey($component, $writers, sprintf(
                    '%s ecrit deja %s, %s ne peut pas l\'ecrire aussi',
                    $writers[$component] ?? '',
                    $component,
                    $system->id(),
                ));
                $writers[$component] = $system->id();
            }
        }

        self::assertNotEmpty($writers);
    }

    public function testAtMostOneSystemRemovesEachComponent(): void
    {
        $removers = [];
        foreach ($this->pipeline() as $system) {
            foreach ($system->removes() as $component) {
                self::assertArrayNotHasKey($component, $removers, sprintf(
                    '%s retire deja %s, %s ne peut pas le retirer aussi',
                    $removers[$component] ?? '',
                    $component,
                    $system->id(),
                ));
                $removers[$component] = $system->id();
            }
        }

        self::assertNotEmpty($removers);
    }

    public function testAtMostOneSystemCreatesEachComponent(): void
    {
        $creators = [];
        foreach ($this->pipeline() as $system) {
            foreach ($system->creates() as $component) {
                self::assertArrayNotHasKey($component, $creators, sprintf(
                    '%s cree deja %s, %s ne peut pas le creer aussi',
                    $creators[$component] ?? '',
                    $component,
                    $system->id(),
                ));
                $creators[$component] = $system->id();
            }
        }

        self::assertNotEmpty($creators);
    }

    /**
     * Depuis que l'ordre est derive, ce test ne verifie plus le travail d'un
     * humain mais la **postcondition de `SystemGraph::sort()`** : le tri
     * garantit exactement cette propriete, donc un echec ici denonce un bug
     * du graphe, pas une liste mal ecrite. Conserve a ce titre - c'est le
     * test de regression de l'invariant que tout le lot promet.
     *
     * `creates()` en est volontairement absent, alors que `writes()`/
     * `removes()` y sont, pour la meme raison qui l'exclut des aretes du
     * graphe. Un createur ne pose ses composants que sur une entite qui
     * n'existait pas quand le lecteur a itere : il ne peut donc pas invalider
     * une lecture deja faite. Un joueur cree par un systeme place plus loin
     * dans le pipeline est simplement pris en compte au tick suivant -
     * exactement le decalage que l'OutQueue impose deja aux evenements
     * (docs/13- §2).
     */
    public function testNoSystemReadsAComponentWrittenOrRemovedLaterInThePipeline(): void
    {
        $systems = $this->pipeline();

        foreach ($systems as $readerIndex => $reader) {
            foreach ($reader->reads() as $component) {
                foreach ($systems as $laterIndex => $later) {
                    if ($laterIndex <= $readerIndex) {
                        continue;
                    }

                    self::assertNotContains($component, $later->writes(), sprintf(
                        '%s lit %s, mais %s (plus loin dans le pipeline) l\'ecrit - dependance inversee',
                        $reader->id(),
                        $component,
                        $later->id(),
                    ));
                    self::assertNotContains($component, $later->removes(), sprintf(
                        '%s lit %s, mais %s (plus loin dans le pipeline) le retire - dependance inversee',
                        $reader->id(),
                        $component,
                        $later->id(),
                    ));
                }
            }
        }
    }
}
