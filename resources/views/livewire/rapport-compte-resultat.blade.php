<div>
    {{-- Style commun aux 3 rapports --}}
    <style>
        .cr-section-header { background: #3d5473; color: #fff; }
        .cr-section-header td { border-bottom: none; padding: 8px 12px; }
        .cr-section-label td { background: #3d5473; color: #fff; font-weight: 700; font-size: 14px; border-bottom: none; padding: 4px 12px 10px; }
        .cr-cat td { background: #dce6f0; color: #1e3a5f; font-weight: 600; border-bottom: 1px solid #b8ccdf; padding: 7px 12px; }
        .cr-sub td { background: #f7f9fc; color: #444; border-bottom: 1px solid #e2e8f0; padding: 5px 12px; }
        .cr-total td { background: #5a7fa8; color: #fff; font-weight: 700; font-size: 14px; border-bottom: none; padding: 9px 12px; }
        .cr-n1 { color: #9ab0c8; }
        .cr-cat .cr-n1 { color: #6b8aaa; }
        .cr-neg { color: #B5453A; }
        .cr-pos { color: #2E7D32; }
        .cr-zero { color: #6c757d; }
        @if($compareBudget)
        .budget-bar-track { background: #e2e8f0; border-radius: 4px; height: 10px; width: 110px; overflow: hidden; }
        .cr-total .budget-bar-track { background: rgba(255,255,255,.25); }
        .budget-bar-fill { height: 10px; border-radius: 4px; }
        .budget-label { font-size: 11px; text-align: right; color: #555; margin-top: 2px; }
        .cr-total .budget-label { color: rgba(255,255,255,.8); }
        @endif
    </style>

    {{-- Export + switches --}}
    <div class="d-flex justify-content-end align-items-center gap-3 mb-3">
        <div class="form-check form-switch mb-0">
            <input type="checkbox" wire:model.live="compareN1" class="form-check-input" id="toggleN1">
            <label class="form-check-label small" for="toggleN1">Afficher N&#8209;1</label>
        </div>
        <div class="form-check form-switch mb-0">
            <input type="checkbox" wire:model.live="compareBudget" class="form-check-input" id="toggleBudget">
            <label class="form-check-label small" for="toggleBudget">Afficher budget &amp; écart</label>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-download me-1"></i>Exporter
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="{{ $this->exportUrl('xlsx') }}"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</a></li>
                <li><a class="dropdown-item" href="{{ $this->exportUrl('pdf') }}" target="_blank"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a></li>
            </ul>
        </div>
    </div>

    @php
        // Helper : barre + % pour une ligne
        $renderBar = function(?float $montantN, ?float $budget, bool $isCharge): string {
            if ($budget === null || $budget <= 0 || $montantN === null) return '<span class="text-muted">&mdash;</span>';
            $pct     = $montantN / $budget * 100;
            $pctCap  = min($pct, 100);
            $color   = \App\Support\ComparaisonBudgetaire::couleurBarre($pct, $isCharge);
            return '<div class="budget-bar-track"><div class="budget-bar-fill" style="width:' . $pctCap . '%;background:' . $color . ';"></div></div>'
                 . '<div class="budget-label">' . number_format($pct, 0) . ' %</div>';
        };
        $renderEcart = function(?float $montantN, ?float $budget, bool $isCharge): string {
            if ($budget === null || $montantN === null) return '<span class="text-muted">&mdash;</span>';
            $ecart = $montantN - $budget;
            if ($ecart == 0) return '<span class="cr-zero">0,00 &euro;</span>';
            $isNeg = ($isCharge && $ecart < 0) || (!$isCharge && $ecart > 0);
            $cls = $isNeg ? 'cr-pos' : 'cr-neg';
            $sign = $ecart > 0 ? '+' : '';
            return '<span class="' . $cls . '">' . $sign . number_format($ecart, 2, ',', ' ') . ' &euro;</span>';
        };
        $fmt = fn(?float $v): string => $v !== null ? number_format($v, 2, ',', ' ') . ' &euro;' : '&mdash;';
    @endphp

    @foreach ([['data' => $charges, 'label' => 'DEPENSES', 'isCharge' => true, 'total' => $totalChargesN],
               ['data' => $produits, 'label' => 'RECETTES', 'isCharge' => false, 'total' => $totalProduitsN]] as $section)
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                <tbody>
                    {{-- En-tete colonnes --}}
                    <tr class="cr-section-header">
                        <td style="width:20px;"></td>
                        <td></td>
                        @if($compareN1)<td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">{{ $labelN1 }}</td>@endif
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">{{ $labelN }}</td>
                        @if($compareBudget)
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">Budget</td>
                        <td class="text-end" style="width:90px;font-weight:400;font-size:12px;opacity:.85;">&Eacute;cart</td>
                        <td class="text-center" style="width:130px;font-weight:400;font-size:12px;opacity:.85;">Conso. budget</td>
                        @endif
                    </tr>
                    {{-- Titre section --}}
                    <tr class="cr-section-label">
                        <td colspan="{{ 4 + ($compareN1 ? 1 : 0) + ($compareBudget ? 3 : 0) }}">{{ $section['label'] }}</td>
                    </tr>

                    @foreach ($section['data'] as $cat)
                        @php
                            // Règle d'affichage : compte visible dès qu'il porte un mouvement
                            // sur N, N-1 ou le budget — quel que soit son SIGNE. Le test était
                            // « > 0 », écrit quand tout compte de classe 7 était nécessairement
                            // créditeur. Un contra-produit — 709A Gratuités accordées — porte un
                            // montant négatif et disparaissait du détail, laissant la famille
                            // afficher un net inexpliqué.
                            $scVisibles = collect($cat['comptes'])->filter(function($sc) {
                                return abs((float) $sc['montant_n']) > 0.001
                                    || ($sc['montant_n1'] !== null && abs((float) $sc['montant_n1']) > 0.001)
                                    || ($sc['budget'] !== null && abs((float) $sc['budget']) > 0.001);
                            });
                        @endphp
                        @if (! $scVisibles->isEmpty())
                        <tr class="cr-cat">
                            <td></td>
                            <td>{{ $cat['famille_nom'] }}</td>
                            @if($compareN1)<td class="text-end cr-n1">{!! $fmt($cat['montant_n1']) !!}</td>@endif
                            <td class="text-end">{!! $fmt($cat['montant_n']) !!}</td>
                            @if($compareBudget)
                            <td class="text-end">{!! $fmt($cat['budget']) !!}</td>
                            <td class="text-end">{!! $renderEcart($cat['montant_n'], $cat['budget'], $section['isCharge']) !!}</td>
                            <td class="text-center">{!! $renderBar($cat['montant_n'], $cat['budget'], $section['isCharge']) !!}</td>
                            @endif
                        </tr>
                        @foreach ($scVisibles as $sc)
                            <tr class="cr-sub">
                                <td></td>
                                <td style="padding-left:32px;">{{ $sc['compte_nom'] }}</td>
                                @if($compareN1)<td class="text-end cr-n1">{!! $fmt($sc['montant_n1']) !!}</td>@endif
                                <td class="text-end">{!! $fmt($sc['montant_n']) !!}</td>
                                @if($compareBudget)
                                <td class="text-end">{!! $fmt($sc['budget']) !!}</td>
                                <td class="text-end">{!! $renderEcart($sc['montant_n'], $sc['budget'], $section['isCharge']) !!}</td>
                                <td class="text-center">{!! $renderBar($sc['montant_n'], $sc['budget'], $section['isCharge']) !!}</td>
                                @endif
                            </tr>
                        @endforeach
                        @endif
                    @endforeach

                    {{-- Total --}}
                    <tr class="cr-total">
                        <td colspan="2">TOTAL {{ $section['label'] }}</td>
                        @if($compareN1)<td class="text-end" style="color:#d0e4f7;">&mdash;</td>@endif
                        <td class="text-end">{{ number_format($section['total'], 2, ',', ' ') }} &euro;</td>
                        @if($compareBudget)
                        <td class="text-end">&mdash;</td>
                        <td class="text-end">&mdash;</td>
                        <td></td>
                        @endif
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @endforeach

    {{-- Resultat --}}
    @php $resultatColor = $resultatCourant >= 0 ? '#2E7D32' : '#B5453A'; @endphp
    <div class="card mb-3 border-0 shadow-sm mt-2">
        <div class="card-body p-0">
            <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                <tbody>
                    <tr style="background:{{ $resultatColor }};color:#fff;font-weight:700;font-size:14px;">
                        <td style="width:20px;padding:12px;"></td>
                        <td style="padding:12px;">RÉSULTAT</td>
                        @if($compareN1)<td class="text-end" style="width:115px;padding:12px;color:rgba(255,255,255,.6);">{!! $resultatCourantN1 != 0 ? number_format($resultatCourantN1, 2, ',', ' ').' &euro;' : '&mdash;' !!}</td>@endif
                        <td class="text-end" style="width:115px;padding:12px;">{{ number_format($resultatCourant, 2, ',', ' ') }} &euro;</td>
                        @if($compareBudget)
                        <td style="width:115px;padding:12px;"></td>
                        <td style="width:90px;padding:12px;"></td>
                        <td style="width:130px;padding:12px;"></td>
                        @endif
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
