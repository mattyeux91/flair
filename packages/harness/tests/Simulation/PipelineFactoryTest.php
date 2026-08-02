<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Simulation;

use Flair\Harness\Simulation\PipelineFactory;
use Flair\Kernel\Football\Systems\CalendarSystem;
use Flair\Kernel\Football\Systems\CompetitionSystem;
use Flair\Kernel\Football\Systems\FacilitiesSystem;
use Flair\Kernel\Football\Systems\FinanceSystem;
use Flair\Kernel\Football\Systems\MatchSystem;
use Flair\Kernel\Football\Systems\PlayerDevelopmentSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;
use Flair\Kernel\Football\Systems\TrainingSystem;
use Flair\Kernel\Football\Systems\YouthIntakeSystem;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PipelineFactoryTest extends TestCase
{
    /**
     * Seule source de verite pour l'ordre du pipeline (cf. docblock de
     * classe) - un changement d'ordre ici est correctness-sensitive
     * (plusieurs contraintes documentees dans CLAUDE.md), donc ce test doit
     * echouer bruyamment si quelqu'un le modifie sans le vouloir.
     */
    public function testBuildsTheNineSystemsInTheDeclaredOrder(): void
    {
        $pipeline = PipelineFactory::build();

        $property = new ReflectionProperty($pipeline, 'systems');
        $systems = $property->getValue($pipeline);
        if (!\is_array($systems)) {
            self::fail('Pipeline::$systems devrait etre un tableau.');
        }

        $classes = [];
        foreach ($systems as $system) {
            if (!\is_object($system)) {
                self::fail('Pipeline::$systems devrait ne contenir que des objets System.');
            }
            $classes[] = $system::class;
        }

        self::assertSame([
            FacilitiesSystem::class,
            YouthIntakeSystem::class,
            TrainingSystem::class,
            RetirementSystem::class,
            FinanceSystem::class,
            PlayerDevelopmentSystem::class,
            CalendarSystem::class,
            MatchSystem::class,
            CompetitionSystem::class,
        ], $classes);
    }
}
