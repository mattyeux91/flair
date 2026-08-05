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
    /** Un joueur uniforme note sa propre valeur : les poids d'un poste somment a 1. */
    public function testQualityOfAUniformPlayerIsThatUniformValue(): void
    {
        self::assertSame(60, WageModel::quality(
            new PlayerPhysicalSkills(60, 60, 60, 60),
            new PlayerTechnicalSkills(60, 60, 60, 60, 60, 60, 60),
            new PlayerMentalSkills(60, 60, 60, 60, 60),
        ));
    }

    /**
     * **Le changement qu'apporte le lot des postes.** Deux joueurs de meme
     * moyenne plate sur les seize attributs ne valent pas la meme chose : le
     * gardien specialise est value sur son metier, le joueur etale ne l'est
     * sur aucun. La moyenne plate d'avant les aurait declares identiques - et
     * un club aurait paye un gardien pour sa finition.
     */
    public function testASpecialistIsWorthMoreThanAGeneralistOfTheSameFlatAverage(): void
    {
        // Gardien : fort sur reflexes/handling/distribution/positioning/command,
        // faible sur tout le reste.
        $specialist = WageModel::quality(
            new PlayerPhysicalSkills(20, 20, 20, 90),
            new PlayerTechnicalSkills(20, 20, 20, 20, 90, 90, 90),
            new PlayerMentalSkills(20, 90, 20, 20, 90),
        );

        // Meme somme d'attributs (880 sur seize), etalee uniformement.
        $generalist = WageModel::quality(
            new PlayerPhysicalSkills(55, 55, 55, 55),
            new PlayerTechnicalSkills(55, 55, 55, 55, 55, 55, 55),
            new PlayerMentalSkills(55, 55, 55, 55, 55),
        );

        self::assertGreaterThan($generalist, $specialist);
    }

    /**
     * Un bloc absent rend zero, jamais une note calculee sur les autres : une
     * entite amputee d'un tiers de ses competences n'est pas un joueur
     * ordinaire, et l'appelant doit s'en apercevoir (cf. docblock).
     */
    public function testAMissingBlockYieldsZeroRatherThanAPartialQuality(): void
    {
        self::assertSame(0, WageModel::quality(
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
