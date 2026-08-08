<?php

declare(strict_types=1);

namespace Flair\Harness\Web;

use Flair\Harness\Comparison\RulesetOverride;
use Flair\Kernel\Core\Ruleset\Ruleset;

/**
 * De quoi afficher un champ de calibration : son libelle, son pas, et la
 * valeur que le `Ruleset` de reference lui donne.
 *
 * ## Pourquoi ca a quitte `public/index.php`
 *
 * Cette liste y vivait, et elle a **silencieusement cesse de couvrir** cinq
 * groupes entiers de `RulesetOverride::GROUPS` - Finances, Installations,
 * Contrats, Marche des transferts, Inflation - au fil des lots qui les ont
 * ajoutes. Le formulaire rendait donc cinq `<details>` vides, sans que rien
 * ne le signale : `public/` etait hors PHPStan et hors tests.
 *
 * Une liste ecrite a la main n'est pas le probleme ; **une liste a la main que
 * rien ne confronte a sa source** l'est. `Tests\Web\CalibrationFieldsTest`
 * exige desormais une correspondance exacte, dans les deux sens, avec
 * `RulesetOverride::ALL_FIELDS`.
 *
 * ## Chaque ligne nomme son champ en clair
 *
 * Aucun acces dynamique `$balance->$field` : un nom de champ variable
 * echapperait a PHPStan et a la recherche plein texte, exactement ce que
 * `RulesetOverride` evite deja en enumerant ses champs un par un.
 */
