<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football;

use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Football\Systems\PlayerDevelopmentSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;
use Flair\Kernel\Football\Systems\TrainingSystem;
use PHPUnit\Framework\TestCase;

/**
 * Verifie mecaniquement les deux invariants promis par docs/13- §2 :
 * au plus un writer/remover par composant, et aucune lecture d'un
 * composant ecrit ou retire plus loin dans le pipeline.
 *
 * Aucun registre canonique type `Pipeline::SYSTEMS` n'existe encore dans
 * le noyau (docs/13- §2 n'en montre qu'un exemple illustratif, cote
 * domaine football, avec des classes qui n'existent pas) - la liste
 * ci-dessous est donc maintenue a la main et doit suivre l'ordre reel
 * declare dans `bin/demo.php`/`harness/Sampler`.
 */
final class PipelineInvariantsTest extends TestCase
{
    /** @return list<System> */
    private function pipeline(): array
    {
        return [
            new TrainingSystem(),
            new RetirementSystem(),
            new PlayerDevelopmentSystem(),
        ];
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
