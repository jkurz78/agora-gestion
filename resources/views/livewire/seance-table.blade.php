<div>
    {{-- Toolbar --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted">{{ $seances->count() }} séances</span>
            <div class="form-check form-switch mb-0">
                <input type="checkbox" class="form-check-input" id="toggleProches" wire:model.live="showProches">
                <label class="form-check-label small" for="toggleProches">Séances proches</label>
            </div>
        </div>
        <button class="btn btn-sm btn-primary" wire:click="addSeance">
            <i class="bi bi-plus-lg"></i> Ajouter une séance
        </button>
    </div>

    @if($seances->isEmpty())
        <div class="text-center text-muted py-4">
            <i class="bi bi-calendar-week" style="font-size:2rem;opacity:0.3"></i>
            <p class="mt-2">Aucune séance. Cliquez sur "Ajouter une séance" pour commencer.</p>
        </div>
    @else
        <div style="overflow-x:auto">
            <table class="table table-sm table-bordered mb-0" style="font-size:10px;min-width:{{ 200 + ($seances->count() * 180) }}px">
                {{-- Header: Titles --}}
                <thead>
                    <tr style="background:#3d5473;color:#fff">
                        <th style="position:sticky;left:0;z-index:2;background:#3d5473;min-width:150px">Participant</th>
                        @foreach($seances as $seance)
                            <th style="min-width:170px;text-align:center">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span class="opacity-75">S{{ $seance->numero }}</span>
                                    <button class="btn btn-sm p-0 text-white opacity-50"
                                            wire:click="removeSeance({{ $seance->id }})"
                                            wire:confirm="Supprimer la séance {{ $seance->numero }} et toutes ses présences ?"
                                            title="Supprimer">
                                        <i class="bi bi-x-lg" style="font-size:10px"></i>
                                    </button>
                                </div>
                            </th>
                        @endforeach
                    </tr>
                    {{-- Titre row --}}
                    <tr>
                        <td style="position:sticky;left:0;z-index:1;background:#f8f9fa;font-weight:600;font-size:9px;color:#888">Titre</td>
                        @foreach($seances as $seance)
                            <td style="background:#f8f9fa">
                                <input type="text" value="{{ $seance->titre }}"
                                       placeholder="Titre..."
                                       class="form-control form-control-sm border-0 bg-transparent"
                                       style="font-size:10px;padding:1px 4px"
                                       onblur="@this.call('updateSeanceField', {{ $seance->id }}, 'titre', this.value)">
                            </td>
                        @endforeach
                    </tr>
                    {{-- Date row --}}
                    <tr>
                        <td style="position:sticky;left:0;z-index:1;background:#f8f9fa;font-weight:600;font-size:9px;color:#888">Date</td>
                        @foreach($seances as $seance)
                            <td style="background:#f8f9fa">
                                <input type="text" value="{{ $seance->date?->format('d/m/Y') }}"
                                       placeholder="jj/mm/aaaa"
                                       class="form-control form-control-sm border-0 bg-transparent"
                                       style="font-size:10px;padding:1px 4px"
                                       onblur="@this.call('updateSeanceField', {{ $seance->id }}, 'date', this.value)">
                            </td>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($participants as $participant)
                        <tr>
                            <td style="position:sticky;left:0;z-index:1;background:#fff;font-weight:500;white-space:nowrap">
                                {{ $participant->tiers->displayName() }}
                            </td>
                            @foreach($seances as $seance)
                                @php
                                    $key = $seance->id . '-' . $participant->id;
                                    $presence = $presenceMap[$key] ?? null;
                                    $statut = $presence?->statut ?? '';
                                    $kine = $presence?->kine ?? '';
                                    $commentaire = $presence?->commentaire ?? '';
                                @endphp
                                <td style="vertical-align:middle;padding:2px 4px">
                                    <div class="d-flex align-items-center gap-1 flex-nowrap">
                                        <select class="form-select form-select-sm border-0"
                                                style="font-size:10px;padding:1px 2px;min-width:90px;background-color:transparent"
                                                onchange="@this.call('updatePresence', {{ $seance->id }}, {{ $participant->id }}, 'statut', this.value)">
                                            <option value="" {{ $statut === '' ? 'selected' : '' }}>—</option>
                                            @foreach($statuts as $s)
                                                <option value="{{ $s->value }}" {{ $statut === $s->value ? 'selected' : '' }}>{{ $s->label() }}</option>
                                            @endforeach
                                        </select>
                                        <label class="form-check mb-0 d-flex align-items-center" style="font-size:9px;white-space:nowrap;cursor:pointer" title="Kiné">
                                            <input type="checkbox" class="form-check-input me-0"
                                                   style="width:14px;height:14px"
                                                   {{ $kine === '1' ? 'checked' : '' }}
                                                   onchange="@this.call('updatePresence', {{ $seance->id }}, {{ $participant->id }}, 'kine', this.checked ? '1' : '0')">
                                            <span class="ms-1">K</span>
                                        </label>
                                    </div>
                                    <div x-data="{ editing: false, value: @js($commentaire) }"
                                         @click="if(!editing){editing=true;$nextTick(()=>$refs.input.focus())}"
                                         style="cursor:pointer;margin-top:1px">
                                        <template x-if="!editing">
                                            <span style="font-size:9px;color:#888" x-text="value ? value.substring(0,25) + (value.length > 25 ? '...' : '') : '—'"></span>
                                        </template>
                                        <template x-if="editing">
                                            <input type="text" x-ref="input" x-model="value" maxlength="200"
                                                   placeholder="Commentaire..."
                                                   @blur="editing=false; @this.call('updatePresence', {{ $seance->id }}, {{ $participant->id }}, 'commentaire', value)"
                                                   @keydown.enter="$refs.input.blur()"
                                                   @keydown.escape="editing=false"
                                                   class="form-control form-control-sm border-0" style="font-size:9px;padding:1px 4px">
                                        </template>
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                {{-- Footer: totals --}}
                <tfoot>
                    <tr style="background:#f0f0f0;font-weight:600;font-size:9px">
                        <td style="position:sticky;left:0;z-index:1;background:#f0f0f0">Présents</td>
                        @foreach($seances as $seance)
                            @php
                                $total = $participants->count();
                                $presents = 0;
                                foreach ($participants as $p) {
                                    $k = $seance->id . '-' . $p->id;
                                    if (isset($presenceMap[$k]) && $presenceMap[$k]->statut === 'present') {
                                        $presents++;
                                    }
                                }
                            @endphp
                            <td style="text-align:center">{{ $presents }} / {{ $total }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
