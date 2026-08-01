<?php

declare(strict_types=1);

namespace Flair\Harness\Comparison;

use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\PlayerDevelopmentBalance;
use Flair\Kernel\Core\Ruleset\RetirementBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Ruleset\YouthIntakeBalance;

/**
 * Construit un Ruleset qui ne differe d'un autre que par un ensemble de
 * champs de calibration (`Balance` racine, `RetirementBalance`,
 * `PlayerDevelopmentBalance`, `YouthIntakeBalance`) - utilise par le CLI et
 * la page web pour la comparaison a graines appariees. Explicitement
 * enumere plutot que via reflection, dans le meme esprit que le reste du
 * noyau (aucune magie/reflection nulle part).
 *
 * `withFields()` applique N overrides en une seule passe - c'est ce qui
 * permet a l'appelant de faire varier plusieurs champs a la fois (par
 * exemple `trainingRate` et `retirementFragilityWeight` ensemble) plutot
 * que la limite a un seul champ de la premiere version de cette classe.
 * Validation fail-fast : un champ inconnu leve avant toute construction, pas
 * d'application partielle.
 *
 * `YouthIntakeBalance` est le seul groupe qui melange des champs `int` et
 * `float` - sous `declare(strict_types=1)`, passer un float a un parametre
 * `int` leve un `TypeError`. Chaque champ est donc caste explicitement selon
 * son type reel dans `YouthIntakeBalance`, jamais via une table de types
 * generique.
 */
final class RulesetOverride
{
    /** @var list<string> */
    public const array GLOBAL_FIELDS = [
        'developmentRate',
        'trainingRate',
    ];

    /** @var list<string> */
    public const array RETIREMENT_FIELDS = [
        'retirementEligibleAge',
        'retirementAgeWeight',
        'retirementFragilityWeight',
    ];

    /** @var list<string> */
    public const array PLAYER_DEVELOPMENT_FIELDS = [
        'growthPrimeAgeThreshold',
        'growthPlateauFactor',
        'declineRatePerYear',
        'physicalDeclineMultiplier',
        'technicalDeclineMultiplier',
        'mentalDeclineMultiplier',
    ];

    /** @var list<string> */
    public const array YOUTH_INTAKE_FIELDS = [
        'intakeDayOfYear',
        'intakeAgeYears',
        'baseIntakePerClub',
        'ceilingMin',
        'ceilingMax',
        'talentSkew',
        'startingSkillRatio',
        'startingSkillJitter',
        'physicalPeakAgeMin',
        'physicalPeakAgeMax',
        'technicalPeakAgeMin',
        'technicalPeakAgeMax',
        'mentalPeakAgeMin',
        'mentalPeakAgeMax',
        'growthRateMin',
        'growthRateMax',
        'fragilityMin',
        'fragilityMax',
    ];

    /** @var list<string> */
    public const array ALL_FIELDS = [
        ...self::GLOBAL_FIELDS,
        ...self::RETIREMENT_FIELDS,
        ...self::PLAYER_DEVELOPMENT_FIELDS,
        ...self::YOUTH_INTAKE_FIELDS,
    ];

    /**
     * Groupement par libelle humain, dans l'ordre ou le panneau de
     * calibration (web) doit les afficher - seul point de verite pour cet
     * ordre, pour que le CLI puisse un jour l'utiliser aussi.
     *
     * @var array<string, list<string>>
     */
    public const array GROUPS = [
        'Retraite' => self::RETIREMENT_FIELDS,
        'Développement' => self::PLAYER_DEVELOPMENT_FIELDS,
        'Formation des jeunes' => self::YOUTH_INTAKE_FIELDS,
        'Global' => self::GLOBAL_FIELDS,
    ];

    /** @param array<string, float> $overrides nom de champ (n'importe quel groupe) -> nouvelle valeur */
    public static function withFields(Ruleset $base, array $overrides): Ruleset
    {
        foreach (array_keys($overrides) as $field) {
            if (!\in_array($field, self::ALL_FIELDS, strict: true)) {
                throw new \InvalidArgumentException("Champ de Balance inconnu : {$field}");
            }
        }

        $balance = $base->balance;

        return new Ruleset($base->version, new Balance(
            developmentRate: $overrides['developmentRate'] ?? $balance->developmentRate,
            trainingRate: $overrides['trainingRate'] ?? $balance->trainingRate,
            retirement: self::withRetirement($balance->retirement, $overrides),
            playerDevelopment: self::withPlayerDevelopment($balance->playerDevelopment, $overrides),
            youthIntake: self::withYouthIntake($balance->youthIntake, $overrides),
        ));
    }

