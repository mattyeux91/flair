<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Leviers d'equilibrage du moteur de match L0 (docs/14- §1, Dixon-Coles),
 * lus uniquement par Football\MatchSystem (via Football\Generation\PoissonMatchEngine).
 *
 * ## `exp()` : premiere fonction transcendante du noyau
 *
 * `docs/14-` §1 specifie `λ = exp(attaque − defense + avantage)` au pied de
 * la lettre. C'est une decision consciente, pas une entorse a
 * `YouthIntakeBalance::$talentSkew`, qui evite `exp`/`log`/`sqrt`/`cos` pour
 * la loi de talent : `docs/13-` §4.8 precise que le noyau n'a besoin que
 * d'une reproductibilite **meme machine, meme version de PHP** - pas
 * cross-plateforme, puisque seul le serveur execute le noyau (pas de
 * lockstep multijoueur). Sur une meme machine, `exp()` de la libm rend
 * toujours le meme resultat pour les memes entrees : le determinisme
 * (docs/11- §1) n'est donc pas menace ici. Le risque ecarte par
 * `talentSkew` etait un risque de portabilite cross-plateforme/cross-libc,
 * pas un risque de determinisme local - et ce risque ne s'applique que si
 * un monde change un jour de machine hote sans figer sa version de PHP/libc,
 * ce qui n'est pas le cas aujourd'hui.
 *
 * Si la Phase 1 introduit une comparaison de hash entre machines
 * differentes (harness sur CI, monde en prod sur un autre hote), ce
 * raisonnement devra etre revisite - voir aussi le commentaire equivalent
 * sur `YouthIntakeBalance::$talentSkew`.
 */
final readonly class MatchBalance
{
    public function __construct(
        /** Ajoute directement dans l'exposant du taux de buts attendus de l'equipe a domicile - jamais applique a l'equipe visiteuse. */
        public float $homeAdvantage = 0.25,
        /** Diviseur de l'ecart de rating attaque/defense (echelle de competences 1-100) avant qu'il n'entre dans l'exposant - plus grand, moins un ecart de niveau pese sur le score attendu. */
        public float $strengthScale = 20.0,
        /** Le `ρ` de la correction de Dixon-Coles sur les scores faibles (0-0, 1-0, 0-1, 1-1) - typiquement negatif, corrige la sous-estimation des matchs nuls serres par un Poisson independant. */
        public float $lowScoreCorrelation = -0.13,
        /** Borne superieure des buts simules par equipe dans la grille de tirage - au-dela, la masse de probabilite est negligeable pour des taux de buts realistes. */
        public int $maxSimulatedGoals = 10,
    ) {
    }
}
