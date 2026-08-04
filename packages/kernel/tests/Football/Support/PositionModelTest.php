<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Support;

use Flair\Kernel\Core\Ruleset\PositionBalance;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Support\AttributeCeilings;
use Flair\Kernel\Football\Support\PositionModel;
use PHPUnit\Framework\TestCase;

final class PositionModelTest extends TestCase
{
    /**
     * Les poids de chaque poste somment a 1, ce qui garde la note sur
     * l'echelle absolue 1-100 des competences (docs/12- §5). Un joueur uniforme
     * a 70 note donc 70 partout - si un poste sommait a 0.9 il noterait 63.
     */
    public function testEveryPositionWeightsSumToOne(): void
    {
        foreach (Position::cases() as $position) {
            self::assertEqualsWithDelta(
                70.0,
                PositionModel::ratingAt($position, ...self::uniformSkills(70)),
                0.000_001,
                "Les poids du poste {$position->value} ne somment pas a 1",
            );
        }
    }

    /**
     * **L'invariant central du modele.** Un joueur pleinement developpe note
     * exactement son `ceiling` au poste de son archetype : les attributs de son
     * profil sont les seuls que la formule de ce poste consomme, et ils sont
     * tous a plein plafond. C'est ce qui permet a la loi de talent de continuer
     * a vouloir dire quelque chose - "un ceiling de 90" reste "un joueur de 90
     * a son poste" apres ce lot.
     */
    public function testAFullyDevelopedPlayerRatesExactlyHisCeilingAtHisArchetype(): void
    {
        $balance = new PositionBalance();

        foreach (Position::cases() as $archetype) {
            $skills = self::skillsAt(PositionModel::ceilings(90, $archetype, [], $balance));

            self::assertEqualsWithDelta(
                90.0,
                PositionModel::ratingAt($archetype, ...$skills),
                0.000_001,
                "Un {$archetype->value} pleinement developpe ne note pas son ceiling",
            );
        }
    }

    /**
     * **Le potentiel plafonne une composition, pas chaque competence.** Un
     * milieu peut avoir un plafond de passe tres au-dessus de son `ceiling` a
     * condition de le payer ailleurs : la contrainte porte sur la somme
     * ponderee, pas sur chaque terme. C'est ce qui fait exister "excellent
     * passeur, mauvais tacleur" - sans quoi deux joueurs de meme poste et de
     * meme potentiel sont litteralement le meme joueur, et il n'y a rien a
     * scouter (docs/12- §4).
     */
    public function testTalentIsRedistributedUnderABudgetRatherThanCappedPerSkill(): void
    {
        $raw = ['passing' => 1.25, 'defending' => 0.75, 'vision' => 1.0, 'technique' => 1.0, 'positioning' => 1.0];
        $spread = PositionModel::normalizeSpread(Position::Midfielder, $raw);
        $ceilings = PositionModel::ceilings(80, Position::Midfielder, $spread, new PositionBalance());

        // Le passeur depasse son ceiling nominal, le tacleur est en dessous.
        self::assertGreaterThan(80, $ceilings->passing);
        self::assertLessThan(80, $ceilings->defending);

        // Et pourtant il note toujours exactement 80 a son poste : la
        // redistribution est un arbitrage, jamais un cadeau.
        self::assertEqualsWithDelta(80.0, PositionModel::ratingAt(Position::Midfielder, ...self::skillsAt($ceilings)), 1.0);
    }

    /**
     * Le corollaire : hors de son profil, le meme joueur est nettement moins
     * bon. C'est cette seule fonction qui penalise un joueur mal aligne -
     * aucun facteur d'affinite n'est applique par-dessus.
     */
    public function testAGoalkeeperIsBadEverywhereElse(): void
    {
        $skills = self::skillsAt(PositionModel::ceilings(90, Position::Goalkeeper, [], new PositionBalance()));

        self::assertEqualsWithDelta(90.0, PositionModel::ratingAt(Position::Goalkeeper, ...$skills), 0.000_001);
        self::assertLessThan(60.0, PositionModel::ratingAt(Position::Attacker, ...$skills));
        self::assertLessThan(60.0, PositionModel::ratingAt(Position::Defender, ...$skills));
    }

