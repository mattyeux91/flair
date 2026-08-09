@extends('layout')
@section('title', $history->clubName . ' — histoire')

@php use Flair\Api\Format\Money; @endphp

@section('content')
    <p class="crumbs">
        <a href="{{ route('worlds.index') }}">les mondes</a> ›
        <a href="{{ route('worlds.show', $history->worldId) }}">{{ $history->worldId }}</a> ›
        <a href="{{ route('clubs.show', [$history->worldId, $history->clubId]) }}">{{ $history->clubName }}</a>
    </p>

    <h1>{{ $history->clubName }} — {{ count($history->seasons) }} saison{{ count($history->seasons) > 1 ? 's' : '' }}</h1>
    <ul class="facts">
        <li>tick <b>{{ number_format($history->tick, 0, ',', "\u{202f}") }}</b></li>
        <li><b>{{ number_format($history->factsKept, 0, ',', "\u{202f}") }}</b> Faits le concernent, sur {{ number_format($history->factsRead, 0, ',', "\u{202f}") }} dans le monde</li>
    </ul>

    @if ($history->seasons === [])
        <div class="scroll"><p class="empty">Ce club n'a pas encore d'histoire : aucun Fait ne le nomme. La première saison n'est générée qu'au tick&nbsp;365.</p></div>
    @endif

    @foreach ($history->seasons as $s)
        <h2>
            Saison {{ $s->season }}
            @if ($s->rank !== null)
                — <b style="color:var(--ink)">{{ $s->rank }}<sup>e</sup></b> sur {{ $s->clubsRanked }}
            @elseif ($s->hasPlayed())
                — en cours
            @endif
        </h2>

        <div class="tiles">
            @if ($s->hasPlayed())
                <div class="tile"><span>Bilan</span><strong>{{ $s->won }}&nbsp;/&nbsp;{{ $s->drawn }}&nbsp;/&nbsp;{{ $s->lost }}</strong></div>
                <div class="tile"><span>Points</span><strong>{{ $s->points }}</strong></div>
                <div class="tile"><span>Buts</span><strong>{{ $s->goalsFor }}:{{ $s->goalsAgainst }} <span style="font-size:.75rem;text-transform:none;letter-spacing:0">({{ $s->goalDifference() > 0 ? '+' : '' }}{{ $s->goalDifference() }})</span></strong></div>
            @endif
            <div class="tile"><span>Arrivées</span><strong>{{ count($s->arrivals) }}</strong></div>
            <div class="tile"><span>Départs</span><strong>{{ count($s->departures) }}</strong></div>
            <div class="tile"><span>Prolongations</span><strong>{{ count($s->renewals) }}</strong></div>
            <div class="tile"><span>Jeunes promus</span><strong>{{ count($s->youthPromoted) }}</strong></div>
            <div class="tile"><span>Retraites</span><strong>{{ count($s->retirements) }}</strong></div>
            @if ($s->transferSpendCents > 0 || $s->transferIncomeCents > 0)
                <div class="tile"><span>Indemnités payées</span><strong>{{ Money::roundEuros($s->transferSpendCents) }}</strong></div>
                <div class="tile"><span>Indemnités reçues</span><strong>{{ Money::roundEuros($s->transferIncomeCents) }}</strong></div>
            @endif
            @if ($s->facilitiesInvestedCents > 0)
                <div class="tile"><span>Installations</span><strong>{{ Money::roundEuros($s->facilitiesInvestedCents) }}</strong></div>
            @endif
        </div>

        @foreach ([
            ['Arrivées', $s->arrivals, 'de'],
            ['Départs', $s->departures, 'vers'],
            ['Prolongations', $s->renewals, null],
            ['Jeunes promus', $s->youthPromoted, null],
        ] as [$label, $movements, $preposition])
            @if ($movements !== [])
                <div class="scroll" style="margin-top:.75rem">
                    <table>
                        <thead>
                        <tr>
                            <th>{{ $label }}</th>
                            @if ($preposition !== null)<th>{{ $preposition }}</th>@endif
                            <th class="n">indemnité</th>
                            <th class="n">salaire / sem.</th>
                            <th class="n">jour</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($movements as $m)
                            <tr>
                                <td>{{ $m->playerName }}</td>
                                @if ($preposition !== null)
                                    <td>{{ $m->otherClubName ?? '—' }}</td>
                                @endif
                                <td class="n">{{ $m->feeCents === null ? '—' : Money::roundEuros($m->feeCents) }}</td>
                                <td class="n">{{ $m->wagePerWeekCents === null ? '—' : Money::euros($m->wagePerWeekCents) }}</td>
                                <td class="n">{{ number_format($m->tick, 0, ',', "\u{202f}") }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endforeach

        @if ($s->retirements !== [])
            {{-- Colonnes a part : une retraite n'a ni indemnite ni club d'en face, et l'age y est l'information principale. --}}
            <div class="scroll" style="margin-top:.75rem">
                <table>
                    <thead>
                    <tr>
                        <th>Retraites</th>
                        <th class="n">âge</th>
                        <th class="n">jour</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($s->retirements as $m)
                        <tr>
                            <td>{{ $m->playerName }}</td>
                            <td class="n">{{ $m->ageYears ?? '—' }}</td>
                            <td class="n">{{ number_format($m->tick, 0, ',', "\u{202f}") }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <details style="margin-top:.75rem">
            <summary class="note" style="cursor:pointer">{{ count($s->log) }} Faits bruts — ticks {{ $s->fromTick }} à {{ $s->toTick }}</summary>
            <div class="scroll" style="margin-top:.5rem">
                <table>
                    <thead>
                    <tr>
                        <th class="n">tick</th>
                        <th class="n">#</th>
                        <th>type</th>
                        <th>rôle</th>
                        <th>données</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($s->log as $fact)
                        <tr>
                            <td class="n">{{ $fact->tick }}</td>
                            <td class="n">{{ $fact->seq }}</td>
                            <td><code>{{ $fact->type }}</code></td>
                            <td>{{ $fact->role ?? '—' }}</td>
                            <td><code>{{ json_encode($fact->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endforeach

    <p class="note">
        Une saison <b>conclue</b> est <b>citée</b> : rang, points, bilan et buts viennent tous de
        <code>SeasonConcluded.finalTable</code>, le procès-verbal que le monde a publié — le rang y est encodé par la position.
        Une saison <b>en cours</b> n'a pas encore ce Fait, donc son bilan est <b>compté</b> sur les matchs et ses points appliqués
        depuis les barèmes du <code>Ruleset</code> du monde. Un test exige que les deux chemins coïncident.
    </p>
    <p class="note">
        Une <b>prolongation</b> n'est ni une arrivée ni un départ : c'est un <code>ContractSigned</code> dont le club précédent est
        le même. Sur le monde de référence à dix ans, c'est le cas de <b>753 signatures sur 819</b> — les compter comme des arrivées
        ferait dire à cette page qu'un club recrute quand il ne fait que garder ses joueurs.
    </p>
@endsection
