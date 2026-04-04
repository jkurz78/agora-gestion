<div x-data="{ showMensuel: false, showRecettesNP: false, showDepensesNP: false }">
    <style>
        [x-cloak] { display: none !important; }
        .ft-header td { background: #3d5473; color: #fff; font-weight: 400; font-size: 12px; padding: 8px 16px; border: none; }
        .ft-row td { padding: 10px 16px; font-size: 14px; border-bottom: 1px solid #e2e8f0; }
        .ft-row-label { font-weight: 600; color: #1e3a5f; }
        .ft-separator td { padding: 0; border-bottom: 3px double #3d5473; }
        .ft-result td { background: #3d5473; color: #fff; font-weight: 700; font-size: 15px; padding: 12px 16px; border-bottom: none; }
        .ft-total td { background: #5a7fa8; color: #fff; font-weight: 700; font-size: 14px; padding: 9px 16px; border: none; }
        .ft-mensuel td { padding: 5px 16px; font-size: 13px; border-bottom: 1px solid #eef1f5; color: #555; }
        .ft-toggle { cursor: pointer; user-select: none; }
        .ft-toggle:hover { background: #eef3f8; }
        .ft-chevron { display: inline-block; transition: transform .2s; font-size: 11px; margin-right: 6px; }
        .ft-chevron.open { transform: rotate(90deg); }

        .ft-rapprochement-header td { background: #3d5473; color: #fff; font-weight: 700; font-size: 14px; padding: 8px 16px; border: none; }
        .ft-rapprochement td { background: #f7f9fc; padding: 8px 16px; font-size: 13px; border-bottom: 1px solid #e2e8f0; }
        .ft-rapprochement-toggle { cursor: pointer; user-select: none; }
        .ft-rapprochement-toggle:hover td { background: #eef3f8; }
        .ft-rapprochement-detail td { background: #fff; padding: 4px 16px 4px 48px; font-size: 12px; color: #666; border-bottom: 1px solid #f0f0f0; }
        .ft-rapprochement-result td { background: #dce6f0; font-weight: 700; padding: 10px 16px; font-size: 14px; }
    </style>

    @php
        $fmt = fn(float $v): string => number_format($v, 2, ',', ' ') . ' €';
        $totalR = collect($mensuel)->sum('recettes');
        $totalD = collect($mensuel)->sum('depenses');
        $totalS = round($totalR - $totalD, 2);
    @endphp

    {{-- Bandeau statut --}}
    @if ($exercice['is_cloture'])
        <div class="alert alert-success mb-3">
            <i class="bi bi-check-circle me-1"></i>
            Rapport définitif — Exercice {{ $exercice['label'] }} clôturé le {{ $exercice['date_cloture'] }}
        </div>
    @else
        <div class="alert alert-info mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Rapport provisoire — Exercice {{ $exercice['label'] }} en cours
        </div>
    @endif

    {{-- Tableau principal --}}
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table mb-0" style="border-collapse:collapse;width:100%;">
                <tbody>
                    {{-- En-tête colonnes --}}
                    <tr class="ft-header">
                        <td></td>
                        <td class="text-end" style="width:140px;">Recettes</td>
                        <td class="text-end" style="width:140px;">Dépenses</td>
                        <td class="text-end" style="width:140px;">Solde (R-D)</td>
                        <td class="text-end" style="width:160px;">Trésorerie cumulée</td>
                    </tr>

                    {{-- Solde d'ouverture --}}
                    <tr class="ft-row">
                        <td class="ft-row-label" colspan="4">
                            Solde de trésorerie au {{ \Carbon\Carbon::parse($exercice['date_debut'])->translatedFormat('j F Y') }}
                        </td>
                        <td class="text-end fw-bold">{{ $fmt($synthese['solde_ouverture']) }}</td>
                    </tr>

                    {{-- Ligne flux annuels (dépliable) --}}
                    <tr class="ft-row ft-toggle" @click="showMensuel = !showMensuel">
                        <td class="ft-row-label">
                            <span class="ft-chevron" :class="showMensuel && 'open'">&#9654;</span>
                            Flux de l'exercice
                        </td>
                        <td class="text-end">{{ $fmt($synthese['total_recettes']) }}</td>
                        <td class="text-end">{{ $fmt($synthese['total_depenses']) }}</td>
                        <td class="text-end {{ $synthese['variation'] >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ $synthese['variation'] >= 0 ? '+' : '' }}{{ $fmt($synthese['variation']) }}
                        </td>
                        <td class="text-end fw-bold">{{ $fmt($synthese['solde_theorique']) }}</td>
                    </tr>

                    {{-- Détail mensuel (dépliant) --}}
                    @foreach ($mensuel as $ligne)
                        <tr class="ft-mensuel" x-show="showMensuel" x-cloak>
                            <td style="padding-left:36px;">{{ $ligne['mois'] }}</td>
                            <td class="text-end">{{ $fmt($ligne['recettes']) }}</td>
                            <td class="text-end">{{ $fmt($ligne['depenses']) }}</td>
                            <td class="text-end {{ $ligne['solde'] >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ $ligne['solde'] >= 0 ? '+' : '' }}{{ $fmt($ligne['solde']) }}
                            </td>
                            <td class="text-end fw-bold">{{ $fmt($ligne['cumul']) }}</td>
                        </tr>
                    @endforeach

                    {{-- Séparateur --}}
                    <tr class="ft-separator"><td colspan="5"></td></tr>

                    {{-- Solde théorique --}}
                    <tr class="ft-result">
                        <td colspan="4">
                            Solde de trésorerie théorique au {{ \Carbon\Carbon::parse($exercice['date_fin'])->translatedFormat('j F Y') }}
                        </td>
                        <td class="text-end">{{ $fmt($synthese['solde_theorique']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Rapprochement bancaire --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table mb-0" style="border-collapse:collapse;width:100%;">
                <tbody>
                    <tr class="ft-rapprochement-header">
                        <td colspan="2">Rapprochement bancaire</td>
                    </tr>
                    <tr class="ft-rapprochement">
                        <td>Solde théorique</td>
                        <td class="text-end" style="width:180px;">{{ $fmt($rapprochement['solde_theorique']) }}</td>
                    </tr>

                    {{-- Recettes non pointées (dépliable) --}}
                    <tr class="ft-rapprochement ft-rapprochement-toggle" @click="showRecettesNP = !showRecettesNP">
                        <td style="padding-left:32px;">
                            <span class="ft-chevron" :class="showRecettesNP && 'open'">&#9654;</span>
                            <span class="text-danger">−</span> Recettes non pointées
                            <span class="text-muted">({{ $rapprochement['nb_recettes_non_pointees'] }} {{ Str::plural('écriture', $rapprochement['nb_recettes_non_pointees']) }})</span>
                        </td>
                        <td class="text-end">{{ $fmt($rapprochement['recettes_non_pointees']) }}</td>
                    </tr>
                    @foreach (collect($ecritures_non_pointees)->where('type', 'recette') as $e)
                        <tr class="ft-rapprochement-detail" x-show="showRecettesNP" x-cloak>
                            <td>
                                <span class="text-muted me-2">{{ $e['numero_piece'] ?? '—' }}</span>
                                {{ $e['date'] }}
                                <span class="mx-1">·</span>
                                {{ $e['tiers'] }}
                                <span class="mx-1">·</span>
                                <span class="text-muted">{{ $e['libelle'] }}</span>
                            </td>
                            <td class="text-end">{{ $fmt($e['montant']) }}</td>
                        </tr>
                    @endforeach

                    {{-- Dépenses non pointées (dépliable) --}}
                    <tr class="ft-rapprochement ft-rapprochement-toggle" @click="showDepensesNP = !showDepensesNP">
                        <td style="padding-left:32px;">
                            <span class="ft-chevron" :class="showDepensesNP && 'open'">&#9654;</span>
                            <span class="text-success">+</span> Dépenses non pointées
                            <span class="text-muted">({{ $rapprochement['nb_depenses_non_pointees'] }} {{ Str::plural('écriture', $rapprochement['nb_depenses_non_pointees']) }})</span>
                        </td>
                        <td class="text-end">{{ $fmt($rapprochement['depenses_non_pointees']) }}</td>
                    </tr>
                    @foreach (collect($ecritures_non_pointees)->where('type', 'depense') as $e)
                        <tr class="ft-rapprochement-detail" x-show="showDepensesNP" x-cloak>
                            <td>
                                <span class="text-muted me-2">{{ $e['numero_piece'] ?? '—' }}</span>
                                {{ $e['date'] }}
                                <span class="mx-1">·</span>
                                {{ $e['tiers'] }}
                                <span class="mx-1">·</span>
                                <span class="text-muted">{{ $e['libelle'] }}</span>
                            </td>
                            <td class="text-end">{{ $fmt($e['montant']) }}</td>
                        </tr>
                    @endforeach

                    {{-- Solde réel --}}
                    <tr class="ft-rapprochement-result">
                        <td>= Solde bancaire réel</td>
                        <td class="text-end">{{ $fmt($rapprochement['solde_reel']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
