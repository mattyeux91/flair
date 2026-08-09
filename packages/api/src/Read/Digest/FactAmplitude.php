<?php

declare(strict_types=1);

namespace Flair\Api\Read\Digest;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Football\Events\ClubInvestedInFacilities;
use Flair\Kernel\Football\Events\ContractExpired;
use Flair\Kernel\Football\Events\ContractSigned;
use Flair\Kernel\Football\Events\FixtureKickoff;
use Flair\Kernel\Football\Events\MatchPlayed;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Kernel\Football\Events\SeasonConcluded;
use Flair\Kernel\Football\Events\SeasonEnded;
use Flair\Kernel\Football\Events\SeasonStarted;
use Flair\Kernel\Football\Events\TransferAgreed;
use Flair\Kernel\Football\Events\TransferNegotiationBroken;
use Flair\Kernel\Football\Events\TransferNegotiationOpened;
use Flair\Kernel\Football\Events\YouthPlayerPromoted;

/**
 * Combien un Fait merite d'etre raconte, sur `[0, 1]`. **Le seul endroit du
 * projet qui le sait.**
 *
 * C'est le facteur `amplitude` du tri de docs/14- §9, et la `base` de la forme
 * de docs/14- §3 : le role du club et la fraicheur ne font que la nuancer,
 * jamais la creer. Un Fait a amplitude nulle n'entre jamais dans un digest,
 * quel que soit son role ou son age.
 *
 * ## Ce que « amplitude » veut dire ici, faute de mieux
 *
 * Chaque type est note a partir de **ce que son payload porte reellement**, et
 * rien d'autre. Le monde n'a pas de blessure, pas de debuts, pas de buteur :
 * l'exemple de docs/14- §9 (« Diallo a marque 7 buts ») decrit un monde qui
 * n'existe pas encore. Ce fichier est donc, en creux, l'inventaire de ce que le
 * journal du monde sait raconter aujourd'hui - et le meilleur endroit ou lire ce
 * qui lui manque.
 *
 * ## Les seuils sont volontairement severes
 *
 * Trois mois en pleine saison font ~100 Faits dont ~90 `MatchPlayed`. Un digest
 * qui les classerait tous par ecart de buts serait une feuille de resultats, ce
 * que le critere de sortie de la phase (« se comprend en trente secondes »)
 * exclut. Les matchs ordinaires sont donc **agreges dans le bandeau de synthese
 * et n'apparaissent en clair que par exception** - d'ou un `MatchPlayed` qui ne
 * decolle qu'a partir de trois buts d'ecart.
 */
final class FactAmplitude
{
    /**
     * Les Faits qui n'entrent jamais dans un digest, avec leur raison. Y
     * figurer est une **decision**, pas un oubli : `DigestCoversEveryFactTest`
     * ne fait pas la difference entre les deux, mais la relecture si.
     *
     * @var array<class-string, string>
     */
    public const array NEVER_NEWSWORTHY = [
        // **Journalise n'est pas racontable, et les deux decisions sont
        // distinctes.** Ces deux Faits meritent le journal (docs/16- §2 : un
        // club qui s'engage franchit un seuil, une rupture est irreversible) et
        // l'histoire d'un club les montre. Ils ne meritent pas le digest, qui
        // raconte ce qui **a** eu lieu : `TransferAgreed` le dira mieux.
        //
        // La contre-demande, elle, etait un troisieme cas qu'on avait range
        // ici par defaut - elle n'appartenait ni au journal ni au recit, mais
        // aux questions. Elle est sortie de l'event log le 2026-08-09 (cf.
        // `Football\Requests\TransferCounterOffered`), donc de cette table.
        TransferNegotiationOpened::class => 'etape de negociation, pas une nouvelle',
        TransferNegotiationBroken::class => 'etape de negociation, pas une nouvelle',

        // Le debut d'une saison est un fait de calendrier : il est deja dit par
        // le bandeau de synthese, qui nomme la periode couverte.
        SeasonStarted::class => 'fait de calendrier, deja porte par le bandeau',

        // Programmes par le Scheduler, jamais emis - ils n'existent pas dans
        // l'event log (cf. `History\ClubMentions::NOT_ABOUT_A_CLUB`).
        SeasonEnded::class => 'passe par le Scheduler, jamais journalise',
        FixtureKickoff::class => 'passe par le Scheduler, jamais journalise',
    ];

