<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Support;

/**
 * Les noms du monde : joueurs, clubs, staff.
 *
 * Jusqu'au 2026-08-09 le monde s'appelait `Joueur 261` et `Club synthetique
 * 12`. Sans consequence tant que le seul lecteur etait l'exploitant - le nom
 * portait l'`EntityId`, ce qui etait meme pratique. Le digest de retour
 * d'absence (Phase 4 lot 3) a montre la limite : un recit ou tous les noms
 * sont des numeros n'est pas un recit.
 *
 * ## ⚠️ Zero tirage RNG, et ce n'est pas un detail de style
 *
 * L'ordre des tirages RNG et des allocations d'`EntityId` est une **donnee
 * d'architecture** (`packages/worldgen/README.md`) : tirer un nom dans le flux
 * partage decalerait tout ce qui suit et **changerait le monde entier** - les
 * memes 18 scouts qui avaient decale l'allocateur au lot perception.
 *
 * D'ou une derivation qui ne consomme rien : `Core\Support\Hash::mixAll()`,
 * pure, 32 bits masques, une valeur par jeu d'arguments. C'est exactement ce
 * que fait deja `PerceptionModel` pour la meme raison - un biais stable n'est
 * pas un tirage. Conséquence verifiable : renommer le monde laisse `EntityId`
 * et sequence d'evenements **identiques au chiffre pres**.
 *
 * La graine entre dans le hash parce que les `EntityId` ne dependent **pas**
 * d'elle : l'allocation est deterministe et identique d'un monde a l'autre.
 * Sans `worldSeed`, deux mondes de graines differentes auraient exactement les
 * memes noms.
 *
 * ## Pourquoi des constantes de classe et non le `Ruleset`
 *
 * Le `Ruleset` porte les **regles parametriques** (docs/12- §6), et un monde y
 * est epingle : y mettre ces listes reviendrait a promettre qu'un monde vivant
 * garde sa liste de prenoms pour toujours, et a faire d'un ajout de prenom une
 * migration. Aucun comportement du monde ne depend d'un nom - aucun systeme ne
 * lit `Person::$name`, aucun Fait ne le porte. C'est de la donnee cosmetique,
 * pas une regle.
 *
 * Le jour du multi-pays (Phase 6), ces tables deviendront des donnees **par
 * pays** et changeront de place. Note, pas construit.
 */
final class NameBook
{
    /**
     * Nombres premiers entre eux avec les tailles de tables, pour que deux
     * entites voisines ne partagent ni prenom ni nom : le hash est deja
     * avalanche, ces decalages ne servent qu'a tirer trois index independants
     * d'une seule valeur plutot qu'a la melanger davantage.
     */
    private const int SURNAME_SHIFT = 7;

    /**
     * Premier avec `count(PLACES) * count(CLUB_FORMS)` (71 est premier et ne
     * divise pas 320), donc les rangs successifs parcourent tous les couples
     * (lieu, forme) sans jamais en repeter un. Changer une des deux tables
     * oblige a reverifier cette primalite relative - `NameBookTest` s'en
     * charge.
     */
    private const int SLOT_STRIDE = 71;

    /** @var list<string> */
    private const array FIRST_NAMES = [
        'Adrien', 'Alexi', 'Amine', 'Anton', 'Arnaud', 'Bastien', 'Bruno', 'Cedric',
        'Cyril', 'Damien', 'Denis', 'Dorian', 'Elias', 'Emeric', 'Enzo', 'Fabien',
        'Florent', 'Gaetan', 'Gilles', 'Hugo', 'Ibrahim', 'Ilyes', 'Jerome', 'Joachim',
        'Julien', 'Karim', 'Kevin', 'Lilian', 'Loic', 'Lucas', 'Mahdi', 'Marius',
        'Mathis', 'Maxence', 'Mehdi', 'Melvin', 'Nathan', 'Nicolas', 'Noe', 'Olivier',
        'Pascal', 'Quentin', 'Rachid', 'Raphael', 'Remi', 'Romain', 'Samir', 'Sacha',
        'Simon', 'Sofiane', 'Stanislas', 'Sylvain', 'Teddy', 'Theo', 'Thibaut', 'Timothee',
        'Tristan', 'Ugo', 'Valentin', 'Vincent', 'Wilfried', 'Xavier', 'Yanis', 'Youssef',
    ];

