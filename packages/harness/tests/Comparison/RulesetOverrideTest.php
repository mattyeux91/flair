<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Comparison;

use Flair\Harness\Comparison\RulesetOverride;
use Flair\Kernel\Core\Ruleset\Ruleset;
use PHPUnit\Framework\TestCase;

final class RulesetOverrideTest extends TestCase
{
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
