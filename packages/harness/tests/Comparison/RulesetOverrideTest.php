<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Comparison;

use Flair\Harness\Comparison\RulesetOverride;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\MarketValueBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use PHPUnit\Framework\TestCase;

final class RulesetOverrideTest extends TestCase
{
    /**
     * Reproduit le test qui aurait attrape le bug `PositionBalance` documente
     * dans CLAUDE.md : `withFields()` reconstruit `Balance` en entier, donc un
     * groupe non-surchargeable mais omis de ce `new Balance(...)` repart
     * silencieusement a ses defauts. `market` est reconduit explicitement -
     * ce test garde cette ligne honnete.
     */
    public function testANonDefaultMarketValueBalanceSurvivesAnUnrelatedOverride(): void
    {
        $base = new Ruleset('test', new Balance(market: new MarketValueBalance(baseValueCents: 999_999)));

        $modified = RulesetOverride::withFields($base, ['developmentRate' => 1.5]);

        self::assertSame(999_999, $modified->balance->market->baseValueCents);
    }

    public function testAppliesOverridesAcrossAllFourGroupsInOnePass(): void
    {
        $base = new Ruleset('test');

        $modified = RulesetOverride::withFields($base, [
            'developmentRate' => 1.5,
            'retirementEligibleAge' => 31.0,
            'growthPlateauFactor' => 0.5,
            'startingSkillRatio' => 0.6,
        ]);

        self::assertSame(1.5, $modified->balance->developmentRate);
        self::assertSame(31.0, $modified->balance->retirement->retirementEligibleAge);
        self::assertSame(0.5, $modified->balance->playerDevelopment->growthPlateauFactor);
        self::assertSame(0.6, $modified->balance->youthIntake->startingSkillRatio);

        // Champs non touches : la valeur par defaut du Ruleset de base, pas 0/null.
        self::assertSame($base->balance->trainingRate, $modified->balance->trainingRate);
        self::assertSame($base->balance->retirement->retirementAgeWeight, $modified->balance->retirement->retirementAgeWeight);
        self::assertSame($base->balance->youthIntake->ceilingMin, $modified->balance->youthIntake->ceilingMin);
    }

    public function testUnknownFieldThrowsBeforeApplyingAnyOverride(): void
    {
        $base = new Ruleset('test');

        $this->expectException(\InvalidArgumentException::class);

        RulesetOverride::withFields($base, [
            'retirementEligibleAge' => 31.0,
            'champInexistant' => 1.0,
        ]);
    }

    public function testOutOfBoundsTalentSkewThrowsBeforeApplyingAnyOverride(): void
    {
        $base = new Ruleset('test');

        $this->expectException(\InvalidArgumentException::class);

        // talentSkew est une borne de boucle dans PlayerFactory::drawTalent()
        // (docs/12- §7) : une valeur demesuree declenche des dizaines/
        // centaines de millions de tirages RNG et un timeout PHP (30s).
        RulesetOverride::withFields($base, [
            'talentSkew' => 999_999.0,
        ]);
    }

    public function testOutOfBoundsBaseIntakePerClubThrowsBeforeApplyingAnyOverride(): void
    {
        $base = new Ruleset('test');

        $this->expectException(\InvalidArgumentException::class);

        // baseIntakePerClub borne le nombre de promotions par club et par
        // saison (YouthIntakeSystem::update()) - meme risque de boucle
        // demesuree que talentSkew.
        RulesetOverride::withFields($base, [
            'baseIntakePerClub' => 1_000_000.0,
        ]);
    }

    public function testYouthIntakeIntFieldsAreCastWithoutTypeError(): void
    {
        $base = new Ruleset('test');

        // array<string,float> : meme les champs int de YouthIntakeBalance
        // arrivent en float depuis le JSON/CLI - withFields doit les caster
        // explicitement, sinon YouthIntakeBalance (parametres `int`, sous
        // declare(strict_types=1)) leve un TypeError.
        $modified = RulesetOverride::withFields($base, [
            'ceilingMin' => 40.0,
            'talentSkew' => 5.0,
            'intakeDayOfYear' => 200.0,
        ]);

        self::assertSame(40, $modified->balance->youthIntake->ceilingMin);
        self::assertSame(5, $modified->balance->youthIntake->talentSkew);
        self::assertSame(200, $modified->balance->youthIntake->intakeDayOfYear);
    }