    /** @var list<string> */
    private const array SURNAMES = [
        'Abadie', 'Amrani', 'Barbier', 'Baudry', 'Benali', 'Bertrand', 'Blondel', 'Bonnet',
        'Bourgeois', 'Cassagne', 'Chartier', 'Chevalier', 'Colin', 'Cordier', 'Dailly', 'Delaunay',
        'Deschamps', 'Dumas', 'Duval', 'Escande', 'Fabre', 'Faivre', 'Fournier', 'Gaillard',
        'Garnier', 'Gauthier', 'Gervais', 'Gonzalez', 'Guerin', 'Hamon', 'Herve', 'Jourdan',
        'Lachaud', 'Lambert', 'Langlois', 'Lecomte', 'Lefevre', 'Leroy', 'Maillard', 'Marchand',
        'Marechal', 'Masson', 'Menard', 'Mercier', 'Meunier', 'Morvan', 'Nogueira', 'Ouedraogo',
        'Pasquier', 'Perrin', 'Poirier', 'Prevost', 'Rambert', 'Renaud', 'Rioux', 'Rossi',
        'Sauvage', 'Savary', 'Tessier', 'Thibault', 'Vallet', 'Verdier', 'Vidal', 'Weber',
    ];

    /**
     * Les clubs prennent un nom de lieu et une forme. La forme d'abord ou
     * apres, selon l'usage : « Stade Aubrieres » et « Aubrieres FC » se disent
     * tous les deux, et alterner evite dix-huit clubs qui se ressemblent.
     *
     * @var list<string>
     */
    private const array PLACES = [
        'Aubrieres', 'Availles', 'Beaumont', 'Bellecombe', 'Bezanne', 'Brissac', 'Cabestan', 'Chantrigne',
        'Clairvaux', 'Combrailles', 'Coutances', 'Dorlay', 'Ecoutel', 'Entrevaux', 'Estagel', 'Fombrune',
        'Fresnaye', 'Gardanne', 'Granville', 'Hautrive', 'Jonquieres', 'Lacanau', 'Latresne', 'Maubourg',
        'Merignac', 'Montfaucon', 'Noirval', 'Oradour', 'Peyrolles', 'Pierrefonds', 'Quillan', 'Rochefort',
        'Sainval', 'Salvagnac', 'Sauveterre', 'Tarascon', 'Thiviers', 'Valreas', 'Verchamps', 'Villandry',
    ];

    /** @var list<string> */
    private const array CLUB_FORMS = [
        '%s FC', 'Stade %s', 'AS %s', 'Olympique %s', 'Racing %s', 'US %s', 'Sporting %s', 'FC %s',
    ];

    /**
     * @param int $derived une valeur deja derivee de (worldSeed, entityId) -
     *                     `Hash::mixAll()` cote worldgen, `SystemContext::stableHash()`
     *                     cote systeme. Ce parametre est un entier et non une
     *                     graine, precisement pour que cette classe ne puisse
     *                     pas tirer.
     */
    public static function personName(int $derived): string
    {
        $first = self::FIRST_NAMES[self::index($derived, count(self::FIRST_NAMES))];
        $surname = self::SURNAMES[self::index($derived >> self::SURNAME_SHIFT, count(self::SURNAMES))];

        return "{$first} {$surname}";
    }

    /**
     * ⚠️ **Un club prend son rang, pas son `EntityId`, et c'est ce qui garantit
     * l'unicite.** Deux joueurs homonymes sont normaux ; deux clubs homonymes
     * dans une meme competition sont un bug d'affichage. Or un tirage
     * independant par club en produirait : 18 noms pris au hasard parmi 320
     * combinaisons se heurtent une fois sur deux (paradoxe des anniversaires).
     *
     * D'ou un parcours de tous les couples (lieu, forme) par pas constant :
     * `SLOT_STRIDE` est premier avec `40 x 8`, donc les rangs successifs
     * visitent **320 noms distincts** avant de se repeter, et le monde n'en
     * compte que 18. Le decalage de depart vient de la graine, ce qui garde
     * deux mondes differents.
     */
    public static function clubName(int $derived, int $rank): string
    {
        $places = count(self::PLACES);
        $slot = self::index($derived + $rank * self::SLOT_STRIDE, $places * count(self::CLUB_FORMS));

        return sprintf(self::CLUB_FORMS[intdiv($slot, $places)], self::PLACES[$slot % $places]);
    }

    /**
     * Le modulo est pris sur la valeur absolue : `Hash::mixAll()` masque a 32
     * bits et rend donc toujours un positif, mais un index negatif serait une
     * `Undefined array key` silencieuse plutot qu'une erreur lisible, et cette
     * classe est appelee depuis le noyau.
     */
    private static function index(int $derived, int $size): int
    {
        return abs($derived) % $size;
    }
}