    /** Au-dela de cet ecart de buts, un match cesse d'etre ordinaire. */
    private const int MATCH_NOTABLE_MARGIN = 3;

    /** Une indemnite qui atteint ce montant est une nouvelle en soi. */
    private const int NOTABLE_FEE_CENTS = 2_000_000_00;

    /** Un investissement en installations qui atteint ce montant marque une saison. */
    private const int NOTABLE_INVESTMENT_CENTS = 5_000_000_00;

    /** L'age a partir duquel raccrocher est une fin de carriere remarquable. */
    private const int LONG_CAREER_AGE = 36;

    /**
     * `null` quand ce type n'a **aucune** regle ici - a distinguer de `0.0`,
     * qui veut dire « je connais ce type, et cette instance-la n'est pas une
     * nouvelle ».
     *
     * La distinction n'est pas cosmetique, c'est elle qui rend
     * `DigestCoversEveryFactTest` capable de faire son travail. Un
     * `MatchPlayed` ordinaire vaut zero et c'est voulu ; un type que personne
     * n'a note vaut zero aussi, et c'est un oubli. Sans deux valeurs
     * distinctes, un seul `float` melangeait les deux et le test ne pouvait
     * accuser que le premier - il l'a d'ailleurs fait des sa premiere
     * execution, ce qui est exactement ce qu'on lui demande.
     */
    public function of(DomainEvent $event): ?float
    {
        return match (true) {
            // Toujours le maximum, et **volontairement sans gradation par
            // rang**. Une premiere idee etait de noter la distance au milieu de
            // tableau (un titre ou une relegation valent plus qu'un ventre mou),
            // mais l'amplitude ne connait pas le club qui lit : elle note un
            // Fait, pas un point de vue. Une saison qui s'acheve est de toute
            // facon l'evenement de calendrier le plus gros de la fenetre, et
            // c'est la **phrase** qui dit le rang.
            $event instanceof SeasonConcluded => 1.0,

            $event instanceof MatchPlayed => $this->matchAmplitude($event),

            $event instanceof TransferAgreed => $this->scaled($event->agreedPriceCents, self::NOTABLE_FEE_CENTS, floor: 0.45),

            $event instanceof ClubInvestedInFacilities => $this->scaled($event->cents, self::NOTABLE_INVESTMENT_CENTS, floor: 0.15),

            // Une fin de carriere longue est une histoire, une retraite a l'age
            // ordinaire est une ligne de gestion.
            $event instanceof PlayerRetired => $event->ageYears >= self::LONG_CAREER_AGE ? 0.75 : 0.35,

            // Une signature compte, mais l'essentiel du volume est constitue de
            // **prolongations** (753 des 819 signatures du monde de reference) :
            // le tri les depart par le role, `Subject` avec un `previousClubId`
            // different pesant plus qu'une reconduction.
            $event instanceof ContractSigned => $event->previousClubId === $event->clubId ? 0.2 : 0.5,

            $event instanceof ContractExpired => 0.3,

            $event instanceof YouthPlayerPromoted => 0.3,

            default => null,
        };
    }

    /** Ce type a-t-il une regle ici, quelle que soit la valeur qu'elle rende ? */
    public function handles(DomainEvent $event): bool
    {
        return $this->of($event) !== null;
    }

    /**
     * Un match ordinaire ne vaut rien : il est deja compte dans le bilan du
     * bandeau. C'est l'ecart qui fait la nouvelle, et il faut au moins trois
     * buts pour decoller.
     */
    private function matchAmplitude(MatchPlayed $event): float
    {
        $margin = abs($event->homeGoals - $event->awayGoals);

        if ($margin < self::MATCH_NOTABLE_MARGIN) {
            return 0.0;
        }

        return min(1.0, 0.5 + 0.15 * ($margin - self::MATCH_NOTABLE_MARGIN));
    }

    /**
     * Un montant rapporte a ce qui le rend remarquable, borne a `1.0`, avec un
     * plancher : meme un petit transfert reste un transfert, la ou un match sans
     * ecart n'est rien.
     */
    private function scaled(int $cents, int $reference, float $floor): float
    {
        return max($floor, min(1.0, $cents / $reference));
    }
}
