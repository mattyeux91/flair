<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Support;

use Flair\Kernel\Core\Ruleset\ContractBalance;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Support\WageModel;
use PHPUnit\Framework\TestCase;

final class WageModelTest extends TestCase
{
    public function testQualityAveragesTheThreeBlocksEqually(): void
    {
        self::assertSame(60, WageModel::quality(
            new PlayerPhysicalSkills(30, 30, 30, 30),
            new PlayerTechnicalSkills(60, 60, 60, 60, 60, 60, 60),
            new PlayerMentalSkills(90, 90, 90, 90, 90),
        ));
    }

    /**
     * Un bloc absent compte pour zero, jamais pour la moyenne des autres :
     * une entite amputee d'un tiers de ses competences n'est pas un joueur
     * ordinaire, et l'appelant doit s'en apercevoir (cf. docblock).
     */
    public function testAMissingBlockDragsTheQualityDownRatherThanBeingIgnored(): void
    {
        self::assertSame(40, WageModel::quality(
            new PlayerPhysicalSkills(60, 60, 60, 60),
            new PlayerTechnicalSkills(60, 60, 60, 60, 60, 60, 60),
            null,
        ));
    }

    public function testWageIsProportionalToQualityAtTheReference(): void
    {
        $contract = new ContractBalance(baseWagePerWeekCents: 50_000, referenceQuality: 50);

        self::assertSame(50_000, WageModel::perWeekCents(50, $contract));
        self::assertSame(100_000, WageModel::perWeekCents(100, $contract));
        self::assertSame(25_000, WageModel::perWeekCents(25, $contract));
    }

    /**
     * Le clamp de docs/14- §3 n'est pas un garde-fou defensif : il fixe
     * l'ecart maximal de salaire que le monde peut produire, donc l'amplitude
     * de son inegalite economique.
     */
    public function testTheMultiplierIsClampedOnBothEnds(): void
    {
        $contract = new ContractBalance(
            baseWagePerWeekCents: 50_000,
            referenceQuality: 50,
            wageMultiplierMin: 0.4,
            wageMultiplierMax: 2.5,
        );

        self::assertSame(20_000, WageModel::perWeekCents(1, $contract));
        self::assertSame(125_000, WageModel::perWeekCents(100_000, $contract));
    }

    /**
     * Un `Ruleset` mal rempli ne doit pas faire exploser un noyau qui tourne
     * 1 000 saisons sans surveillance - meme choix defensif que le clamp de
     * `meritShare` dans Football\FinanceSystem.
     */
    public function testAZeroReferenceFallsBackToTheBaseWageInsteadOfDividingByZero(): void
    {
        $contract = new ContractBalance(baseWagePerWeekCents: 42_000, referenceQuality: 0);

        self::assertSame(42_000, WageModel::perWeekCents(90, $contract));
    }
}
