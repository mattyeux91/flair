<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Regression;

use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Harness\Simulation\StepRunner;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\SquadMembership;
use PHPUnit\Framework\TestCase;

/**
 * Mecanise l'invariant de docs/12-modele-du-monde.md §1 : "pas de joueur avec
 * deux contrats actifs, pas d'effectif > taille max". L'ECS disperse les
 * invariants - rien n'empeche mecaniquement un systeme d'ecrire n'importe quoi
 * dans `Contract`, et la contre-mesure prevue par le document est justement une
 * verification en fin de tick.
 *
 * Ce que ce test verifie reellement, sur un monde qui a vraiment tourne :
 *
 * 1. `Contract` et `SquadMembership` designent **toujours** le meme club.
 *    C'est le seul couplage que `Football\SquadSystem` doit maintenir, et
 *    l'erreur la plus facile a commettre en ajoutant un chemin d'ecriture -
 *    un joueur paye par un club et aligne par un autre ne casserait aucun
 *    test metier existant, mais fausserait a la fois la masse salariale et la
 *    force des equipes.
 * 2. Un joueur sous contrat porte un `SquadMembership`, et reciproquement.
 *    L'ECS ne connait que des composants : c'est cette paire, et rien d'autre,
 *    qui constitue la relation d'emploi.
 * 3. Un retraite n'a garde ni l'un ni l'autre - la limite que le lot des
 *    contrats a corrigee en donnant la propriete des deux composants a un
 *    seul systeme.
 *
 * Vingt saisons, meme fenetre que `MonetaryConservationTest` : assez pour que
 * des milliers de renouvellements, de departs et de retraites se soient
 * produits, y compris les cas limites (club sature, budget epuise, joueur
 * repris le jour meme de sa liberation).
 */
final class SquadIntegrityTest extends TestCase
{
    public function testEmploymentStaysConsistentOverTwentySeasons(): void
    {
        $spec = new PopulationSpec(playerCount: 500, years: 20, seed: 42, clubCount: 18);
        $world = new WorldState();
        (new PopulationFactory())->populate($world, $spec);

        (new StepRunner($world, new Ruleset('ci'), $spec->seed))->advance($spec->years * 365);

        $contracts = $world->components(Contract::class);
        $memberships = $world->components(SquadMembership::class);
        $skills = $world->components(PlayerPhysicalSkills::class);

        $contracted = 0;

        foreach ($contracts->entities() as $playerId) {
            $contract = $contracts->get($playerId);

            if ($contract === null) {
                continue;
            }

            $contracted++;
            $membership = $memberships->get($playerId);

            self::assertNotNull($membership, "le joueur {$playerId} est sous contrat sans appartenir a un effectif");
            self::assertSame($contract->clubId, $membership->clubId, "le joueur {$playerId} est paye par un club et aligne par un autre");
            self::assertNotNull($skills->get($playerId), "le joueur {$playerId} est sous contrat alors qu'il n'est plus un joueur");
        }

        foreach ($memberships->entities() as $playerId) {
            if ($memberships->get($playerId) === null) {
                continue;
            }

            self::assertNotNull($contracts->get($playerId), "le joueur {$playerId} appartient a un effectif sans contrat");
        }

        // Garde-fou : sans lui, un monde ou plus personne n'est employe
        // passerait ce test sans rien prouver.
        self::assertGreaterThan(100, $contracted, 'le monde devrait encore employer des joueurs apres vingt saisons');
    }
}