    public function testAppliesOverridesAcrossTheThreeNewGroups(): void
    {
        $base = new Ruleset('test');

        $modified = RulesetOverride::withFields($base, [
            'matchdayIntervalDays' => 10.0,
            'homeAdvantage' => 0.4,
            'pointsForWin' => 2.0,
        ]);

        self::assertSame(10, $modified->balance->calendar->matchdayIntervalDays);
        self::assertSame(0.4, $modified->balance->match->homeAdvantage);
        self::assertSame(2, $modified->balance->competition->pointsForWin);

        // Champs non touches : la valeur par defaut du Ruleset de base, pas 0/null.
        self::assertSame($base->balance->calendar->seasonStartDayOfYear, $modified->balance->calendar->seasonStartDayOfYear);
        self::assertSame($base->balance->match->strengthScale, $modified->balance->match->strengthScale);
        self::assertSame($base->balance->competition->pointsForDraw, $modified->balance->competition->pointsForDraw);
    }

    public function testCalendarMatchAndCompetitionIntFieldsAreCastWithoutTypeError(): void
    {
        $base = new Ruleset('test');

        // Meme piege que YouthIntakeBalance : CalendarBalance/CompetitionBalance
        // sont entierement typees `int`, et `maxSimulatedGoals` est le seul
        // champ `int` de MatchBalance (sinon tout `float`).
        $modified = RulesetOverride::withFields($base, [
            'seasonStartDayOfYear' => 10.0,
            'firstMatchdayOffsetDays' => 21.0,
            'maxSimulatedGoals' => 8.0,
            'pointsForDraw' => 1.0,
        ]);

        self::assertSame(10, $modified->balance->calendar->seasonStartDayOfYear);
        self::assertSame(21, $modified->balance->calendar->firstMatchdayOffsetDays);
        self::assertSame(8, $modified->balance->match->maxSimulatedGoals);
        self::assertSame(1, $modified->balance->competition->pointsForDraw);
    }

    /**
     * Contrairement a `market` (encore un passe-plat), `transfer` est
     * reellement surchargeable des sa creation : la verification du point 2
     * (docs/17-marche-transferts.md) est une campagne a graines appariees qui
     * balaie ces coefficients. Meme piege `int`/`float` que `YouthIntakeBalance`.
     */
    public function testAppliesOverridesToTransferBalance(): void
    {
        $base = new Ruleset('test');

        $modified = RulesetOverride::withFields($base, [
            'negotiationOpeningDayOfYear' => 210.0,
            'maxRounds' => 8.0,
            'openingOfferShare' => 0.6,
        ]);

        self::assertSame(210, $modified->balance->transfer->negotiationOpeningDayOfYear);
        self::assertSame(8, $modified->balance->transfer->maxRounds);
        self::assertSame(0.6, $modified->balance->transfer->openingOfferShare);

        // Champs non touches : la valeur par defaut du Ruleset de base, pas 0/null.
        self::assertSame($base->balance->transfer->buyerFlexMargin, $modified->balance->transfer->buyerFlexMargin);
        self::assertSame($base->balance->transfer->financialDistressScaleCents, $modified->balance->transfer->financialDistressScaleCents);
    }

    public function testAllFieldsGroupsCoverAllDeclaredFields(): void
    {
        self::assertSame(
            \count(RulesetOverride::ALL_FIELDS),
            \count(array_unique(RulesetOverride::ALL_FIELDS)),
            'ALL_FIELDS ne doit pas contenir de doublon entre les groupes',
        );

        $fieldsFromGroups = array_merge(...array_values(RulesetOverride::GROUPS));
        sort($fieldsFromGroups);
        $allFields = RulesetOverride::ALL_FIELDS;
        sort($allFields);

        self::assertSame($allFields, $fieldsFromGroups, 'GROUPS doit couvrir exactement ALL_FIELDS');
    }
}
