@extends('layout')
@section('title', $digest->clubName . ' — digest')

@php use Flair\Api\Format\Money; @endphp

@section('content')
    <p class="crumbs">
        <a href="{{ route('worlds.index') }}">les mondes</a> ›
        <a href="{{ route('worlds.show', $digest->worldId) }}">{{ $digest->worldId }}</a> ›
        <a href="{{ route('clubs.show', [$digest->worldId, $digest->clubId]) }}">{{ $digest->clubName }}</a>
    </p>

    <h1>{{ $digest->clubName }} — les {{ $digest->days }} derniers jours</h1>
    <ul class="facts">
        <li>du tick <b>{{ number_format($digest->fromTick, 0, ',', "\u{202f}") }}</b> au <b>{{ number_format($digest->toTick, 0, ',', "\u{202f}") }}</b></li>
        <li><b>{{ number_format($digest->factsAboutClub, 0, ',', "\u{202f}") }}</b> Faits le concernent, sur {{ number_format($digest->factsRead, 0, ',', "\u{202f}") }} dans la fenêtre</li>
    </ul>

    @if ($digest->summary->isEmpty() && $digest->highlights === [])
        <div class="scroll">
            <p class="empty">
                Rien n'est arrivé à ce club sur cette fenêtre. Ce n'est pas forcément une anomalie :
                l'intersaison (jours&nbsp;270 à&nbsp;365) ne produit presque aucun Fait. Essayer
                <a href="?days=270">une fenêtre plus large</a>.
            </p>
        </div>
    @endif

    {{-- Le bandeau : un bilan, pas une sélection. C'est ici que vivent les
         ~90 matchs ordinaires d'une fenêtre de trois mois en pleine saison. --}}
    <h2>Le bilan</h2>
    <div class="tiles">
        @if ($digest->summary->played > 0)
            <div class="tile"><span>Matchs</span><strong>{{ $digest->summary->played }}</strong></div>
            <div class="tile"><span>Bilan</span><strong>{{ $digest->summary->won }}&nbsp;/&nbsp;{{ $digest->summary->drawn }}&nbsp;/&nbsp;{{ $digest->summary->lost }}</strong></div>
            <div class="tile"><span>Buts</span><strong>{{ $digest->summary->goalsFor }}:{{ $digest->summary->goalsAgainst }}</strong></div>
        @endif
        <div class="tile"><span>Arrivées</span><strong>{{ $digest->summary->arrivals }}</strong></div>
        <div class="tile"><span>Départs</span><strong>{{ $digest->summary->departures }}</strong></div>
        <div class="tile"><span>Prolongations</span><strong>{{ $digest->summary->renewals }}</strong></div>
        <div class="tile"><span>Jeunes promus</span><strong>{{ $digest->summary->youthPromoted }}</strong></div>
        <div class="tile"><span>Retraites</span><strong>{{ $digest->summary->retirements }}</strong></div>
        @if ($digest->summary->transferSpendCents > 0 || $digest->summary->transferIncomeCents > 0)
            <div class="tile"><span>Mercato net</span><strong>{{ Money::roundEuros($digest->summary->netTransferCents()) }}</strong></div>
        @endif
        @if ($digest->summary->facilitiesInvestedCents > 0)
            <div class="tile"><span>Installations</span><strong>{{ Money::roundEuros($digest->summary->facilitiesInvestedCents) }}</strong></div>
        @endif
    </div>

    <h2>Ce qu'il faut retenir</h2>
    @if ($digest->highlights === [])
        <div class="scroll"><p class="empty">Aucun fait marquant : tout ce qui s'est passé tient dans le bilan ci-dessus.</p></div>
    @else
        <ul class="facts digest">
            @foreach ($digest->highlights as $entry)
                <li>
                    <b>j+{{ $entry->tick - $digest->fromTick }}</b> — {{ $entry->sentence }}
                </li>
            @endforeach
        </ul>
    @endif

    <h2>Ailleurs dans le monde</h2>
    @if ($digest->world === [])
        <div class="scroll"><p class="empty">Rien de marquant ailleurs sur cette fenêtre.</p></div>
    @else
        <ul class="facts digest">
            @foreach ($digest->world as $entry)
                <li>
                    <b>j+{{ $entry->tick - $digest->fromTick }}</b> — {{ $entry->sentence }}
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Pas un détail de mise au point : `docs/14-` §9 fait du digest le
         contrôle qualité des seuils d'émission, et un digest pauvre ne se
         diagnostique qu'en voyant de quoi la fenêtre était faite. --}}
    <h2>De quoi la fenêtre était faite</h2>
    @if ($digest->factsByType === [])
        <div class="scroll"><p class="empty">Aucun Fait sur la fenêtre.</p></div>
    @else
        <div class="scroll">
            <table>
                <thead><tr><th>type de Fait</th><th class="num">nombre</th><th class="num">part</th></tr></thead>
                <tbody>
                    @foreach ($digest->factsByType as $type => $count)
                        <tr>
                            <td>{{ $type }}</td>
                            <td class="num">{{ $count }}</td>
                            <td class="num">{{ number_format(100 * $count / max(1, $digest->factsRead), 1, ',', "\u{202f}") }}&nbsp;%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