final readonly class CalibrationFields
{
    /**
     * @param int|float $default valeur du `Ruleset` de reference
     * @param int|float|null $min borne basse du `<input>`, si le kernel en a besoin
     * @param int|float|null $max borne haute du `<input>`, si le kernel en a besoin
     */
    public function __construct(
        public string $field,
        public string $group,
        public string $label,
        public string $step,
        public int|float $default,
        public int|float|null $min = null,
        public int|float|null $max = null,
    ) {
    }

    /**
     * Les groupes deplies a l'ouverture : ceux qu'on manipule en calibrant le
     * vieillissement, le sujet historique de cet outil. Les autres sont
     * replies - quatre-vingt-deux champs deplies d'un coup ne sont pas un
     * formulaire, c'est un mur.
     *
     * @var array<string, true>
     */
    public const array OPEN_BY_DEFAULT = ['Retraite' => true, 'Développement' => true];

    /**
     * Tous les champs calibrables, groupes dans l'ordre de
     * `RulesetOverride::GROUPS`.
     *
     * @return array<string, list<self>> libelle de groupe -> champs
     */
    public static function grouped(Ruleset $baseline): array
    {
        $grouped = [];

        foreach (self::all($baseline) as $meta) {
            $grouped[$meta->group][] = $meta;
        }

        return $grouped;
    }

    /** @return list<self> */
    public static function all(Ruleset $baseline): array
    {
        $balance = $baseline->balance;
        $retirement = $balance->retirement;
        $development = $balance->playerDevelopment;
        $youth = $balance->youthIntake;
        $calendar = $balance->calendar;
        $match = $balance->match;
        $competition = $balance->competition;
        $finance = $balance->finance;
        $facilities = $balance->facilities;
        $contract = $balance->contract;
        $perception = $balance->perception;
        $transfer = $balance->transfer;
        $inflation = $balance->inflation;

        return [
            new self('retirementEligibleAge', 'Retraite', "Âge d'éligibilité (années)", '0.5', $retirement->retirementEligibleAge),
            new self('retirementAgeWeight', 'Retraite', "Poids de l'âge dans la probabilité", '0.01', $retirement->retirementAgeWeight),
            new self('retirementFragilityWeight', 'Retraite', 'Poids de la fragilité', '0.01', $retirement->retirementFragilityWeight),

            new self('growthPrimeAgeThreshold', 'Développement', "Seuil d'âge de progression max (années)", '0.5', $development->growthPrimeAgeThreshold),
            new self('growthPlateauFactor', 'Développement', 'Facteur de plateau', '0.01', $development->growthPlateauFactor),
            new self('declineRatePerYear', 'Développement', 'Pente de déclin post-pic', '0.01', $development->declineRatePerYear),
            new self('physicalDeclineMultiplier', 'Développement', 'Multiplicateur déclin physique', '0.1', $development->physicalDeclineMultiplier),
            new self('technicalDeclineMultiplier', 'Développement', 'Multiplicateur déclin technique', '0.1', $development->technicalDeclineMultiplier),
            new self('mentalDeclineMultiplier', 'Développement', 'Multiplicateur déclin mental', '0.1', $development->mentalDeclineMultiplier),

            new self('intakeDayOfYear', 'Formation des jeunes', 'Jour de promotion (tick % 365)', '1', $youth->intakeDayOfYear),
            new self('intakeAgeYears', 'Formation des jeunes', "Âge d'entrée pro (années)", '0.5', $youth->intakeAgeYears),
            // Bornes : ce champ sert de borne de boucle dans le kernel, une
            // valeur demesuree y declenche des millions de tirages RNG.
            new self('baseIntakePerClub', 'Formation des jeunes', 'Promotions moyennes par club/saison', '0.1', $youth->baseIntakePerClub, 0, 20),
            new self('ceilingMin', 'Formation des jeunes', 'Potentiel min (ceiling)', '1', $youth->ceilingMin),
            new self('ceilingMax', 'Formation des jeunes', 'Potentiel max (ceiling)', '1', $youth->ceilingMax),
            new self('talentSkew', 'Formation des jeunes', 'Asymétrie de la loi de talent (k)', '1', $youth->talentSkew, 1, 50),
            new self('startingSkillRatio', 'Formation des jeunes', 'Ratio de compétence de départ', '0.01', $youth->startingSkillRatio),
            new self('startingSkillJitter', 'Formation des jeunes', 'Bruit de compétence de départ', '1', $youth->startingSkillJitter),
            new self('physicalPeakAgeMin', 'Formation des jeunes', 'Âge de pic physique min', '1', $youth->physicalPeakAgeMin),
            new self('physicalPeakAgeMax', 'Formation des jeunes', 'Âge de pic physique max', '1', $youth->physicalPeakAgeMax),
            new self('technicalPeakAgeMin', 'Formation des jeunes', 'Âge de pic technique min', '1', $youth->technicalPeakAgeMin),
            new self('technicalPeakAgeMax', 'Formation des jeunes', 'Âge de pic technique max', '1', $youth->technicalPeakAgeMax),
            new self('mentalPeakAgeMin', 'Formation des jeunes', 'Âge de pic mental min', '1', $youth->mentalPeakAgeMin),
            new self('mentalPeakAgeMax', 'Formation des jeunes', 'Âge de pic mental max', '1', $youth->mentalPeakAgeMax),
            new self('growthRateMin', 'Formation des jeunes', 'Vitesse de progression min', '0.01', $youth->growthRateMin),
            new self('growthRateMax', 'Formation des jeunes', 'Vitesse de progression max', '0.01', $youth->growthRateMax),
            new self('fragilityMin', 'Formation des jeunes', 'Fragilité min', '0.01', $youth->fragilityMin),
            new self('fragilityMax', 'Formation des jeunes', 'Fragilité max', '0.01', $youth->fragilityMax),
            new self('basePlayerWagePerWeekCents', 'Formation des jeunes', 'Salaire hebdo. du premier contrat (centimes)', '1000', $youth->basePlayerWagePerWeekCents),

            new self('seasonStartDayOfYear', 'Calendrier', 'Jour de génération de la saison (tick % 365)', '1', $calendar->seasonStartDayOfYear),
            new self('firstMatchdayOffsetDays', 'Calendrier', "Délai avant le coup d'envoi (jours)", '1', $calendar->firstMatchdayOffsetDays),
            new self('matchdayIntervalDays', 'Calendrier', 'Espacement entre journées (jours)', '1', $calendar->matchdayIntervalDays),

            new self('homeAdvantage', 'Match', 'Avantage du terrain (exposant)', '0.05', $match->homeAdvantage),
            new self('strengthScale', 'Match', "Échelle de force (diviseur de l'écart de rating)", '1', $match->strengthScale),
            new self('lowScoreCorrelation', 'Match', 'Corrélation Dixon-Coles (ρ, scores faibles)', '0.01', $match->lowScoreCorrelation),
            new self('maxSimulatedGoals', 'Match', 'Plafond de buts simulés par équipe', '1', $match->maxSimulatedGoals),

            new self('pointsForWin', 'Classement', 'Points pour une victoire', '1', $competition->pointsForWin),
            new self('pointsForDraw', 'Classement', 'Points pour un match nul', '1', $competition->pointsForDraw),

            new self('clubIncomePerSeasonCents', 'Finances', 'Enveloppe de droits TV par club et par saison (centimes)', '1000000', $finance->clubIncomePerSeasonCents),
            // L'amortisseur de l'equilibre competitif : a 0, tous les clubs
            // touchent la meme chose quel que soit leur classement.
            new self('meritShare', 'Finances', 'Part au mérite de l\'enveloppe (0 = tout à parts égales)', '0.05', $finance->meritShare),
            new self('facilityUpkeepPerQualityPointCents', 'Finances', "Entretien par point de qualité (centimes, coût convexe)", '1000000', $finance->facilityUpkeepPerQualityPointCents),
            new self('facilityInvestmentReserveCents', 'Finances', 'Réserve gardée avant d\'investir (centimes)', '1000000', $finance->facilityInvestmentReserveCents),
            new self('facilityInvestmentMaxPerSeasonCents', 'Finances', 'Investissement maximum par saison (centimes)', '1000000', $finance->facilityInvestmentMaxPerSeasonCents),
            new self('wagePaymentDayOfWeek', 'Finances', 'Jour de paie hebdomadaire (tick % 7)', '1', $finance->wagePaymentDayOfWeek),

            new self('centsPerQualityPoint', 'Installations', 'Coût d\'un point de qualité (centimes)', '1000000', $facilities->centsPerQualityPoint),
            new self('qualityDecayPerSeason', 'Installations', 'Dégradation par saison (points)', '0.01', $facilities->qualityDecayPerSeason),

            new self('renewalDayOfYear', 'Contrats', 'Jour du mercato (tick % 365)', '1', $contract->renewalDayOfYear),
            new self('minDurationYears', 'Contrats', 'Durée minimale (années)', '1', $contract->minDurationYears),
            new self('maxDurationYears', 'Contrats', 'Durée maximale (années)', '1', $contract->maxDurationYears),
            new self('targetSquadSize', 'Contrats', "Effectif visé par club", '1', $contract->targetSquadSize),
            new self('baseWagePerWeekCents', 'Contrats', 'Salaire hebdo. à la qualité de référence (centimes)', '1000', $contract->baseWagePerWeekCents),
            new self('referenceQuality', 'Contrats', 'Qualité de référence (1-100)', '1', $contract->referenceQuality),
            new self('wageMultiplierMin', 'Contrats', 'Multiplicateur de salaire min', '0.1', $contract->wageMultiplierMin),
            new self('wageMultiplierMax', 'Contrats', 'Multiplicateur de salaire max', '0.1', $contract->wageMultiplierMax),
            new self('wageBudgetShare', 'Contrats', 'Part du revenu consacrée aux salaires', '0.05', $contract->wageBudgetShare),

            // A 0, tout observateur est exact : c'est l'interrupteur de mesure
            // du lot perception, et le champ qu'on manipule vraiment ici.
            new self('baseErrorPoints', 'Perception', "Erreur d'estimation d'un staff médian (points, 0 = omniscience)", '0.5', $perception->baseErrorPoints),
            new self('judgementReference', 'Perception', 'Jugement de référence', '1', $perception->judgementReference),
            new self('unstaffedJudgement', 'Perception', 'Jugement imputé à un club sans recruteur', '1', $perception->unstaffedJudgement),

            new self('negotiationOpeningDayOfYear', 'Marché des transferts', "Jour d'ouverture du marché (tick % 365)", '1', $transfer->negotiationOpeningDayOfYear),
            new self('maxRounds', 'Marché des transferts', 'Tours de négociation maximum', '1', $transfer->maxRounds),
            new self('openingOfferShare', 'Marché des transferts', 'Première offre, en part de la valeur', '0.05', $transfer->openingOfferShare),
            new self('buyerFlexMargin', 'Marché des transferts', 'Marge de manœuvre de l\'acheteur', '0.05', $transfer->buyerFlexMargin),
            new self('sellerConcessionShare', 'Marché des transferts', 'Concession du vendeur par tour', '0.05', $transfer->sellerConcessionShare),
            new self('buyerConcessionShare', 'Marché des transferts', 'Concession de l\'acheteur par tour', '0.05', $transfer->buyerConcessionShare),
            new self('breakBaseProbability', 'Marché des transferts', 'Probabilité de rupture de base', '0.01', $transfer->breakBaseProbability),
            new self('breakRoundGrowth', 'Marché des transferts', 'Croissance de la rupture par tour', '0.01', $transfer->breakRoundGrowth),
            new self('breakGapWeight', 'Marché des transferts', "Poids de l'écart de prix dans la rupture", '0.05', $transfer->breakGapWeight),
            new self('financialDistressWeight', 'Marché des transferts', 'Poids de la détresse financière du vendeur', '0.05', $transfer->financialDistressWeight),
            new self('financialDistressScaleCents', 'Marché des transferts', 'Échelle de détresse financière (centimes)', '1000000', $transfer->financialDistressScaleCents),
            new self('squadDepthDiscountPerSurplusPlayer', 'Marché des transferts', 'Décote par joueur excédentaire', '0.01', $transfer->squadDepthDiscountPerSurplusPlayer),
            new self('squadDepthDiscountFloor', 'Marché des transferts', 'Plancher de la décote d\'effectif', '0.05', $transfer->squadDepthDiscountFloor),
            new self('positionScarcityMin', 'Marché des transferts', 'Facteur de rareté de poste min', '0.1', $transfer->positionScarcityMin),
            new self('positionScarcityMax', 'Marché des transferts', 'Facteur de rareté de poste max', '0.1', $transfer->positionScarcityMax),
            new self('buyerWealthMin', 'Marché des transferts', "Facteur de richesse de l'acheteur min", '0.1', $transfer->buyerWealthMin),
            new self('buyerWealthMax', 'Marché des transferts', "Facteur de richesse de l'acheteur max", '0.1', $transfer->buyerWealthMax),
            new self('needWeightSpan', 'Marché des transferts', "Poids de l'urgence d'un poste (0 = bonne affaire seule)", '0.25', $transfer->needWeightSpan),
            new self('patienceReference', 'Marché des transferts', 'Patience de référence du conseil', '1', $transfer->patienceReference),
            new self('patienceFactorMin', 'Marché des transferts', 'Facteur de patience min', '0.1', $transfer->patienceFactorMin),
            new self('patienceFactorMax', 'Marché des transferts', 'Facteur de patience max', '0.1', $transfer->patienceFactorMax),
            new self('responseGraceTicks', 'Marché des transferts', 'Délai laissé à une réponse humaine (ticks)', '1', $transfer->responseGraceTicks),

            // Defaut a 0 : a 3 % le monde reste stable mais le chomage tombe de
            // ~35 a ~2, mesure et non corrige (docs/17- point 5).
            new self('marketInflationTarget', 'Inflation', 'Cible d\'inflation par saison (0 = aucune)', '0.005', $inflation->marketInflationTarget),
            new self('toleranceBand', 'Inflation', 'Bande de tolérance autour de la cible', '0.01', $inflation->toleranceBand),

            new self('developmentRate', 'Global', 'Multiplicateur global de progression/déclin', '0.05', $balance->developmentRate),
            new self('trainingRate', 'Global', "Multiplicateur global d'entraînement", '0.05', $balance->trainingRate),
        ];
    }

    /** L'attribut `min`/`max` du `<input>`, vide si le champ n'a pas de bornes. */
    public function boundsAttribute(): string
    {
        $attribute = '';

        if ($this->min !== null) {
            $attribute .= ' min="' . htmlspecialchars((string) $this->min) . '"';
        }

        if ($this->max !== null) {
            $attribute .= ' max="' . htmlspecialchars((string) $this->max) . '"';
        }

        return $attribute;
    }

    /**
     * Les groupes a afficher, dans l'ordre canonique de `RulesetOverride`.
     *
     * @return list<string>
     */
    public static function groupLabels(): array
    {
        return array_keys(RulesetOverride::GROUPS);
    }
}
