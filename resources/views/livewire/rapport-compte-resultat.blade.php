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
        .budget-bar-track { background: #e2e8f0; border-radius: 4px; height: 10px; width: 110px; overflow: hidden; }
        .cr-total .budget-bar-track { background: rgba(255,255,255,.25); }
        .budget-bar-fill { height: 10px; border-radius: 4px; }
        .budget-label { font-size: 11px; text-align: right; color: #555; margin-top: 2px; }
        .cr-total .budget-label { color: rgba(255,255,255,.8); }
    </style>

    {{-- Export --}}
    <div class="d-flex justify-content-end mb-3">
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
        $renderBar = function(?float $montantN, ?float $budget): string {
            if ($budget === null || $budget <= 0 || $montantN === null) return '<span class="text-muted">&mdash;</span>';
            $pct     = $montantN / $budget * 100;
            $pctCap  = min($pct, 100);
            $color   = $pct > 100 ? '#B5453A' : ($pct > 90 ? '#fd7e14' : '#2E7D32');
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

    @if($extournes->isNotEmpty() || $extournesN1->isNotEmpty())
    {{-- Extournes provisions N-1 --}}
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                <tbody>
                    <tr class="cr-section-header">
                        <td style="width:20px;"></td>
                        <td></td>
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">{{ $labelN1 }}</td>
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">{{ $labelN }}</td>
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">Budget</td>
                        <td class="text-end" style="width:90px;font-weight:400;font-size:12px;opacity:.85;">&Eacute;cart</td>
                        <td class="text-center" style="width:130px;font-weight:400;font-size:12px;opacity:.85;">Conso. budget</td>
                    </tr>
                    <tr class="cr-section-label">
                        <td colspan="7">EXTOURNES PROVISIONS N&minus;1</td>
                    </tr>
                    @php
                        // Build a merged list keyed by libelle+sous_cat for alignment
                        $extN1ByKey = $extournesN1->keyBy(fn($e) => $e['libelle'].'|'.$e['sous_categorie_id']);
                        $extNByKey  = $extournes->keyBy(fn($e) => $e['libelle'].'|'.$e['sous_categorie_id']);
                        $extAllKeys = $extN1ByKey->keys()->merge($extNByKey->keys())->unique();
                    @endphp
                    @foreach($extAllKeys as $key)
                        @php
                            $extN  = $extNByKey->get($key);
                            $extN1 = $extN1ByKey->get($key);
                            $item  = $extN ?? $extN1;
                        @endphp
                        <tr class="cr-sub">
                            <td></td>
                            <td style="padding-left:32px;">{{ $item['libelle'] }} <span class="text-muted">({{ $item['sous_categorie_nom'] }})</span></td>
                            <td class="text-end cr-n1">{!! $extN1 ? number_format($extN1['montant_signe'], 2, ',', ' ').' &euro;' : '<span class="text-muted">&mdash;</span>' !!}</td>
                            <td class="text-end">{!! $extN ? number_format($extN['montant_signe'], 2, ',', ' ').' &euro;' : '<span class="text-muted">&mdash;</span>' !!}</td>
                            <td class="text-end"><span class="text-muted">&mdash;</span></td>
                            <td class="text-end"><span class="text-muted">&mdash;</span></td>
                            <td></td>
                        </tr>
                    @endforeach
                    <tr class="cr-total">
                        <td colspan="2">TOTAL EXTOURNES</td>
                        <td class="text-end" style="color:#d0e4f7;">{!! $totalExtournesN1 != 0 ? number_format($totalExtournesN1, 2, ',', ' ').' &euro;' : '&mdash;' !!}</td>
                        <td class="text-end">{{ number_format($totalExtournes, 2, ',', ' ') }} &euro;</td>
                        <td class="text-end">&mdash;</td>
                        <td class="text-end">&mdash;</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @endif

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
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">{{ $labelN1 }}</td>
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">{{ $labelN }}</td>
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">Budget</td>
                        <td class="text-end" style="width:90px;font-weight:400;font-size:12px;opacity:.85;">&Eacute;cart</td>
                        <td class="text-center" style="width:130px;font-weight:400;font-size:12px;opacity:.85;">Conso. budget</td>
                    </tr>
                    {{-- Titre section --}}
                    <tr class="cr-section-label">
                        <td colspan="7">{{ $section['label'] }}</td>
                    </tr>

                    @foreach ($section['data'] as $cat)
                        @php
                            // Regle d'affichage : sous-cat visible si montant_n > 0, ou N-1 > 0, ou budget defini
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
                            <td class="text-end cr-n1">{!! $fmt($cat['montant_n1']) !!}</td>
                            <td class="text-end">{!! $fmt($cat['montant_n']) !!}</td>
                            <td class="text-end">{!! $fmt($cat['budget']) !!}</td>
                            <td class="text-end">{!! $renderEcart($cat['montant_n'], $cat['budget'], $section['isCharge']) !!}</td>
                            <td class="text-center">{!! $renderBar($cat['montant_n'], $cat['budget']) !!}</td>
                        </tr>
                        @foreach ($scVisibles as $sc)
                            <tr class="cr-sub">
                                <td></td>
                                <td style="padding-left:32px;">{{ $sc['label'] }}</td>
                                <td class="text-end cr-n1">{!! $fmt($sc['montant_n1']) !!}</td>
                                <td class="text-end">{!! $fmt($sc['montant_n']) !!}</td>
                                <td class="text-end">{!! $fmt($sc['budget']) !!}</td>
                                <td class="text-end">{!! $renderEcart($sc['montant_n'], $sc['budget'], $section['isCharge']) !!}</td>
                                <td class="text-center">{!! $renderBar($sc['montant_n'], $sc['budget']) !!}</td>
                            </tr>
                        @endforeach
                        @endif
                    @endforeach

                    {{-- Total --}}
                    <tr class="cr-total">
                        <td colspan="2">TOTAL {{ $section['label'] }}</td>
                        <td class="text-end" style="color:#d0e4f7;">&mdash;</td>
                        <td class="text-end">{{ number_format($section['total'], 2, ',', ' ') }} &euro;</td>
                        <td class="text-end">&mdash;</td>
                        <td class="text-end">&mdash;</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @endforeach

    @if($provisions->isNotEmpty() || $provisionsN1->isNotEmpty() || $extournes->isNotEmpty() || $extournesN1->isNotEmpty())
    {{-- Resultat brut (avant provisions) --}}
    <div class="card mb-3 border-0 shadow-sm mt-2">
        <div class="card-body p-0">
            <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                <tbody>
                    <tr style="background:#5a7fa8;color:#fff;font-weight:700;font-size:14px;">
                        <td style="width:20px;padding:9px 12px;"></td>
                        <td style="padding:9px 12px;">RÉSULTAT BRUT</td>
                        <td class="text-end" style="width:115px;padding:9px 12px;color:#d0e4f7;">{!! $resultatBrutN1 != 0 ? number_format($resultatBrutN1, 2, ',', ' ').' &euro;' : '&mdash;' !!}</td>
                        <td class="text-end" style="width:115px;padding:9px 12px;">{{ number_format($resultatBrut, 2, ',', ' ') }} &euro;</td>
                        <td style="width:115px;padding:9px 12px;"></td>
                        <td style="width:90px;padding:9px 12px;"></td>
                        <td style="width:130px;padding:9px 12px;"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    @if($provisions->isNotEmpty() || $provisionsN1->isNotEmpty())
    {{-- Provisions fin d'exercice --}}
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                <tbody>
                    <tr class="cr-section-header">
                        <td style="width:20px;"></td>
                        <td></td>
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">{{ $labelN1 }}</td>
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">{{ $labelN }}</td>
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">Budget</td>
                        <td class="text-end" style="width:90px;font-weight:400;font-size:12px;opacity:.85;">&Eacute;cart</td>
                        <td class="text-center" style="width:130px;font-weight:400;font-size:12px;opacity:.85;">Conso. budget</td>
                    </tr>
                    <tr class="cr-section-label">
                        <td colspan="7">PROVISIONS FIN D&#039;EXERCICE</td>
                    </tr>
                    @php
                        $provN1ByKey = $provisionsN1->keyBy(fn($p) => $p['libelle'].'|'.$p['sous_categorie_id']);
                        $provNByKey  = $provisions->keyBy(fn($p) => $p['libelle'].'|'.$p['sous_categorie_id']);
                        $provAllKeys = $provN1ByKey->keys()->merge($provNByKey->keys())->unique();
                    @endphp
                    @foreach($provAllKeys as $key)
                        @php
                            $provN  = $provNByKey->get($key);
                            $provN1 = $provN1ByKey->get($key);
                            $item   = $provN ?? $provN1;
                        @endphp
                        <tr class="cr-sub">
                            <td></td>
                            <td style="padding-left:32px;">{{ $item['libelle'] }} <span class="text-muted">({{ $item['sous_categorie_nom'] }})</span></td>
                            <td class="text-end cr-n1">{!! $provN1 ? number_format($provN1['montant_signe'], 2, ',', ' ').' &euro;' : '<span class="text-muted">&mdash;</span>' !!}</td>
                            <td class="text-end">{!! $provN ? number_format($provN['montant_signe'], 2, ',', ' ').' &euro;' : '<span class="text-muted">&mdash;</span>' !!}</td>
                            <td class="text-end"><span class="text-muted">&mdash;</span></td>
                            <td class="text-end"><span class="text-muted">&mdash;</span></td>
                            <td></td>
                        </tr>
                    @endforeach
                    <tr class="cr-total">
                        <td colspan="2">TOTAL PROVISIONS</td>
                        <td class="text-end" style="color:#d0e4f7;">{!! $totalProvisionsN1 != 0 ? number_format($totalProvisionsN1, 2, ',', ' ').' &euro;' : '&mdash;' !!}</td>
                        <td class="text-end">{{ number_format($totalProvisions, 2, ',', ' ') }} &euro;</td>
                        <td class="text-end">&mdash;</td>
                        <td class="text-end">&mdash;</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Resultat net ajuste --}}
    @php $resultatColor = $resultatNet >= 0 ? '#2E7D32' : '#B5453A'; @endphp
    <div class="card mb-3 border-0 shadow-sm mt-2">
        <div class="card-body p-0">
            <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                <tbody>
                    <tr style="background:{{ $resultatColor }};color:#fff;font-weight:700;font-size:14px;">
                        <td style="width:20px;padding:12px;"></td>
                        <td style="padding:12px;">RÉSULTAT AJUSTÉ</td>
                        <td class="text-end" style="width:115px;padding:12px;color:rgba(255,255,255,.6);">{!! $resultatNetN1 != 0 ? number_format($resultatNetN1, 2, ',', ' ').' &euro;' : '&mdash;' !!}</td>
                        <td class="text-end" style="width:115px;padding:12px;">{{ number_format($resultatNet, 2, ',', ' ') }} &euro;</td>
                        <td style="width:115px;padding:12px;"></td>
                        <td style="width:90px;padding:12px;"></td>
                        <td style="width:130px;padding:12px;"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    @else
    {{-- Pas de provisions ni extournes --}}
    @php $resultatColor = $resultatNet >= 0 ? '#2E7D32' : '#B5453A'; @endphp
    <div class="card mb-3 border-0 shadow-sm mt-2">
        <div class="card-body p-0">
            <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                <tbody>
                    <tr style="background:{{ $resultatColor }};color:#fff;font-weight:700;font-size:14px;">
                        <td style="width:20px;padding:12px;"></td>
                        <td style="padding:12px;">RÉSULTAT</td>
                        <td class="text-end" style="width:115px;padding:12px;color:rgba(255,255,255,.6);">&mdash;</td>
                        <td class="text-end" style="width:115px;padding:12px;">{{ number_format($resultatNet, 2, ',', ' ') }} &euro;</td>
                        <td style="width:115px;padding:12px;"></td>
                        <td style="width:90px;padding:12px;"></td>
                        <td style="width:130px;padding:12px;"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
