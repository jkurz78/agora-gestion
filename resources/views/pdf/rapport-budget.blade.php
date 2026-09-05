@extends('pdf.rapport-layout')

@section('content')
    {{--
        Reproduit App\Livewire\BudgetTable::render() / budget-table.blade.php :
        mêmes groupes de comptes, mêmes enveloppes, mêmes ventilations, même
        réalisé. Les deux drapeaux $avecRealise / $avecVentilations ne pilotent
        QUE l'affichage — jamais une source de données différente — et aucune
        colonne n'est inventée (en particulier pas de N-1, que l'écran n'a
        jamais affiché).

        Aucun écart n'est jamais écrit à la main : toujours
        App\Support\ComparaisonBudgetaire::ecart().
    --}}
    @php
        $colCount = $avecRealise ? 4 : 2;
        $sectionTotaux = [];
    @endphp

    @foreach ([
        ['groupes' => $depenseGroupes, 'label' => 'DÉPENSES'],
        ['groupes' => $recetteGroupes, 'label' => 'RECETTES'],
    ] as $section)
        @php
            $totalPrevu = 0.0;
            $totalRealise = 0.0;
        @endphp
        <table class="data-table" style="margin-bottom:14px;">
            <tbody>
                <tr class="cr-section-header">
                    <td>{{ $section['label'] }}</td>
                    <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">Prévu</td>
                    @if ($avecRealise)
                        <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">Réalisé</td>
                        <td class="text-right" style="width:80px;font-weight:400;font-size:10px;opacity:.85;">Écart</td>
                    @endif
                </tr>
                @forelse ($section['groupes'] as $codeFamille => $groupe)
                    <tr class="cr-cat">
                        <td colspan="{{ $colCount }}">{{ $groupe['famille']?->libelle() ?? $codeFamille }}</td>
                    </tr>
                    @foreach ($groupe['comptes'] as $compte)
                        @php
                            $line = $budgetLines->get($compte->id);
                            // Même règle que l'écran : une enveloppe absente
                            // affiche un tiret (case vide), jamais un zéro qui
                            // affirmerait à tort une prévision nulle. Le
                            // réalisé, lui, est toujours un montant réel.
                            $prevu = $line ? (float) $line->montant_prevu : 0.0;
                            $realise = (float) ($realiseData[$compte->id] ?? 0);
                            $ecart = \App\Support\ComparaisonBudgetaire::ecart($prevu, $realise);
                            $totalPrevu += $prevu;
                            $totalRealise += $realise;

                            $lignesVentilees = $ventilations->get($compte->id, collect());
                        @endphp
                        <tr class="cr-sub">
                            <td style="padding-left:20px;">
                                <span style="font-family:monospace;">{{ $compte->numero_pcg }}</span> — {{ $compte->intitule }}
                            </td>
                            <td class="text-right">{{ $line ? number_format($prevu, 2, ',', ' ').' €' : '—' }}</td>
                            @if ($avecRealise)
                                <td class="text-right">{{ number_format($realise, 2, ',', ' ') }} €</td>
                                <td class="text-right">{{ $line ? number_format($ecart, 2, ',', ' ').' €' : '—' }}</td>
                            @endif
                        </tr>
                        @if ($avecVentilations)
                            @foreach ($lignesVentilees as $v)
                                @php
                                    $vPrevu = (float) $v->montant_prevu;
                                    $vRealise = (float) ($realiseParOperation[$compte->id][$v->operation_id] ?? 0);
                                    $vEcart = \App\Support\ComparaisonBudgetaire::ecart($vPrevu, $vRealise);
                                @endphp
                                <tr class="cr-sub">
                                    <td style="padding-left:34px;font-style:italic;font-size:11px;">
                                        {{ $v->operation->nom ?? 'Opération supprimée' }}
                                    </td>
                                    <td class="text-right">{{ number_format($vPrevu, 2, ',', ' ') }} €</td>
                                    @if ($avecRealise)
                                        <td class="text-right">{{ number_format($vRealise, 2, ',', ' ') }} €</td>
                                        <td class="text-right">{{ number_format($vEcart, 2, ',', ' ') }} €</td>
                                    @endif
                                </tr>
                            @endforeach
                        @endif
                    @endforeach
                @empty
                    <tr>
                        <td colspan="{{ $colCount }}" class="text-center text-muted">Aucun compte.</td>
                    </tr>
                @endforelse
                <tr class="cr-total">
                    <td>TOTAL {{ $section['label'] }}</td>
                    <td class="text-right">{{ number_format($totalPrevu, 2, ',', ' ') }} €</td>
                    @if ($avecRealise)
                        <td class="text-right">{{ number_format($totalRealise, 2, ',', ' ') }} €</td>
                        <td class="text-right">{{ number_format(\App\Support\ComparaisonBudgetaire::ecart($totalPrevu, $totalRealise), 2, ',', ' ') }} €</td>
                    @endif
                </tr>
            </tbody>
        </table>
        @php
            $sectionTotaux[$section['label']] = ['prevu' => $totalPrevu, 'realise' => $totalRealise];
        @endphp
    @endforeach

    @php
        $resultatPrevu = ($sectionTotaux['RECETTES']['prevu'] ?? 0.0) - ($sectionTotaux['DÉPENSES']['prevu'] ?? 0.0);
        $resultatRealise = ($sectionTotaux['RECETTES']['realise'] ?? 0.0) - ($sectionTotaux['DÉPENSES']['realise'] ?? 0.0);
    @endphp
    <table class="data-table">
        <tbody>
            <tr class="cr-total">
                <td>RÉSULTAT (Produits - Charges)</td>
                <td class="text-right">{{ number_format($resultatPrevu, 2, ',', ' ') }} €</td>
                @if ($avecRealise)
                    <td class="text-right">{{ number_format($resultatRealise, 2, ',', ' ') }} €</td>
                    <td class="text-right">{{ number_format(\App\Support\ComparaisonBudgetaire::ecart($resultatPrevu, $resultatRealise), 2, ',', ' ') }} €</td>
                @endif
            </tr>
        </tbody>
    </table>
@endsection
