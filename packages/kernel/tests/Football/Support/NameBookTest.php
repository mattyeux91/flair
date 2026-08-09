<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Support;

use Flair\Kernel\Core\Support\Hash;
use Flair\Kernel\Football\Support\NameBook;
use PHPUnit\Framework\TestCase;

/**
 * Deux proprietes portent tout le lot, et une seule est visible a l'oeil.
 *
 * La visible : deux clubs d'un meme monde ne portent jamais le meme nom.
 * L'invisible : nommer ne **tire** rien - ce qui est verifie ailleurs, par
 * empreinte (`EntityId` et sequence d'evenements identiques avant/apres le
 * lot). Ici on tient ce qui se tient depuis une seule classe.
 */
final class NameBookTest extends TestCase
{
    private const int PLACES = 40;
    private const int FORMS = 8;
    private const int STRIDE = 71;

    public function testTwoClubsOfTheSameWorldNeverShareAName(): void
    {
        foreach ([1, 42, 7, 123_456] as $seed) {
            $derived = Hash::mixAll($seed);
            $names = [];

            for ($rank = 1; $rank <= 18; $rank++) {
                $names[] = NameBook::clubName($derived, $rank);
            }

            self::assertSame(
                $names,
                array_values(array_unique($names)),
                "Doublon de nom de club sur la graine {$seed}.",
            );
        }
    }

    /**
     * La borne exacte du parcours : `SLOT_STRIDE` etant premier avec
     * `PLACES x FORMS`, les rangs successifs visitent **tous** les couples
     * avant d'en repeter un. Un monde de 320 clubs reste donc sans doublon -
     * bien au-dela des 18 d'aujourd'hui, ce qui est le point : la propriete ne
     * tient pas par chance de petit echantillon.
     */
    public function testTheWalkVisitsEveryPlaceAndFormBeforeRepeating(): void
    {
        $total = self::PLACES * self::FORMS;
        $derived = Hash::mixAll(42);
        $names = [];

        for ($rank = 1; $rank <= $total; $rank++) {
            $names[] = NameBook::clubName($derived, $rank);
        }

        self::assertCount($total, array_unique($names));
        self::assertSame(1, self::gcd(self::STRIDE, $total), 'Le pas doit rester premier avec le nombre de couples.');
    }

    public function testTheSeedChangesTheNamesOfAWorld(): void
    {
        $first = NameBook::clubName(Hash::mixAll(1), rank: 1);
        $second = NameBook::clubName(Hash::mixAll(2), rank: 1);

        self::assertNotSame($first, $second);
    }

    /**
     * Un nom est une **fonction** de son argument : deux appels rendent la
     * meme chose, toujours. C'est ce qui autorise a ne rien stocker de plus que
     * `Person::$name` et a relire un monde a l'identique.
     */
    public function testANameIsAPureFunctionOfItsArgument(): void
    {
        $derived = Hash::mixAll(42, 261);

        self::assertSame(NameBook::personName($derived), NameBook::personName($derived));
        self::assertNotSame(NameBook::personName($derived), NameBook::personName(Hash::mixAll(42, 262)));
    }

    /**
     * Le monde ne doit pas etre peuple d'homonymes : sur 500 joueurs, deux
     * tables de 64 donnent 4 096 combinaisons, et le paradoxe des
     * anniversaires en fait attendre une trentaine de collisions - acceptable
     * pour des joueurs (le vrai football en a), pas au point que la moitie de
     * la population porte dix noms.
     */
    public function testAPopulationOfPlayersIsMostlyDistinct(): void
    {
        $names = [];

        for ($entity = 20; $entity < 520; $entity++) {
            $names[] = NameBook::personName(Hash::mixAll(42, $entity));
        }

        self::assertGreaterThan(450, count(array_unique($names)));
    }

    public function testNoNameIsEmptyOrCarriesItsIdentifier(): void
    {
        for ($entity = 1; $entity <= 200; $entity++) {
            $person = NameBook::personName(Hash::mixAll(42, $entity));
            $club = NameBook::clubName(Hash::mixAll(42), $entity);

            self::assertMatchesRegularExpression('/^\S+ \S+$/', $person);
            self::assertNotSame('', $club);
            self::assertDoesNotMatchRegularExpression('/\d/', $person . $club, 'Un nom ne porte plus de numero.');
        }
    }

    private static function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : self::gcd($b, $a % $b);
    }
}
