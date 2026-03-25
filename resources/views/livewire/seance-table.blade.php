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
            <table class="table table-sm table-bordered mb-0" style="font-size:12px">
                {{-- Header: Titles --}}
                <thead>
                    <tr style="background:#3d5473;color:#fff">
                        <td rowspan="3" style="position:sticky;left:0;z-index:2;background:#fff;vertical-align:middle;font-weight:600;color:#555;font-size:11px">Participants</td>
                        @foreach($seances as $seance)
                            <th style="min-width:170px;text-align:center;font-size:12px">
                                <div class="d-flex align-items-center justify-content-center gap-2">
                                    <span>S{{ $seance->numero }}</span>
                                    <button class="btn btn-sm p-0 text-white opacity-50"
                                            wire:click="removeSeance({{ $seance->id }})"
                                            wire:confirm="Supprimer la séance {{ $seance->numero }} et toutes ses présences ?"
                                            title="Supprimer">
                                        <i class="bi bi-x-lg" style="font-size:12px"></i>
                                    </button>
                                </div>
                            </th>
                        @endforeach
                    </tr>
                    {{-- Titre row --}}
                    <tr>
                        @foreach($seances as $seance)
                            <td style="background:#f8f9fa;text-align:center">
                                <input type="text" value="{{ $seance->titre }}"
                                       placeholder="Titre..."
                                       class="form-control form-control-sm border-0 bg-transparent text-center"
                                       style="font-size:12px;padding:2px 4px"
                                       onblur="@this.call('updateSeanceField', {{ $seance->id }}, 'titre', this.value)">
                            </td>
                        @endforeach
                    </tr>
                    {{-- Date row --}}
                    <tr>
                        @foreach($seances as $seance)
                            <td style="background:#f8f9fa;text-align:center" wire:ignore>
                                <div x-data="{
                                    fp: null,
                                    init() {
                                        this.fp = flatpickr(this.$refs.dateInput, {
                                            locale: 'fr',
                                            dateFormat: 'd/m/Y',
                                            allowInput: true,
                                            disableMobile: true,
                                            defaultDate: @js($seance->date?->format('d/m/Y')),
                                            parseDate(str) { return window.svsParseFlatpickrDate(str); },
                                            onChange: (dates) => {
                                                if (!dates.length) return;
                                                const d = dates[0];
                                                const iso = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
                                                $wire.call('updateSeanceField', {{ $seance->id }}, 'date', iso);
                                            }
                                        });
                                    },
                                    destroy() { if (this.fp) this.fp.destroy(); }
                                }">
                                    <input type="text" x-ref="dateInput"
                                           placeholder="jj/mm/aaaa"
                                           class="form-control form-control-sm border-0 bg-transparent text-center"
                                           style="font-size:12px;padding:2px 4px">
                                </div>
                            </td>
                        @endforeach
                    </tr>
                    {{-- Sub-header row: Présence / Kiné --}}
                    <tr>
                        <td style="position:sticky;left:0;z-index:1;background:#f0f0f0"></td>
                        @foreach($seances as $seance)
                            <td style="background:#f0f0f0;padding:2px 0;font-size:12px;color:#888">
                                <div class="d-flex">
                                    <span style="flex:1;text-align:center">Présence</span>
                                    <span style="width:40px;text-align:center;border-left:1px solid #ddd">Kiné</span>
                                </div>
                            </td>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($participants as $participant)
                        {{-- Ligne 1 : Présence + Kiné --}}
                        <tr>
                            <td rowspan="2" style="position:sticky;left:0;z-index:1;background:#fff;font-weight:500;white-space:nowrap;vertical-align:middle;font-size:11px">
                                {{ $participant->tiers->displayName() }}
                            </td>
                            @foreach($seances as $seance)
                                @php
                                    $key = $seance->id . '-' . $participant->id;
                                    $presence = $presenceMap[$key] ?? null;
                                    $statut = $presence?->statut ?? '';
                                    $kine = $presence?->kine ?? '';
                                    $commentaire = $presence?->commentaire ?? '';
                                    $kineBg = match($kine) {
                                        'oui' => '#d4edda',
                                        'non' => '#f8d7da',
                                        default => '#fff',
                                    };
                                @endphp
                                <td style="padding:0;vertical-align:middle;border-bottom:none">
                                    <div class="d-flex" style="min-height:28px">
                                        <div style="flex:1;padding:2px 4px">
                                            <select class="form-select form-select-sm border-0"
                                                    style="font-size:12px;padding:1px 2px;background-color:transparent"
                                                    onchange="@this.call('updatePresence', {{ $seance->id }}, {{ $participant->id }}, 'statut', this.value)">
                                                <option value="" {{ $statut === '' ? 'selected' : '' }}>—</option>
                                                @foreach($statuts as $s)
                                                    <option value="{{ $s->value }}" {{ $statut === $s->value ? 'selected' : '' }}>{{ $s->label() }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div style="width:40px;border-left:1px solid #dee2e6;background:{{ $kineBg }};cursor:pointer;display:flex;align-items:center;justify-content:center"
                                             onclick="
                                                var vals = ['', 'oui', 'non'];
                                                var cur = '{{ $kine }}';
                                                var next = vals[(vals.indexOf(cur) + 1) % vals.length];
                                                @this.call('updatePresence', {{ $seance->id }}, {{ $participant->id }}, 'kine', next);
                                             "
                                             title="{{ $kine === 'oui' ? 'Oui' : ($kine === 'non' ? 'Non' : 'Non renseigné') }} — clic pour changer">
                                            @if($kine === 'oui')
                                                <i class="bi bi-check-lg" style="color:#198754;font-size:14px"></i>
                                            @elseif($kine === 'non')
                                                <i class="bi bi-x-lg" style="color:#dc3545;font-size:12px"></i>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                        {{-- Ligne 2 : Commentaire --}}
                        <tr>
                            @foreach($seances as $seance)
                                @php
                                    $key = $seance->id . '-' . $participant->id;
                                    $presence = $presenceMap[$key] ?? null;
                                    $commentaire = $presence?->commentaire ?? '';
                                @endphp
                                <td style="padding:1px 4px;border-top:none"
                                    x-data="{ editing: false, value: @js($commentaire) }"
                                    @click="if(!editing){editing=true;$nextTick(()=>$refs.input.focus())}"
                                    class="small" style="cursor:pointer">
                                    <template x-if="!editing">
                                        <span style="font-size:12px;color:#888" x-text="value ? value.substring(0,30) + (value.length > 30 ? '...' : '') : '—'"></span>
                                    </template>
                                    <template x-if="editing">
                                        <input type="text" x-ref="input" x-model="value" maxlength="200"
                                               placeholder="Commentaire..."
                                               @blur="editing=false; @this.call('updatePresence', {{ $seance->id }}, {{ $participant->id }}, 'commentaire', value)"
                                               @keydown.enter="$refs.input.blur()"
                                               @keydown.escape="editing=false"
                                               class="form-control form-control-sm border-0" style="font-size:12px;padding:1px 4px;width:100%">
                                    </template>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                {{-- Footer: totals --}}
                <tfoot>
                    <tr style="background:#f0f0f0;font-weight:600;font-size:12px">
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
