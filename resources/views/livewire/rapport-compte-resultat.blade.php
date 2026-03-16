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
        .cr-neg { color: #dc3545; }
        .cr-pos { color: #198754; }
        .cr-zero { color: #6c757d; }
        .budget-bar-track { background: #e2e8f0; border-radius: 4px; height: 10px; width: 110px; overflow: hidden; }
        .cr-total .budget-bar-track { background: rgba(255,255,255,.25); }
        .budget-bar-fill { height: 10px; border-radius: 4px; }
        .budget-label { font-size: 11px; text-align: right; color: #555; margin-top: 2px; }
        .cr-total .budget-label { color: rgba(255,255,255,.8); }
    </style>

    {{-- Export --}}
    <div class="d-flex justify-content-end mb-3">
        <button wire:click="exportCsv" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download"></i> Exporter CSV
        </button>
    </div>

    @php
        // Helper : barre + % pour une ligne
        $renderBar = function(?float $montantN, ?float $budget): string {
            if ($budget === null || $budget <= 0 || $montantN === null) return '<span class="text-muted">—</span>';
            $pct     = $montantN / $budget * 100;
            $pctCap  = min($pct, 100);
            $color   = $pct > 100 ? '#dc3545' : ($pct > 90 ? '#fd7e14' : '#198754');
            return '<div class="budget-bar-track"><div class="budget-bar-fill" style="width:' . $pctCap . '%;background:' . $color . ';"></div></div>'
                 . '<div class="budget-label">' . number_format($pct, 0) . ' %</div>';
        };
        $renderEcart = function(?float $montantN, ?float $budget, bool $isCharge): string {
            if ($budget === null || $montantN === null) return '<span class="text-muted">—</span>';
            $ecart = $montantN - $budget;
            if ($ecart == 0) return '<span class="cr-zero">0,00 €</span>';
            $isNeg = ($isCharge && $ecart < 0) || (!$isCharge && $ecart > 0);
            $cls = $isNeg ? 'cr-pos' : 'cr-neg';
            $sign = $ecart > 0 ? '+' : '';
            return '<span class="' . $cls . '">' . $sign . number_format($ecart, 2, ',', ' ') . ' €</span>';
        };
        $fmt = fn(?float $v): string => $v !== null ? number_format($v, 2, ',', ' ') . ' €' : '—';
    @endphp

    @foreach ([['data' => $charges, 'label' => 'DÉPENSES', 'isCharge' => true, 'total' => $totalChargesN],
               ['data' => $produits, 'label' => 'RECETTES', 'isCharge' => false, 'total' => $totalProduitsN]] as $section)
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                <tbody>
                    {{-- En-tête colonnes --}}
                    <tr class="cr-section-header">
                        <td style="width:20px;"></td>
                        <td></td>
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">{{ $labelN1 }}</td>
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">{{ $labelN }}</td>
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">Budget</td>
                        <td class="text-end" style="width:90px;font-weight:400;font-size:12px;opacity:.85;">Écart</td>
                        <td class="text-center" style="width:130px;font-weight:400;font-size:12px;opacity:.85;">Conso. budget</td>
                    </tr>
                    {{-- Titre section --}}
                    <tr class="cr-section-label">
                        <td colspan="7">{{ $section['label'] }}</td>
                    </tr>

                    @foreach ($section['data'] as $cat)
                        @php
                            // Règle d'affichage : sous-cat visible si montant_n > 0, ou N-1 > 0, ou budget défini
                            $scVisibles = collect($cat['sous_categories'])->filter(function($sc) {
                                return $sc['montant_n'] > 0
                                    || ($sc['montant_n1'] !== null && $sc['montant_n1'] > 0)
                                    || ($sc['budget'] !== null && $sc['budget'] > 0);
                            });
                        @endphp
                        @if (! $scVisibles->isEmpty())
                        <tr class="cr-cat">
                            <td></td>
                            <td>{{ $cat['label'] }}</td>
                            <td class="text-end cr-n1">{{ $fmt($cat['montant_n1']) }}</td>
                            <td class="text-end">{{ $fmt($cat['montant_n']) }}</td>
                            <td class="text-end">{{ $fmt($cat['budget']) }}</td>
                            <td class="text-end">{!! $renderEcart($cat['montant_n'], $cat['budget'], $section['isCharge']) !!}</td>
                            <td class="text-center">{!! $renderBar($cat['montant_n'], $cat['budget']) !!}</td>
                        </tr>
                        @foreach ($scVisibles as $sc)
                            <tr class="cr-sub">
                                <td></td>
                                <td style="padding-left:32px;">{{ $sc['label'] }}</td>
                                <td class="text-end cr-n1">{{ $fmt($sc['montant_n1']) }}</td>
                                <td class="text-end">{{ $fmt($sc['montant_n']) }}</td>
                                <td class="text-end">{{ $fmt($sc['budget']) }}</td>
                                <td class="text-end">{!! $renderEcart($sc['montant_n'], $sc['budget'], $section['isCharge']) !!}</td>
                                <td class="text-center">{!! $renderBar($sc['montant_n'], $sc['budget']) !!}</td>
                            </tr>
                        @endforeach
                        @endif
                    @endforeach

                    {{-- Total --}}
                    <tr class="cr-total">
                        <td colspan="2">TOTAL {{ $section['label'] }}</td>
                        <td class="text-end" style="color:#d0e4f7;">—</td>
                        <td class="text-end">{{ number_format($section['total'], 2, ',', ' ') }} €</td>
                        <td class="text-end">—</td>
                        <td class="text-end">—</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @endforeach

    {{-- Résultat net --}}
    <div class="rounded p-4 d-flex justify-content-between align-items-center mt-2"
         style="background:{{ $resultatNet >= 0 ? '#198754' : '#dc3545' }};color:#fff;font-size:1.1rem;font-weight:700;">
        <span>{{ $resultatNet >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }}</span>
        <span>{{ number_format(abs($resultatNet), 2, ',', ' ') }} €</span>
    </div>
</div>
