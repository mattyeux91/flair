<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Les leviers d'equilibrage du monde (docs/12-modele-du-monde.md §6, la cle
 * JSON "balance" : trainingRate, injuryBaseHazard, marketInflationTarget...).
 *
 * `developmentRate` - multiplicateur global sur la progression naturelle
 * (vieillissement, docs/14- §2), lu par Football\PlayerDevelopmentSystem.
 * `trainingRate` - multiplicateur global sur `h(entrainement)`, meme role
 * que `developmentRate` mais pour Football\TrainingSystem : calibrer sans
 * toucher au code. `retirement`/`playerDevelopment`/`youthIntake`
 * regroupent les leviers plus fins d'un seul systeme chacun (age de
 * retraite... / forme de g(age)... / loi de talent d'une promotion... /
 * generation du calendrier... / moteur de match L0... / points du
 * classement...) - une classe dediee par systeme plutot que des scalaires
 * ici ou une classe partagee, pour qu'un systeme ne depende jamais des
 * leviers d'un autre (meme principe que `reads()`/`writes()` sur `System`,
 * `13-` §2), et pour ne pas melanger les sous-domaines a mesure que
 * d'autres systemes (blessures, marche...) rejoindront `Balance`.
 * `finance` rejoint cette liste avec la Phase 2 (docs/15- §4).
 */
final readonly class Balance
{
    public function __construct(
        /** Multiplicateur global sur `annualDelta` dans PlayerDevelopmentSystem::nextValue - accelere/ralentit la progression et le declin des attributs sans changer leur forme (g(age), plafond...). */
        public float $developmentRate = 1.0,
        /** Multiplicateur global sur `Facilities::$quality` dans TrainingSystem::update - le resultat est clampe a [0.5, 2.0] par TrainingSystem, jamais par ce champ. */
        public float $trainingRate = 1.0,
        public RetirementBalance $retirement = new RetirementBalance(),
        public PlayerDevelopmentBalance $playerDevelopment = new PlayerDevelopmentBalance(),
        public YouthIntakeBalance $youthIntake = new YouthIntakeBalance(),
        public CalendarBalance $calendar = new CalendarBalance(),
        public MatchBalance $match = new MatchBalance(),
        public CompetitionBalance $competition = new CompetitionBalance(),
        public FinanceBalance $finance = new FinanceBalance(),
    ) {
    }
}
