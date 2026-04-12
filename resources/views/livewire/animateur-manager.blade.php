<div class="mt-2" style="max-width:100%">
    @php
        $fmt = fn(float $v): string => number_format($v, 2, ',', "\u{202F}");
        $seanceNums = $seances->pluck('numero')->toArray();
        $animateurList = $matrixData['animateurs'];
        $seanceTotals = $matrixData['seanceTotals'];
        $grandTotal = $matrixData['grandTotal'];
        $colCount = count($seanceNums);
    @endphp

    <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" style="font-size:12px;table-layout:fixed;width:{{ 180 + ($colCount * 110) + 100 }}px">
                <colgroup>
                    <col style="width:180px">
                    @for($i = 0; $i < $colCount; $i++)<col style="width:110px">@endfor
                    <col style="width:100px">
                </colgroup>
                <thead>
                    <tr style="background:#3d5473;color:#fff">
                        <th style="position:sticky;left:0;z-index:2;background:#3d5473;font-size:11px;min-width:180px;text-align:right;font-weight:normal;font-style:italic;color:#b0c4de">Séance →</th>
                        @foreach($seances as $seance)
                            <th style="text-align:center;font-size:12px">S{{ $seance->numero }}</th>
                        @endforeach
                        <th style="text-align:center;font-size:12px">Total</th>
                    </tr>
                    <tr>
                        <td style="position:sticky;left:0;z-index:2;background:#f8f9fa;font-size:11px;font-weight:600;color:#495057">Encadrant ↓</td>
                        @foreach($seances as $seance)
                            <td style="background:#f8f9fa;text-align:center;font-size:10px;color:#6c757d">
                                @if($seance->titre_affiche){{ $seance->titre_affiche }}@endif
                                @if($seance->date){{ $seance->date->format('d/m') }}@endif
                            </td>
                        @endforeach
                        <td style="background:#f8f9fa"></td>
                    </tr>
                </thead>
                <tbody>
                    @forelse($animateurList as $tiersId => $anim)
                        {{-- Parent row: encadrant name + totals --}}
                        <tr style="background:#eef1f5">
                            <td style="position:sticky;left:0;z-index:1;background:#eef1f5;font-weight:600;padding:4px 8px;white-space:nowrap;font-size:12px">
                                {{ $anim['tiersName'] }}
                            </td>
                            @foreach($seanceNums as $num)
                                @php
                                    $key = (string) $num;
                                    $val = $anim['seanceTotals'][$key] ?? 0;
                                @endphp
                                <td style="text-align:center;font-weight:600;padding:4px 6px;vertical-align:middle">
                                    <div class="d-flex align-items-center justify-content-center gap-1">
                                        @if($val > 0)
                                            <span>{{ $fmt($val) }}</span>
                                        @endif
                                        <button class="btn btn-sm p-0 ms-1" style="color:#198754;font-size:14px;line-height:1;border:none;background:none"
                                                wire:click="openCreateModal({{ $tiersId }}, {{ $num }})"
                                                title="Nouvelle facture pour S{{ $num }}">&#8853;</button>
                                    </div>
                                </td>
                            @endforeach
                            <td style="text-align:center;font-weight:700;padding:4px 6px">
                                {{ $fmt($anim['total']) }}
                            </td>
                        </tr>
                        {{-- Sub-rows per sous-catégorie --}}
                        @foreach($anim['sousCategories'] as $scId => $sc)
                            <tr>
                                <td style="position:sticky;left:0;z-index:1;background:#fff;padding:2px 8px 2px 20px;font-size:11px;color:#6c757d;white-space:nowrap">
                                    {{ $sc['scName'] }}
                                </td>
                                @foreach($seanceNums as $num)
                                    @php
                                        $key = (string) $num;
                                        $cell = $sc['seanceAmounts'][$key] ?? null;
                                    @endphp
                                    <td style="text-align:center;padding:2px 4px;font-size:11px">
                                        @if($cell && $cell['montant'] > 0)
                                            <span style="cursor:pointer;text-decoration:underline dotted;color:#0d6efd"
                                                  wire:click="openEditModal(@js($cell['transactionIds']))"
                                                  title="Modifier la transaction">
                                                {{ $fmt($cell['montant']) }}
                                            </span>
                                            @if(!empty($cell['numeroPieces']))
                                                <div style="font-size:9px;color:#999;line-height:1.1">
                                                    @foreach($cell['numeroPieces'] as $np)
                                                        {{ $np }}@if(!$loop->last)<br>@endif
                                                    @endforeach
                                                </div>
                                            @endif
                                        @else
                                            <span style="color:#ccc">&mdash;</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td style="text-align:center;padding:2px 6px;font-size:11px;font-weight:500">
                                    {{ $fmt($sc['total']) }}
                                </td>
                            </tr>
                        @endforeach

                        {{-- Transversal (null seance) amounts if any --}}
                        @php $hasNull = false; @endphp
                        @foreach($anim['sousCategories'] as $scId => $sc)
                            @if(isset($sc['seanceAmounts']['null']))
                                @php $hasNull = true; @endphp
                            @endif
                        @endforeach
                        @if($hasNull && $colCount > 0)
                            <tr>
                                <td colspan="{{ 1 + $colCount + 1 }}" style="padding:2px 8px 2px 20px;font-size:10px;color:#888;background:#fffdf0">
                                    <i class="bi bi-info-circle me-1"></i>Ce tiers a aussi des d&eacute;penses sans s&eacute;ance affect&eacute;e (incluses dans le total)
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ $colCount + 2 }}" class="text-center text-muted py-3" style="font-size:12px">
                                Ajoutez un encadrant ci-dessous pour commencer le suivi des factures.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr style="background:#eef1f5;font-weight:600;font-size:12px">
                        <td style="position:sticky;left:0;z-index:1;background:#eef1f5;padding:6px 8px">Total</td>
                        @foreach($seanceNums as $num)
                            @php $key = (string) $num; @endphp
                            <td style="text-align:center">
                                {{ $fmt($seanceTotals[$key] ?? 0) }}
                            </td>
                        @endforeach
                        <td style="text-align:center;font-weight:700">
                            {{ $fmt($grandTotal) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

    {{-- Ajouter un encadrant --}}
    <div class="mt-3 p-3 border rounded" style="max-width:400px;background:#fafafa">
        <label class="form-label fw-medium" style="font-size:13px">
            <i class="bi bi-plus-circle me-1"></i>Ajouter un encadrant
        </label>
        <livewire:tiers-autocomplete wire:model="newTiersId" filtre="depenses" :key="'anim-tiers-'.$operation->id" />
    </div>

    {{-- Modal --}}
    @include('livewire.animateur-manager-modal')
</div>
