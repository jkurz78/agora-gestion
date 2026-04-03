<div>
    <style>
        .ft-row td { padding: 10px 16px; font-size: 14px; border-bottom: 1px solid #e2e8f0; }
        .ft-row-label { font-weight: 600; color: #1e3a5f; }
        .ft-row-indent { padding-left: 32px !important; color: #555; }
        .ft-row-separator td { padding: 0; border-bottom: 3px double #3d5473; }
        .ft-row-result td { background: #3d5473; color: #fff; font-weight: 700; font-size: 15px; padding: 12px 16px; border-bottom: none; }
        .ft-row-rapprochement td { background: #f7f9fc; padding: 8px 16px; font-size: 13px; border-bottom: 1px solid #e2e8f0; }
        .ft-row-rapprochement-result td { background: #dce6f0; font-weight: 700; padding: 10px 16px; font-size: 14px; }
        .ft-mensuel-header th { background: #3d5473; color: #fff; font-weight: 400; font-size: 12px; padding: 8px 12px; border: none; }
        .ft-mensuel-row td { padding: 6px 12px; font-size: 13px; border-bottom: 1px solid #e2e8f0; }
        .ft-mensuel-total td { background: #5a7fa8; color: #fff; font-weight: 700; font-size: 13px; padding: 9px 12px; border: none; }
    </style>

    @php
        $fmt = fn(float $v): string => number_format($v, 2, ',', ' ') . ' €';
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

    {{-- Toggle flux mensuels --}}
    <div class="d-flex justify-content-end mb-3">
        <div class="form-check form-switch">
            <input type="checkbox" wire:model.live="fluxMensuels" class="form-check-input" id="toggleMensuel">
            <label class="form-check-label small" for="toggleMensuel">Flux mensuels</label>
        </div>
    </div>

    {{-- Section 1 : Synthèse annuelle --}}
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table mb-0" style="border-collapse:collapse;width:100%;">
                <tbody>
                    <tr class="ft-row">
                        <td class="ft-row-label">Solde de trésorerie au {{ \Carbon\Carbon::parse($exercice['date_debut'])->translatedFormat('j F Y') }}</td>
                        <td class="text-end" style="width:180px;">{{ $fmt($synthese['solde_ouverture']) }}</td>
                    </tr>
                    <tr class="ft-row">
                        <td class="ft-row-label"><span class="text-success">+</span> Encaissements (recettes)</td>
                        <td class="text-end">{{ $fmt($synthese['total_recettes']) }}</td>
                    </tr>
                    <tr class="ft-row">
                        <td class="ft-row-label"><span class="text-danger">−</span> Décaissements (dépenses)</td>
                        <td class="text-end">{{ $fmt($synthese['total_depenses']) }}</td>
                    </tr>
                    <tr class="ft-row">
                        <td class="ft-row-label">= Variation de trésorerie</td>
                        <td class="text-end fw-bold {{ $synthese['variation'] >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ $synthese['variation'] >= 0 ? '+' : '' }}{{ $fmt($synthese['variation']) }}
                        </td>
                    </tr>
                    <tr class="ft-row-separator"><td colspan="2"></td></tr>
                    <tr class="ft-row-result">
                        <td>Solde de trésorerie théorique au {{ \Carbon\Carbon::parse($exercice['date_fin'])->translatedFormat('j F Y') }}</td>
                        <td class="text-end">{{ $fmt($synthese['solde_theorique']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Section Rapprochement --}}
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table mb-0" style="border-collapse:collapse;width:100%;">
                <tbody>
                    <tr style="background:#3d5473;color:#fff;">
                        <td colspan="2" style="font-weight:700;font-size:14px;padding:8px 16px;border:none;">Rapprochement bancaire</td>
                    </tr>
                    <tr class="ft-row-rapprochement">
                        <td>Solde théorique</td>
                        <td class="text-end" style="width:180px;">{{ $fmt($rapprochement['solde_theorique']) }}</td>
                    </tr>
                    <tr class="ft-row-rapprochement">
                        <td class="ft-row-indent">
                            <span class="text-danger">−</span> Recettes non pointées
                            <span class="text-muted">({{ $rapprochement['nb_recettes_non_pointees'] }} {{ Str::plural('écriture', $rapprochement['nb_recettes_non_pointees']) }})</span>
                        </td>
                        <td class="text-end">{{ $fmt($rapprochement['recettes_non_pointees']) }}</td>
                    </tr>
                    <tr class="ft-row-rapprochement">
                        <td class="ft-row-indent">
                            <span class="text-success">+</span> Dépenses non pointées
                            <span class="text-muted">({{ $rapprochement['nb_depenses_non_pointees'] }} {{ Str::plural('écriture', $rapprochement['nb_depenses_non_pointees']) }})</span>
                        </td>
                        <td class="text-end">{{ $fmt($rapprochement['depenses_non_pointees']) }}</td>
                    </tr>
                    <tr class="ft-row-rapprochement-result">
                        <td>= Solde bancaire réel</td>
                        <td class="text-end">{{ $fmt($rapprochement['solde_reel']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Section 2 : Tableau mensuel (conditionnel) --}}
    @if ($fluxMensuels)
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table mb-0" style="border-collapse:collapse;width:100%;">
                <thead>
                    <tr class="ft-mensuel-header">
                        <th>Mois</th>
                        <th class="text-end" style="width:140px;">Recettes</th>
                        <th class="text-end" style="width:140px;">Dépenses</th>
                        <th class="text-end" style="width:140px;">Solde (R-D)</th>
                        <th class="text-end" style="width:160px;">Trésorerie cumulée</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($mensuel as $ligne)
                        <tr class="ft-mensuel-row">
                            <td>{{ $ligne['mois'] }}</td>
                            <td class="text-end">{{ $fmt($ligne['recettes']) }}</td>
                            <td class="text-end">{{ $fmt($ligne['depenses']) }}</td>
                            <td class="text-end {{ $ligne['solde'] >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ $ligne['solde'] >= 0 ? '+' : '' }}{{ $fmt($ligne['solde']) }}
                            </td>
                            <td class="text-end fw-bold">{{ $fmt($ligne['cumul']) }}</td>
                        </tr>
                    @endforeach
                    @php
                        $totalR = collect($mensuel)->sum('recettes');
                        $totalD = collect($mensuel)->sum('depenses');
                        $totalS = round($totalR - $totalD, 2);
                    @endphp
                    <tr class="ft-mensuel-total">
                        <td>Total</td>
                        <td class="text-end">{{ $fmt($totalR) }}</td>
                        <td class="text-end">{{ $fmt($totalD) }}</td>
                        <td class="text-end">{{ $totalS >= 0 ? '+' : '' }}{{ $fmt($totalS) }}</td>
                        <td class="text-end">{{ $fmt(end($mensuel)['cumul']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
