<?php

declare(strict_types=1);

namespace Flair\Harness\Comparison;

use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\CalendarBalance;
use Flair\Kernel\Core\Ruleset\CompetitionBalance;
use Flair\Kernel\Core\Ruleset\FinanceBalance;
use Flair\Kernel\Core\Ruleset\MatchBalance;
use Flair\Kernel\Core\Ruleset\PlayerDevelopmentBalance;
use Flair\Kernel\Core\Ruleset\RetirementBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Ruleset\YouthIntakeBalance;

/**
 * Construit un Ruleset qui ne differe d'un autre que par un ensemble de
 * champs de calibration (`Balance` racine, `RetirementBalance`,
 * `PlayerDevelopmentBalance`, `YouthIntakeBalance`, `CalendarBalance`,
 * `MatchBalance`, `CompetitionBalance`) - utilise par le CLI et
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
        'basePlayerWagePerWeekCents',
    ];

    /** @var list<string> */
    public const array CALENDAR_FIELDS = [
        'seasonStartDayOfYear',
        'firstMatchdayOffsetDays',
        'matchdayIntervalDays',
    ];

    /** @var list<string> */
    public const array MATCH_FIELDS = [
        'homeAdvantage',
        'strengthScale',
        'lowScoreCorrelation',
        'maxSimulatedGoals',
    ];

    /** @var list<string> */
    public const array COMPETITION_FIELDS = [
        'pointsForWin',
        'pointsForDraw',
    ];

    /** @var list<string> */
    public const array FINANCE_FIELDS = [
        'clubIncomePerSeasonCents',
        'wagePaymentDayOfWeek',
    ];

    /** @var list<string> */
    public const array ALL_FIELDS = [
        ...self::GLOBAL_FIELDS,
        ...self::RETIREMENT_FIELDS,
        ...self::PLAYER_DEVELOPMENT_FIELDS,
        ...self::YOUTH_INTAKE_FIELDS,
        ...self::CALENDAR_FIELDS,
        ...self::MATCH_FIELDS,
        ...self::COMPETITION_FIELDS,
        ...self::FINANCE_FIELDS,
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
        'Calendrier' => self::CALENDAR_FIELDS,
        'Match' => self::MATCH_FIELDS,
        'Classement' => self::COMPETITION_FIELDS,
        'Finances' => self::FINANCE_FIELDS,
        'Global' => self::GLOBAL_FIELDS,
    ];

    /**
     * Bornes des seuls champs qui servent de borne de boucle dans le kernel
     * (`PlayerFactory::drawTalent()`, `YouthIntakeSystem::cohortSize()`) - une
     * valeur demesuree declenche des dizaines/centaines de millions de
     * tirages RNG et un timeout PHP (30s), constate en prod sur le harness
     * web. Les autres champs de `ALL_FIELDS` n'exposent aucun risque de
     * boucle et n'ont donc pas besoin de bornes artificielles ici.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const array FIELD_BOUNDS = [
        'talentSkew' => [1, 50],
        'baseIntakePerClub' => [0.0, 20.0],
    ];

    /** @param array<string, float> $overrides nom de champ (n'importe quel groupe) -> nouvelle valeur */
    public static function withFields(Ruleset $base, array $overrides): Ruleset
    {
        foreach ($overrides as $field => $value) {
            if (!\in_array($field, self::ALL_FIELDS, strict: true)) {
                throw new \InvalidArgumentException("Champ de Balance inconnu : {$field}");
            }

            $bounds = self::FIELD_BOUNDS[$field] ?? null;
            if ($bounds !== null && ($value < $bounds[0] || $value > $bounds[1])) {
                throw new \InvalidArgumentException(
                    "Valeur hors bornes pour {$field} : {$value} (attendu entre {$bounds[0]} et {$bounds[1]})"
                );
            }
        }

        $balance = $base->balance;

        return new Ruleset($base->version, new Balance(
            developmentRate: $overrides['developmentRate'] ?? $balance->developmentRate,
            trainingRate: $overrides['trainingRate'] ?? $balance->trainingRate,
            retirement: self::withRetirement($balance->retirement, $overrides),
            playerDevelopment: self::withPlayerDevelopment($balance->playerDevelopment, $overrides),
            youthIntake: self::withYouthIntake($balance->youthIntake, $overrides),
            calendar: self::withCalendar($balance->calendar, $overrides),
            match: self::withMatch($balance->match, $overrides),
            competition: self::withCompetition($balance->competition, $overrides),
            finance: self::withFinance($balance->finance, $overrides),
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
            basePlayerWagePerWeekCents: isset($overrides['basePlayerWagePerWeekCents']) ? (int) round($overrides['basePlayerWagePerWeekCents']) : $base->basePlayerWagePerWeekCents,
        );
    }

    /** @param array<string, float> $overrides */
    private static function withCalendar(CalendarBalance $base, array $overrides): CalendarBalance
    {
        return new CalendarBalance(
            seasonStartDayOfYear: isset($overrides['seasonStartDayOfYear']) ? (int) round($overrides['seasonStartDayOfYear']) : $base->seasonStartDayOfYear,
            firstMatchdayOffsetDays: isset($overrides['firstMatchdayOffsetDays']) ? (int) round($overrides['firstMatchdayOffsetDays']) : $base->firstMatchdayOffsetDays,
            matchdayIntervalDays: isset($overrides['matchdayIntervalDays']) ? (int) round($overrides['matchdayIntervalDays']) : $base->matchdayIntervalDays,
        );
    }

    /** @param array<string, float> $overrides */
    private static function withMatch(MatchBalance $base, array $overrides): MatchBalance
    {
        return new MatchBalance(
            homeAdvantage: $overrides['homeAdvantage'] ?? $base->homeAdvantage,
            strengthScale: $overrides['strengthScale'] ?? $base->strengthScale,
            lowScoreCorrelation: $overrides['lowScoreCorrelation'] ?? $base->lowScoreCorrelation,
            maxSimulatedGoals: isset($overrides['maxSimulatedGoals']) ? (int) round($overrides['maxSimulatedGoals']) : $base->maxSimulatedGoals,
        );
    }

    /** @param array<string, float> $overrides */
    private static function withCompetition(CompetitionBalance $base, array $overrides): CompetitionBalance
    {
        return new CompetitionBalance(
            pointsForWin: isset($overrides['pointsForWin']) ? (int) round($overrides['pointsForWin']) : $base->pointsForWin,
            pointsForDraw: isset($overrides['pointsForDraw']) ? (int) round($overrides['pointsForDraw']) : $base->pointsForDraw,
        );
    }

    /** @param array<string, float> $overrides */
    private static function withFinance(FinanceBalance $base, array $overrides): FinanceBalance
    {
        return new FinanceBalance(
            clubIncomePerSeasonCents: isset($overrides['clubIncomePerSeasonCents']) ? (int) round($overrides['clubIncomePerSeasonCents']) : $base->clubIncomePerSeasonCents,
            wagePaymentDayOfWeek: isset($overrides['wagePaymentDayOfWeek']) ? (int) round($overrides['wagePaymentDayOfWeek']) : $base->wagePaymentDayOfWeek,
        );
    }
}