    /** @param array<string, float> $overrides */
    private static function withRetirement(RetirementBalance $base, array $overrides): RetirementBalance
    {
        return new RetirementBalance(
            retirementEligibleAge: $overrides['retirementEligibleAge'] ?? $base->retirementEligibleAge,
            retirementAgeWeight: $overrides['retirementAgeWeight'] ?? $base->retirementAgeWeight,
            retirementFragilityWeight: $overrides['retirementFragilityWeight'] ?? $base->retirementFragilityWeight,
        );
    }

    /** @param array<string, float> $overrides */
    private static function withPlayerDevelopment(PlayerDevelopmentBalance $base, array $overrides): PlayerDevelopmentBalance
    {
        return new PlayerDevelopmentBalance(
            growthPrimeAgeThreshold: $overrides['growthPrimeAgeThreshold'] ?? $base->growthPrimeAgeThreshold,
            growthPlateauFactor: $overrides['growthPlateauFactor'] ?? $base->growthPlateauFactor,
            declineRatePerYear: $overrides['declineRatePerYear'] ?? $base->declineRatePerYear,
            physicalDeclineMultiplier: $overrides['physicalDeclineMultiplier'] ?? $base->physicalDeclineMultiplier,
            technicalDeclineMultiplier: $overrides['technicalDeclineMultiplier'] ?? $base->technicalDeclineMultiplier,
            mentalDeclineMultiplier: $overrides['mentalDeclineMultiplier'] ?? $base->mentalDeclineMultiplier,
        );
    }

    /** @param array<string, float> $overrides */
    private static function withYouthIntake(YouthIntakeBalance $base, array $overrides): YouthIntakeBalance
    {
        return new YouthIntakeBalance(
            intakeDayOfYear: isset($overrides['intakeDayOfYear']) ? (int) round($overrides['intakeDayOfYear']) : $base->intakeDayOfYear,
            intakeAgeYears: $overrides['intakeAgeYears'] ?? $base->intakeAgeYears,
            baseIntakePerClub: $overrides['baseIntakePerClub'] ?? $base->baseIntakePerClub,
            ceilingMin: isset($overrides['ceilingMin']) ? (int) round($overrides['ceilingMin']) : $base->ceilingMin,
            ceilingMax: isset($overrides['ceilingMax']) ? (int) round($overrides['ceilingMax']) : $base->ceilingMax,
            talentSkew: isset($overrides['talentSkew']) ? (int) round($overrides['talentSkew']) : $base->talentSkew,
            startingSkillRatio: $overrides['startingSkillRatio'] ?? $base->startingSkillRatio,
            startingSkillJitter: isset($overrides['startingSkillJitter']) ? (int) round($overrides['startingSkillJitter']) : $base->startingSkillJitter,
            physicalPeakAgeMin: isset($overrides['physicalPeakAgeMin']) ? (int) round($overrides['physicalPeakAgeMin']) : $base->physicalPeakAgeMin,
            physicalPeakAgeMax: isset($overrides['physicalPeakAgeMax']) ? (int) round($overrides['physicalPeakAgeMax']) : $base->physicalPeakAgeMax,
            technicalPeakAgeMin: isset($overrides['technicalPeakAgeMin']) ? (int) round($overrides['technicalPeakAgeMin']) : $base->technicalPeakAgeMin,
            technicalPeakAgeMax: isset($overrides['technicalPeakAgeMax']) ? (int) round($overrides['technicalPeakAgeMax']) : $base->technicalPeakAgeMax,
            mentalPeakAgeMin: isset($overrides['mentalPeakAgeMin']) ? (int) round($overrides['mentalPeakAgeMin']) : $base->mentalPeakAgeMin,
            mentalPeakAgeMax: isset($overrides['mentalPeakAgeMax']) ? (int) round($overrides['mentalPeakAgeMax']) : $base->mentalPeakAgeMax,
            growthRateMin: $overrides['growthRateMin'] ?? $base->growthRateMin,
            growthRateMax: $overrides['growthRateMax'] ?? $base->growthRateMax,
            fragilityMin: $overrides['fragilityMin'] ?? $base->fragilityMin,
            fragilityMax: $overrides['fragilityMax'] ?? $base->fragilityMax,
        );
    }
}
