@extends('layout')
@section('title', $world->id)

@php use Flair\Api\Format\Money; @endphp

@section('content')
    <p class="crumbs"><a href="{{ route('worlds.index') }}">← les mondes</a></p>

    <h1>{{ $world->id }}</h1>
    <ul class="facts">
        <li>tick <b>{{ number_format($world->tick, 0, ',', "\u{202f}") }}</b></li>
        <li>saison <b>{{ $world->season }}</b>, jour <b>{{ $world->dayOfYear }}</b></li>
        <li>graine <b>{{ $world->seed }}</b></li>
        <li>noyau <b>{{ $world->kernelVersion }}</b> / ruleset <b>{{ $world->rulesetVersion }}</b></li>
    </ul>

    <h2>Le monde</h2>
    <div class="tiles">
        <div class="tile"><span>Joueurs</span><strong>{{ $world->playerCount }}</strong></div>
        <div class="tile"><span>Sous contrat</span><strong>{{ $world->contractedPlayerCount }}</strong></div>
        <div class="tile"><span>Sans club</span><strong>{{ $world->playerCount - $world->contractedPlayerCount }}</strong></div>
        <div class="tile"><span>En circulation</span><strong>{{ Money::roundEuros($world->moneyInCirculationCents()) }}</strong></div>
        <div class="tile"><span>Indice d'inflation</span><strong>{{ number_format($world->inflationIndex, 3, ',', ' ') }}</strong></div>
        <div class="tile"><span>Cible annuelle</span><strong>{{ number_format($world->inflationAnnualRate * 100, 1, ',', ' ') }}&nbsp;%</strong></div>
    </div>
    <p class="note">
        « En circulation » est la difference des injections et des puits du singleton <code>MonetaryMass</code> — l'invariant que
        <code>MonetaryConservationTest</code> surveille. C'est la premiere fois qu'on peut le regarder sans lancer un run.
        Un solde negatif n'est pas un bug : les clubs peuvent etre collectivement debiteurs.
    </p>

    <h2>{{ $world->competitionName ?? 'Classement' }}</h2>
    <div class="scroll">
        @if ($world->standings === [])
            <p class="empty">Aucun classement : la premiere saison n'est generee qu'au tick 365, et ce monde en est au {{ $world->tick }}.</p>
        @else
            <table>
                <thead>
                <tr>
                    <th class="n">#</th>
                    <th>club</th>
                    <th class="n">j</th>
                    <th class="n">g</th>
                    <th class="n">n</th>
                    <th class="n">p</th>
                    <th class="n">bp</th>
                    <th class="n">bc</th>
                    <th class="n">diff</th>
                    <th class="n">pts</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($world->standings as $row)
                    <tr>
                        <td class="n">{{ $row->rank }}</td>
                        <td><a href="{{ route('clubs.show', [$world->id, $row->clubId]) }}">{{ $row->clubName }}</a></td>
                        <td class="n">{{ $row->played }}</td>
                        <td class="n">{{ $row->won }}</td>
                        <td class="n">{{ $row->drawn }}</td>
                        <td class="n">{{ $row->lost }}</td>
                        <td class="n">{{ $row->goalsFor }}</td>
                        <td class="n">{{ $row->goalsAgainst }}</td>
                        <td class="n">{{ $row->goalDifference() > 0 ? '+' : '' }}{{ $row->goalDifference() }}</td>
                        <td class="n"><b>{{ $row->points }}</b></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <h2>Les clubs</h2>
    <div class="scroll">
        <table>
            <thead>
            <tr>
                <th class="n">id</th>
                <th>club</th>
                <th class="n">effectif</th>
                <th class="n">solde</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($world->clubs as $club)
                <tr>
                    <td class="n">{{ $club->id }}</td>
                    <td><a href="{{ route('clubs.show', [$world->id, $club->id]) }}">{{ $club->name }}</a></td>
                    <td class="n">{{ $club->squadSize }}</td>
                    <td class="n">{{ Money::roundEuros($club->balanceCents) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
