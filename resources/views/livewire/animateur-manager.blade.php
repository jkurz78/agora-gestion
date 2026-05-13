<div class="mt-2" style="max-width:100%">
    @php
        $fmt = fn(float $v): string => number_format($v, 2, ',', "\u{202F}");
        $animateurList = $matrixData['animateurs'];
        $seanceTotPrev = $matrixData['seancePrevuTotaux'];
        $seanceTotReal = $matrixData['seanceRealiseTotaux'];
        $grandPrev = $matrixData['grandPrevu'];
        $grandReal = $matrixData['grandRealise'];
        $orphans = $matrixData['orphanRealiseHorsSeance'];
        $colCount = $seances->count();
    @endphp

    <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0" style="font-size:12px;table-layout:fixed;width:{{ 200 + ($colCount * 110) + 110 }}px">
            <colgroup>
                <col style="width:200px">
                @for($i = 0; $i < $colCount; $i++)<col style="width:110px">@endfor
                <col style="width:110px">
            </colgroup>
            <thead>
                <tr style="background:#3d5473;color:#fff">
                    <th style="position:sticky;left:0;z-index:2;background:#3d5473;font-size:11px;min-width:200px;text-align:right;font-weight:normal;font-style:italic;color:#b0c4de">Séance →</th>
                    @foreach($seances as $seance)
                        <th style="text-align:center;font-size:12px">S{{ $seance->numero }}</th>
                    @endforeach
                    <th style="text-align:center;font-size:12px">Total</th>
                </tr>
                <tr>
                    <td style="background:#f8f9fa;font-size:11px;font-weight:600;color:#495057">Encadrant ↓</td>
                    @foreach($seances as $seance)
                        <td style="background:#f8f9fa;text-align:center;font-size:10px;color:#6c757d">
                            @if($seance->titre_affiche){{ $seance->titre_affiche }}@endif
                            @if($seance->date) {{ $seance->date->format('d/m') }}@endif
                        </td>
                    @endforeach
                    <td style="background:#f8f9fa"></td>
                </tr>
            </thead>
            <tbody>
                @forelse($animateurList as $tiersId => $anim)
                    {{-- Ligne parent : nom + totaux Prévu --}}
                    <tr style="background:#eef1f5">
                        <td rowspan="2" style="position:sticky;left:0;z-index:1;background:#eef1f5;font-weight:600;padding:4px 8px;white-space:nowrap;font-size:12px;vertical-align:middle">
                            {{ $anim['tiersName'] }}
                            @if($anim['totalRealise'] == 0.0 && $this->canEdit)
                                <button class="btn btn-sm p-0 ms-1" style="color:#b00;font-size:10px;line-height:1;border:none;background:none"
                                        wire:click="supprimerEncadrant({{ $tiersId }})"
                                        wire:confirm="Supprimer cet encadrant et toutes ses prévisions ?"
                                        title="Supprimer l'encadrant">✕</button>
                            @endif
                        </td>
                        @foreach($seances as $seance)
                            @php $p = $anim['totalPrevuParSeance'][$seance->id] ?? 0; @endphp
                            <td style="text-align:center;font-weight:600;padding:2px 6px;background:#eef1f5;color:#6c757d">
                                {{ $p > 0 ? $fmt($p) : '—' }}
                            </td>
                        @endforeach
                        <td style="text-align:center;font-weight:700;padding:2px 6px;background:#eef1f5;color:#6c757d">{{ $fmt($anim['totalPrevu']) }}</td>
                    </tr>
                    {{-- Ligne parent : totaux Réalisé --}}
                    <tr style="background:#f4f7fa">
                        @foreach($seances as $seance)
                            @php $r = $anim['totalRealiseParSeance'][$seance->id] ?? 0; @endphp
                            <td style="text-align:center;padding:2px 6px;color:#2E7D32;font-weight:600;font-size:11px">
                                {{ $r > 0 ? $fmt($r) : '—' }}
                            </td>
                        @endforeach
                        <td style="text-align:center;padding:2px 6px;color:#2E7D32;font-weight:700;font-size:11px">{{ $fmt($anim['totalRealise']) }}</td>
                    </tr>

                    {{-- Sous-lignes par sous-catégorie : 2 rangées (Prévu / Réalisé) --}}
                    @foreach($anim['sousCategories'] as $scId => $sc)
                        <tr>
                            <td rowspan="2" style="position:sticky;left:0;z-index:1;background:#fff;padding:2px 8px 2px 20px;font-size:11px;color:#6c757d;white-space:nowrap;vertical-align:middle">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span>{{ $sc['scName'] }}</span>
                                    @if($this->canEdit)
                                        <div class="d-flex gap-1">
                                            <button class="btn btn-sm p-0" style="color:#0d6efd;font-size:10px;border:1px solid #0d6efd;border-radius:3px;padding:0 3px !important;line-height:1.3"
                                                    wire:click="recopierLigne({{ $tiersId }}, {{ $scId }})"
                                                    title="Recopier la 1re séance sur toute la ligne">→</button>
                                            @if(! $sc['hasRealise'])
                                                <button class="btn btn-sm p-0" style="color:#b00;font-size:11px;line-height:1;border:none;background:none"
                                                        wire:click="supprimerLigne({{ $tiersId }}, {{ $scId }})"
                                                        wire:confirm="Supprimer cette ligne ?"
                                                        title="Supprimer la ligne">✕</button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </td>
                            @foreach($seances as $seance)
                                @php
                                    $prevu = $sc['prevuParSeance'][$seance->id] ?? 0;
                                    $val = number_format($prevu, 2, ',', '');
                                @endphp
                                <td style="text-align:center;padding:2px 4px;font-size:11px;color:#6c757d">
                                    <span wire:key="prev-{{ $tiersId }}-{{ $scId }}-{{ $seance->id }}-{{ $val }}"
                                          x-data="{ editing: false, value: @js($val) }"
                                          @if($this->canEdit) @click="if(!editing){editing=true;$nextTick(()=>{$refs.input.focus();$refs.input.select()})}" :style="!editing ? 'cursor:text' : ''" @endif>
                                        <template x-if="!editing">
                                            <span x-text="value || '—'"></span>
                                        </template>
                                        <template x-if="editing">
                                            <input type="text" x-ref="input" x-model="value"
                                                   @blur="editing=false; $wire.call('updateMontantPrevu', {{ $tiersId }}, {{ $scId }}, {{ $seance->id }}, value)"
                                                   @keydown.enter="$refs.input.blur()"
                                                   @keydown.escape="editing=false"
                                                   style="width:55px;border:1px solid #0d6efd;border-radius:3px;padding:1px 4px;font-size:11px;text-align:right;outline:none">
                                        </template>
                                    </span>
                                </td>
                            @endforeach
                            <td style="text-align:center;padding:2px 6px;font-size:11px;font-weight:500;color:#6c757d">{{ $fmt($sc['totalPrevu']) }}</td>
                        </tr>
                        <tr>
                            @foreach($seances as $seance)
                                @php
                                    $realise = $sc['realiseParSeance'][$seance->id] ?? 0;
                                    $txIds = $sc['transactionIdsParSeance'][$seance->id] ?? [];
                                    $pieces = $sc['numeroPiecesParSeance'][$seance->id] ?? [];
                                @endphp
                                <td style="text-align:center;padding:2px 4px;font-size:11px;background:#f8f9fa">
                                    @if($realise > 0)
                                        <span style="cursor:pointer;text-decoration:underline dotted;color:#2E7D32;font-weight:600"
                                              wire:click="openEditModal(@js($txIds))"
                                              title="Modifier la transaction">
                                            {{ $fmt($realise) }}
                                        </span>
                                        @if(!empty($pieces))
                                            <div style="font-size:9px;color:#999;line-height:1.1">
                                                @foreach($pieces as $np){{ $np }}@if(!$loop->last)<br>@endif @endforeach
                                            </div>
                                        @endif
                                    @else
                                        @if($this->canEdit)
                                            <button class="btn btn-sm p-0" style="color:#198754;font-size:14px;line-height:1;border:none;background:none"
                                                    wire:click="openCreateModal({{ $tiersId }}, {{ $scId }}, {{ $seance->numero }})"
                                                    title="Saisir le réalisé">&#8853;</button>
                                        @endif
                                    @endif
                                </td>
                            @endforeach
                            <td style="text-align:center;padding:2px 6px;font-size:11px;color:#2E7D32;font-weight:600">{{ $fmt($sc['totalRealise']) }}</td>
                        </tr>
                    @endforeach

                    {{-- Bouton + Ajouter une ligne sous-catégorie --}}
                    @if($this->canEdit)
                    <tr>
                        <td colspan="{{ $colCount + 2 }}" style="padding:2px 8px 6px 20px;font-size:11px">
                            @if($addingScForTiersId === $tiersId)
                                <div x-data="{ scId: null }" class="d-flex gap-2 align-items-center">
                                    <select x-model.number="scId" class="form-select form-select-sm" style="max-width:240px;font-size:11px">
                                        <option :value="null">Choisir une sous-catégorie…</option>
                                        @foreach($sousCategoriesDepense as $sc)
                                            @if(! isset($anim['sousCategories'][$sc['id']]))
                                                <option value="{{ $sc['id'] }}">{{ $sc['nom'] }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    <button class="btn btn-sm btn-primary" style="font-size:11px"
                                            @click="if(scId) { $wire.call('ajouterLigneSousCategorie', {{ $tiersId }}, scId) }"
                                            :disabled="!scId">Ajouter</button>
                                    <button class="btn btn-sm btn-outline-secondary" style="font-size:11px"
                                            wire:click="fermerAjoutLigne">Annuler</button>
                                </div>
                            @else
                                <button class="btn btn-sm" style="color:#0d6efd;font-size:11px;border:1px dashed #0d6efd;background:none"
                                        wire:click="ouvrirAjoutLigne({{ $tiersId }})">+ Ajouter une ligne</button>
                            @endif
                        </td>
                    </tr>
                    @endif

                    @if(($orphans[$tiersId] ?? 0) > 0)
                        <tr>
                            <td colspan="{{ $colCount + 2 }}" style="padding:2px 8px 4px 20px;font-size:10px;color:#888;background:#fffdf0">
                                <i class="bi bi-info-circle me-1"></i>Réalisé sans séance affectée : {{ $fmt($orphans[$tiersId]) }} € (inclus dans le total)
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="{{ $colCount + 2 }}" class="text-center text-muted py-3" style="font-size:12px">
                            Ajoutez un encadrant ci-dessous pour commencer le suivi.
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr style="background:#eef1f5;font-weight:600;font-size:11px">
                    <td style="position:sticky;left:0;z-index:1;background:#eef1f5;padding:4px 8px">Total prévu</td>
                    @foreach($seances as $seance)
                        <td style="text-align:center">{{ $fmt($seanceTotPrev[$seance->id] ?? 0) }}</td>
                    @endforeach
                    <td style="text-align:center;font-weight:700">{{ $fmt($grandPrev) }}</td>
                </tr>
                <tr style="background:#f4f7fa;font-size:11px;color:#2E7D32;font-weight:600">
                    <td style="position:sticky;left:0;z-index:1;background:#f4f7fa;padding:4px 8px">Total réalisé</td>
                    @foreach($seances as $seance)
                        <td style="text-align:center">{{ $fmt($seanceTotReal[$seance->id] ?? 0) }}</td>
                    @endforeach
                    <td style="text-align:center;font-weight:700">{{ $fmt($grandReal) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- Ajouter un encadrant : 2 étapes (sélection tiers puis sous-catégorie) --}}
    <div class="mt-3 p-3 border rounded" style="max-width:520px;background:#fafafa">
        <label class="form-label fw-medium" style="font-size:13px">
            <i class="bi bi-plus-circle me-1"></i>Ajouter un encadrant
        </label>
        @if($newTiersIdEnCours === null)
            <livewire:tiers-autocomplete wire:model="newTiersId" filtre="depenses" :key="'anim-tiers-'.$operation->id" />
        @else
            <div x-data="{ scId: null }" class="d-flex gap-2 align-items-center">
                <div class="text-muted small">Sous-catégorie :</div>
                <select x-model.number="scId" class="form-select form-select-sm" style="max-width:240px;font-size:12px" x-init="$el.focus()">
                    <option :value="null">Choisir…</option>
                    @foreach($sousCategoriesDepense as $sc)
                        <option value="{{ $sc['id'] }}">{{ $sc['nom'] }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-primary"
                        @click="if(scId) { $wire.call('ajouterEncadrantAvecSousCategorie', {{ $newTiersIdEnCours }}, scId) }"
                        :disabled="!scId">Ajouter</button>
                <button class="btn btn-sm btn-outline-secondary"
                        wire:click="annulerAjoutEncadrant">Annuler</button>
            </div>
        @endif
    </div>

    {{-- Modal OCR (inchangée) --}}
    @include('livewire.animateur-manager-modal')
</div>
