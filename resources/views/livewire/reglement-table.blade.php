<div style="max-width:100%;overflow:hidden">
    @if($participants->isEmpty())
        <div class="text-center text-muted py-4">
            <i class="bi bi-people" style="font-size:2rem;opacity:0.3"></i>
            <p class="mt-2">Aucun participant inscrit à cette opération.</p>
        </div>
    @elseif($seances->isEmpty())
        <div class="text-center text-muted py-4">
            <i class="bi bi-calendar-week" style="font-size:2rem;opacity:0.3"></i>
            <p class="mt-2">Aucune séance définie pour cette opération.</p>
        </div>
    @else
        @php
            // Compute totals
            $totalPrevuParSeance = [];
            $totalRealiseParSeance = [];
            foreach ($seances as $s) {
                $totalPrevuParSeance[$s->id] = 0;
                $totalRealiseParSeance[$s->id] = 0;
            }
            $totalPrevuParParticipant = [];
            $totalRealiseParParticipant = [];

            foreach ($participants as $p) {
                $totalPrevuParParticipant[$p->id] = 0;
                $totalRealiseParParticipant[$p->id] = 0;
                foreach ($seances as $s) {
                    $key = $p->id . '-' . $s->id;
                    $prevu = (float) ($reglementMap[$key]?->montant_prevu ?? 0);
                    $realise = $realiseMap[$key] ?? 0;
                    $totalPrevuParSeance[$s->id] += $prevu;
                    $totalRealiseParSeance[$s->id] += $realise;
                    $totalPrevuParParticipant[$p->id] += $prevu;
                    $totalRealiseParParticipant[$p->id] += $realise;
                }
            }
            $grandTotalPrevu = array_sum($totalPrevuParSeance);
            $grandTotalRealise = array_sum($totalRealiseParSeance);
        @endphp

        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" style="font-size:12px;table-layout:fixed;width:{{ 160 + ($seances->count() * 130) + 100 }}px">
                <thead>
                    {{-- Row 1: S# headers --}}
                    <tr style="background:#3d5473;color:#fff">
                        <td rowspan="3" style="position:sticky;left:0;z-index:2;background:#fff;vertical-align:middle;font-weight:600;color:#555;font-size:11px;min-width:160px">Participant</td>
                        @foreach($seances as $seance)
                            <th style="min-width:120px;text-align:center;font-size:12px">S{{ $seance->numero }}</th>
                        @endforeach
                        <th style="min-width:100px;text-align:center;font-size:12px">Total</th>
                    </tr>
                    {{-- Row 2: Titles --}}
                    <tr>
                        @foreach($seances as $seance)
                            <td style="background:#f8f9fa;text-align:center;font-size:11px;color:#6c757d">{{ $seance->titre ?? '' }}</td>
                        @endforeach
                        <td style="background:#f8f9fa"></td>
                    </tr>
                    {{-- Row 3: Dates --}}
                    <tr>
                        @foreach($seances as $seance)
                            <td style="background:#f8f9fa;text-align:center;font-size:11px;color:#6c757d">{{ $seance->date?->format('d/m') ?? '' }}</td>
                        @endforeach
                        <td style="background:#f8f9fa"></td>
                    </tr>
                </thead>
                <tbody>
                    @foreach($participants as $participant)
                        @php
                            $prevuLigne = $totalPrevuParParticipant[$participant->id];
                            $realiseLigne = $totalRealiseParParticipant[$participant->id];
                            $ecartLigne = $realiseLigne - $prevuLigne;
                        @endphp
                        {{-- Row 1: Mode + Montant prévu --}}
                        <tr>
                            <td rowspan="2" style="position:sticky;left:0;z-index:1;background:#fff;font-weight:500;white-space:nowrap;vertical-align:middle;font-size:11px">
                                {{ $participant->tiers->nom }} {{ $participant->tiers->prenom }}
                                <button class="btn btn-sm p-0 ms-1" style="color:#0d6efd;font-size:11px;border:1px solid #0d6efd;border-radius:3px;padding:0 4px !important;line-height:1.4"
                                        wire:click="copierLigne({{ $participant->id }})"
                                        title="Recopier la 1re séance sur toute la ligne">→</button>
                            </td>
                            @foreach($seances as $seance)
                                @php
                                    $key = $participant->id . '-' . $seance->id;
                                    $reglement = $reglementMap[$key] ?? null;
                                    $mode = $reglement?->mode_paiement;
                                    $montant = $reglement ? number_format((float) $reglement->montant_prevu, 2, ',', '') : '0,00';
                                    $locked = $reglement?->remise_id !== null;
                                    $triColors = [
                                        'cheque' => 'background:#e7f1ff;color:#0d6efd',
                                        'virement' => 'background:#d4edda;color:#155724',
                                        'especes' => 'background:#fff3cd;color:#856404',
                                    ];
                                    $triStyle = $mode ? ($triColors[$mode->value] ?? 'background:#f0f0f0;color:#adb5bd') : 'background:#f0f0f0;color:#adb5bd';
                                    $triLabel = $mode ? $mode->trigramme() : '—';
                                @endphp
                                <td style="padding:4px 6px;vertical-align:middle;border-bottom:none;white-space:nowrap">
                                    <div class="d-flex align-items-center justify-content-center gap-1">
                                        @if($locked)
                                            <i class="bi bi-lock-fill" style="font-size:10px;color:#6c757d" title="Remise en banque effectuée"></i>
                                        @endif
                                        <span style="font-weight:600;font-size:11px;padding:2px 5px;border-radius:3px;{{ $triStyle }};{{ $locked ? '' : 'cursor:pointer;' }}"
                                              @if(!$locked) wire:click="cycleModePaiement({{ $participant->id }}, {{ $seance->id }})" @endif
                                              title="{{ $locked ? 'Verrouillé' : 'Clic pour changer' }}">{{ $triLabel }}</span>
                                        @if($locked)
                                            <span style="font-size:12px;font-variant-numeric:tabular-nums">{{ $montant }}</span>
                                        @else
                                            <span wire:key="montant-{{ $participant->id }}-{{ $seance->id }}-{{ $montant }}"
                                                  style="font-size:12px;font-variant-numeric:tabular-nums;border:1px solid transparent;border-radius:3px;padding:1px 4px;min-width:40px;display:inline-block;text-align:right"
                                                  x-data="{ editing: false, value: @js($montant) }"
                                                  @click="if(!editing){editing=true;$nextTick(()=>{$refs.input.focus();$refs.input.select()})}"
                                                  :style="!editing ? 'cursor:text' : ''">
                                                <template x-if="!editing">
                                                    <span x-text="value" style="display:inline-block;min-width:40px;text-align:right"></span>
                                                </template>
                                                <template x-if="editing">
                                                    <input type="text" x-ref="input" x-model="value"
                                                           @blur="editing=false; $wire.call('updateMontant', {{ $participant->id }}, {{ $seance->id }}, value)"
                                                           @keydown.enter="$refs.input.blur()"
                                                           @keydown.escape="editing=false"
                                                           style="width:55px;border:1px solid #0d6efd;border-radius:3px;padding:1px 4px;font-size:12px;text-align:right;outline:none">
                                                </template>
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            @endforeach
                            <td rowspan="2" style="text-align:center;vertical-align:middle;padding:4px 6px">
                                <div style="font-weight:600;font-size:12px">{{ number_format($prevuLigne, 2, ',', ' ') }}</div>
                                <div style="font-size:11px;color:{{ $realiseLigne > 0 ? '#198754' : '#6c757d' }}">{{ number_format($realiseLigne, 2, ',', ' ') }}</div>
                                @if(abs($ecartLigne) > 0.01)
                                    <div style="font-size:10px;color:{{ $ecartLigne < 0 ? '#dc3545' : '#198754' }}">{{ ($ecartLigne >= 0 ? '+' : '') . number_format($ecartLigne, 2, ',', ' ') }}</div>
                                @else
                                    <div style="font-size:10px;color:#6c757d">Écart 0</div>
                                @endif
                            </td>
                        </tr>
                        {{-- Row 2: Réalisé --}}
                        <tr>
                            @foreach($seances as $seance)
                                @php
                                    $key = $participant->id . '-' . $seance->id;
                                    $realise = $realiseMap[$key] ?? 0;
                                    $prevu = (float) ($reglementMap[$key]?->montant_prevu ?? 0);
                                    $color = $prevu == 0 && $realise == 0 ? '#6c757d' : ($realise >= $prevu && $prevu > 0 ? '#198754' : '#dc3545');
                                @endphp
                                <td style="padding:2px 6px;background:#f8f9fa;border-top:none;text-align:center">
                                    <span style="font-size:11px;color:{{ $color }}">
                                        {{ $realise > 0 ? number_format($realise, 2, ',', '') : ($prevu > 0 ? '0,00' : '—') }}
                                    </span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    {{-- Total prévu --}}
                    <tr style="background:#eef1f5;font-weight:600;font-size:12px">
                        <td style="position:sticky;left:0;z-index:1;background:#eef1f5;padding:6px 12px">Total prévu</td>
                        @foreach($seances as $seance)
                            <td style="text-align:center">{{ number_format($totalPrevuParSeance[$seance->id], 2, ',', ' ') }}</td>
                        @endforeach
                        <td style="text-align:center;font-weight:700">{{ number_format($grandTotalPrevu, 2, ',', ' ') }}</td>
                    </tr>
                    {{-- Total réalisé --}}
                    <tr style="background:#eef1f5;font-size:12px;color:#198754">
                        <td style="position:sticky;left:0;z-index:1;background:#eef1f5;padding:4px 12px">Total réalisé</td>
                        @foreach($seances as $seance)
                            <td style="text-align:center">{{ number_format($totalRealiseParSeance[$seance->id], 2, ',', ' ') }}</td>
                        @endforeach
                        <td style="text-align:center">{{ number_format($grandTotalRealise, 2, ',', ' ') }}</td>
                    </tr>
                    {{-- Écart --}}
                    @php $grandEcart = $grandTotalRealise - $grandTotalPrevu; @endphp
                    <tr style="background:#eef1f5;font-size:11px;color:#6c757d">
                        <td style="position:sticky;left:0;z-index:1;background:#eef1f5;padding:4px 12px">Écart</td>
                        @foreach($seances as $seance)
                            @php $ecart = ($totalRealiseParSeance[$seance->id]) - ($totalPrevuParSeance[$seance->id]); @endphp
                            <td style="text-align:center;{{ $ecart < -0.01 ? 'color:#dc3545' : '' }}">
                                {{ abs($ecart) > 0.01 ? (($ecart >= 0 ? '+' : '') . number_format($ecart, 2, ',', ' ')) : '0' }}
                            </td>
                        @endforeach
                        <td style="text-align:center;{{ $grandEcart < -0.01 ? 'color:#dc3545' : '' }}">
                            {{ abs($grandEcart) > 0.01 ? (($grandEcart >= 0 ? '+' : '') . number_format($grandEcart, 2, ',', ' ')) : '0' }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