    /**
     * Le poste joue est **derive**, jamais stocke : un profil de gardien se
     * reconnait a ses competences, sans etiquette.
     */
    public function testBestPositionIsDerivedFromSkillsAlone(): void
    {
        $balance = new PositionBalance();

        foreach (Position::cases() as $archetype) {
            $skills = self::skillsAt(PositionModel::ceilings(90, $archetype, [], $balance));

            self::assertSame($archetype, PositionModel::bestPosition(...$skills));
        }
    }

    /**
     * Les plafonds : pleins sur le profil, rabaisses ailleurs, et **pleins pour
     * les trois dormants** (`stamina`/`leadership`/`discipline`), qui
     * n'appartiennent a aucun poste. Sans cette exception ils seraient mauvais
     * chez tout le monde, et le monde serait atone sur ces axes le jour ou le
     * moteur L1 les consommera.
     */
    public function testCeilingsAreFullOnProfileAndOnDormantAttributes(): void
    {
        $ceilings = PositionModel::ceilings(100, Position::Goalkeeper, [], new PositionBalance(offProfileCeilingRatio: 0.45));

        self::assertSame(100, $ceilings->reflexes, 'attribut du profil gardien');
        self::assertSame(100, $ceilings->handling, 'attribut du profil gardien');
        self::assertSame(45, $ceilings->finishing, 'hors profil');
        self::assertSame(45, $ceilings->defending, 'hors profil');
        self::assertSame(100, $ceilings->stamina, 'dormant');
        self::assertSame(100, $ceilings->leadership, 'dormant');
        self::assertSame(100, $ceilings->discipline, 'dormant');
    }

    /** L'echelle des competences est [1, 100] : un plafond rabaisse ne descend jamais a zero. */
    public function testAnOffProfileCeilingNeverFallsBelowTheScaleFloor(): void
    {
        $ceilings = PositionModel::ceilings(1, Position::Attacker, [], new PositionBalance(offProfileCeilingRatio: 0.01));

        self::assertSame(1, $ceilings->reflexes);
    }

    /**
     * Un gardien pese en defense et pas du tout en attaque, un attaquant
     * l'exact miroir - c'est ce qui fait qu'un excellent gardien compte enfin.
     */
    public function testSectorWeightsMirrorGoalkeeperAndAttacker(): void
    {
        [$gkAttack, $gkDefense] = PositionModel::sectorWeights(Position::Goalkeeper);
        [$attAttack, $attDefense] = PositionModel::sectorWeights(Position::Attacker);

        self::assertSame(0.0, $gkAttack);
        self::assertGreaterThan(0.0, $gkDefense);
        self::assertSame(0.0, $attDefense);
        self::assertGreaterThan(0.0, $attAttack);
    }

    /** @return array{0: PlayerPhysicalSkills, 1: PlayerTechnicalSkills, 2: PlayerMentalSkills} */
    private static function uniformSkills(int $value): array
    {
        return [
            new PlayerPhysicalSkills($value, $value, $value, $value),
            new PlayerTechnicalSkills($value, $value, $value, $value, $value, $value, $value),
            new PlayerMentalSkills($value, $value, $value, $value, $value),
        ];
    }

    /**
     * Un joueur dont chaque competence vaut exactement son plafond : le joueur
     * pleinement developpe que `PlayerDevelopmentSystem` produit a terme.
     *
     * @return array{0: PlayerPhysicalSkills, 1: PlayerTechnicalSkills, 2: PlayerMentalSkills}
     */
    private static function skillsAt(AttributeCeilings $c): array
    {
        return [
            new PlayerPhysicalSkills($c->pace, $c->stamina, $c->strength, $c->reflexes),
            new PlayerTechnicalSkills($c->technique, $c->passing, $c->finishing, $c->defending, $c->positioning, $c->handling, $c->distribution),
            new PlayerMentalSkills($c->vision, $c->composure, $c->leadership, $c->discipline, $c->command),
        ];
    }
}
