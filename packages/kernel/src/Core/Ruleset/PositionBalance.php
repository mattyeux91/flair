<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Les leviers des postes (docs/12-modele-du-monde.md §5, docs/14- §1), lus par
 * `Football\Support\PositionModel` a qui ce groupe est passe en entier - meme
 * forme que `ContractBalance` pour `Football\Support\WageModel`.
 *
 * ## Ce qui est ici, et ce qui ne l'est pas
 *
 * La **matrice de contribution** (quelles competences font un gardien, ce
 * qu'un defenseur apporte a l'attaque) n'est **pas** dans ce groupe : elle
 * vit dans `PositionModel`. Ce n'est pas un reglage, c'est la definition de ce
 * qu'est un poste - la changer ne calibre pas le monde, elle redefinit le
 * football. Meme coupure que le moteur de match, dont la formule de
 * Dixon-Coles est du code et dont seuls les parametres (`lowScoreCorrelation`,
 * `homeAdvantage`) sont dans `MatchBalance`.
 *
 * Restent ici les trois choses qu'un auteur de `Ruleset` changerait
 * plausiblement : la formation, la severite hors profil, et la composition de
 * la population generee.
 *
 * Ce groupe ne nomme aucun type du domaine football : `Core` n'importe jamais
 * `Football` (docs/11- §7), d'ou des champs nommes poste par poste plutot
 * qu'un tableau indexe par l'enum `Position`.
 */
final readonly class PositionBalance
{
    public function __construct(
        /**
         * La formation, en nombre de places par poste. Somme attendue : onze.
         *
         * Un 4-4-2 unique, et c'est **le point de branchement des tactiques**
         * plus tard (choix de formation, consignes, roles) - pas un choix
         * definitif. Tant qu'aucun club ne choisit sa formation, en offrir
         * plusieurs serait un levier que personne n'actionne.
         *
         * C'est aussi ce qui fixe la **rarete relative** des postes, dont le
         * marche des transferts fera son `rarete_poste` (docs/14- §5) : un
         * gardien pour quatre defenseurs, ce n'est pas le meme marche.
         */
        public int $goalkeeperSlots = 1,
        public int $defenderSlots = 4,
        public int $midfielderSlots = 4,
        public int $attackerSlots = 2,
        /**
         * La fraction du `ceiling` qu'un joueur peut atteindre sur un attribut
         * **hors du profil de son archetype** : le plafond de finition d'un
         * gardien vaut `ceiling x 0.45`.
         *
         * C'est le seul levier qui empeche les profils de se dissoudre, et il
         * porte sur le **plafond**, pas sur la competence de depart. Sans lui,
         * `Football\PlayerDevelopmentSystem` ramene tout attribut vers un
         * plafond unique - et d'autant plus vite qu'il en est loin, puisque la
         * progression est proportionnelle a l'ecart. Un gardien ne avec une
         * mauvaise finition verrait donc sa finition progresser **plus vite
         * que tout le reste** et rattraper le peloton. Mesure avant ce lot :
         * l'ecart-type des seize attributs **a l'interieur d'un meme joueur**
         * a l'age du pic valait 4,0 points en mediane - du bruit de marche
         * aleatoire, aucun profil.
         *
         * Les attributs qu'**aucun** poste ne consomme (`stamina`,
         * `leadership`, `discipline` - dormants jusqu'au moteur L1, docs/14-
         * §1) ne sont pas rabaisses : ils resteraient mauvais pour tout le
         * monde, et le jour ou un systeme les lira le monde entier serait
         * atone. Ils gardent le plafond plein.
         */
        public float $offProfileCeilingRatio = 0.45,
        /**
         * L'amplitude de la repartition du talent **a l'interieur** du profil
         * d'un poste : chaque attribut du profil recoit un facteur tire dans
         * `[1 - profileSpread, 1 + profileSpread]`, puis l'ensemble est
         * normalise pour que la note du joueur a son poste vaille toujours
         * exactement son `ceiling` (`PositionModel::normalizeSpread()`).
         *
         * C'est ce levier, et lui seul, qui fait exister "excellent passeur,
         * mauvais tacleur". A zero, tous les joueurs d'un meme poste et d'un
         * meme potentiel sont litteralement identiques - c'etait le cas avant
         * ce reglage, mesure a 1,5 point d'ecart-type intra-profil, et un monde
         * ou connaitre le poste et le niveau suffit a tout reconstituer n'a
         * rien a faire scouter (docs/12- §4).
         *
         * 0,25 donne des ecarts d'environ 20 points entre le meilleur et le
         * pire attribut d'un joueur de bon niveau - l'ordre de grandeur reel
         * entre la passe et le tacle d'un meneur de jeu. Monter beaucoup plus
         * haut ferait mordre le plafond de 100 de l'echelle sur les gros
         * potentiels, et "100" cesserait de vouloir dire ce que docs/12- §5
         * dit qu'il veut dire.
         */
        public float $profileSpread = 0.25,
        /**
         * La composition de la population generee, en part d'archetype tiree
         * par `Football\Generation\PlayerFactory`. Somme attendue : 1.
         *
         * Calee sur les besoins de la formation **avec marge**, pas sur ses
         * proportions exactes (1/4/4/2, soit 9/36/36/18 %) : un club porte un
         * effectif d'environ dix-sept joueurs pour onze places, et il lui faut
         * un remplacant a chaque poste. La part de gardiens est celle qui
         * compte : trop basse, des clubs se retrouvent sans gardien du tout et
         * alignent un attaquant dans les buts.
         */
        public float $goalkeeperShare = 0.10,
        public float $defenderShare = 0.33,
        public float $midfielderShare = 0.35,
        public float $attackerShare = 0.22,
    ) {
    }
}
