<div>
    {{-- Toolbar --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <span class="text-muted">{{ $participants->count() }} participants</span>
        <div class="d-flex gap-2">
            @if(Route::has('gestion.operations.participants.export'))
                <a href="{{ route('gestion.operations.participants.export', $operation) }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Exporter Excel
                </a>
            @endif
            <button class="btn btn-sm btn-primary" wire:click="openAddModal">
                <i class="bi bi-plus-lg"></i> Ajouter un participant
            </button>
        </div>
    </div>

    {{-- Table --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover" id="participant-table">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th class="sortable" data-col="nom" style="cursor:pointer">Nom <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th class="sortable" data-col="prenom" style="cursor:pointer">Prénom <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th>Téléphone</th>
                    <th>Email</th>
                    <th class="sortable" data-col="date_inscription" style="cursor:pointer">Date inscription <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    @if($canSeeSensible)
                        <th>Date naissance</th>
                        <th>Âge</th>
                        <th>Sexe</th>
                        <th>Taille</th>
                        <th>Poids</th>
                    @endif
                    <th>Référé par</th>
                    @if($canSeeSensible)
                        <th>Notes</th>
                    @endif
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody style="color:#555">
                @forelse($participants as $p)
                    <tr wire:key="participant-row-{{ $p->id }}-{{ $p->updated_at?->timestamp }}-{{ $p->tiers?->updated_at?->timestamp }}-{{ $p->donneesMedicales?->updated_at?->timestamp }}">
                        {{-- Nom --}}
                        <td x-data="{ editing: false, value: @js($p->tiers->nom ?? '') }"
                            @click="if(!editing) { editing=true; $nextTick(()=>$refs.input.focus()) }"
                            class="small" style="cursor:pointer"
                            data-sort="{{ $p->tiers->nom ?? '' }}">
                            <template x-if="!editing">
                                <span x-text="value || '—'"></span>
                            </template>
                            <template x-if="editing">
                                <input type="text" x-ref="input" x-model="value"
                                       @blur="editing=false; $wire.updateTiersField({{ $p->id }}, 'nom', value)"
                                       @keydown.enter="$refs.input.blur()"
                                       @keydown.escape="editing=false; value=@js($p->tiers->nom ?? '')"
                                       class="form-control form-control-sm" style="min-width:80px">
                            </template>
                        </td>

                        {{-- Prénom --}}
                        <td x-data="{ editing: false, value: @js($p->tiers->prenom ?? '') }"
                            @click="if(!editing) { editing=true; $nextTick(()=>$refs.input.focus()) }"
                            class="small" style="cursor:pointer"
                            data-sort="{{ $p->tiers->prenom ?? '' }}">
                            <template x-if="!editing">
                                <span x-text="value || '—'"></span>
                            </template>
                            <template x-if="editing">
                                <input type="text" x-ref="input" x-model="value"
                                       @blur="editing=false; $wire.updateTiersField({{ $p->id }}, 'prenom', value)"
                                       @keydown.enter="$refs.input.blur()"
                                       @keydown.escape="editing=false; value=@js($p->tiers->prenom ?? '')"
                                       class="form-control form-control-sm" style="min-width:80px">
                            </template>
                        </td>

                        {{-- Téléphone --}}
                        <td x-data="{ editing: false, value: @js($p->tiers->telephone ?? '') }"
                            @click="if(!editing) { editing=true; $nextTick(()=>$refs.input.focus()) }"
                            class="small" style="cursor:pointer">
                            <template x-if="!editing">
                                <span x-text="value || '—'"></span>
                            </template>
                            <template x-if="editing">
                                <input type="text" x-ref="input" x-model="value"
                                       @blur="editing=false; $wire.updateTiersField({{ $p->id }}, 'telephone', value)"
                                       @keydown.enter="$refs.input.blur()"
                                       @keydown.escape="editing=false; value=@js($p->tiers->telephone ?? '')"
                                       class="form-control form-control-sm" style="min-width:100px">
                            </template>
                        </td>

                        {{-- Email --}}
                        <td x-data="{ editing: false, value: @js($p->tiers->email ?? '') }"
                            @click="if(!editing) { editing=true; $nextTick(()=>$refs.input.focus()) }"
                            class="small" style="cursor:pointer">
                            <template x-if="!editing">
                                <span x-text="value || '—'"></span>
                            </template>
                            <template x-if="editing">
                                <input type="text" x-ref="input" x-model="value"
                                       @blur="editing=false; $wire.updateTiersField({{ $p->id }}, 'email', value)"
                                       @keydown.enter="$refs.input.blur()"
                                       @keydown.escape="editing=false; value=@js($p->tiers->email ?? '')"
                                       class="form-control form-control-sm" style="min-width:120px">
                            </template>
                        </td>

                        {{-- Date inscription --}}
                        @php $dateInscr = $p->date_inscription?->format('d/m/Y') ?? ''; @endphp
                        <td x-data="{ editing: false, value: @js($dateInscr) }"
                            @click="if(!editing) { editing=true; $nextTick(()=>$refs.input.focus()) }"
                            class="small text-nowrap" style="cursor:pointer"
                            data-sort="{{ $p->date_inscription?->format('Y-m-d') ?? '' }}">
                            <template x-if="!editing">
                                <span x-text="value || '—'"></span>
                            </template>
                            <template x-if="editing">
                                <input type="text" x-ref="input" x-model="value"
                                       placeholder="jj/mm/aaaa"
                                       @blur="editing=false;
                                           let parts = value.split('/');
                                           let iso = parts.length===3 ? parts[2]+'-'+parts[1]+'-'+parts[0] : value;
                                           $wire.updateParticipantField({{ $p->id }}, 'date_inscription', iso)"
                                       @keydown.enter="$refs.input.blur()"
                                       @keydown.escape="editing=false; value=@js($dateInscr)"
                                       class="form-control form-control-sm" style="min-width:100px">
                            </template>
                        </td>

                        @if($canSeeSensible)
                            @php $med = $p->donneesMedicales; @endphp

                            {{-- Date naissance --}}
                            @php
                                $dateNais = $med?->date_naissance ?? '';
                                $dateNaisDisplay = $dateNais ? \Carbon\Carbon::parse($dateNais)->format('d/m/Y') : '';
                            @endphp
                            <td x-data="{ editing: false, value: @js($dateNais), display: @js($dateNaisDisplay) }"
                                @click="if(!editing) { editing=true; $nextTick(()=>$refs.input.focus()) }"
                                class="small text-nowrap" style="cursor:pointer">
                                <template x-if="!editing">
                                    <span x-text="display || '—'"></span>
                                </template>
                                <template x-if="editing">
                                    <input type="text" x-ref="input" x-model="value"
                                           placeholder="jj/mm/aaaa"
                                           @blur="editing=false; $wire.updateMedicalField({{ $p->id }}, 'date_naissance', value)"
                                           @keydown.enter="$refs.input.blur()"
                                           @keydown.escape="editing=false; value=@js($dateNais)"
                                           class="form-control form-control-sm" style="min-width:100px">
                                </template>
                            </td>

                            {{-- Âge (calculé, non éditable) --}}
                            <td class="small text-muted">
                                @if($dateNais)
                                    @php
                                        try {
                                            $age = \Carbon\Carbon::parse($dateNais)->age;
                                        } catch (\Throwable) {
                                            $age = null;
                                        }
                                    @endphp
                                    {{ $age !== null ? $age.' ans' : '—' }}
                                @else
                                    —
                                @endif
                            </td>

                            {{-- Sexe --}}
                            @php $sexe = $med?->sexe ?? ''; @endphp
                            <td x-data="{ editing: false, value: @js($sexe) }"
                                @click="if(!editing) { editing=true; $nextTick(()=>$refs.input.focus()) }"
                                class="small" style="cursor:pointer">
                                <template x-if="!editing">
                                    <span x-text="value || '—'"></span>
                                </template>
                                <template x-if="editing">
                                    <select x-ref="input" x-model="value"
                                            @blur="editing=false; $wire.updateMedicalField({{ $p->id }}, 'sexe', value)"
                                            @change="$refs.input.blur()"
                                            class="form-select form-select-sm" style="min-width:60px">
                                        <option value="">—</option>
                                        <option value="F">F</option>
                                        <option value="M">M</option>
                                    </select>
                                </template>
                            </td>

                            {{-- Taille --}}
                            @php $taille = $med?->taille ?? ''; @endphp
                            <td x-data="{ editing: false, value: @js($taille) }"
                                @click="if(!editing) { editing=true; $nextTick(()=>$refs.input.focus()) }"
                                class="small" style="cursor:pointer">
                                <template x-if="!editing">
                                    <span x-text="value ? value + ' cm' : '—'"></span>
                                </template>
                                <template x-if="editing">
                                    <input type="text" x-ref="input" x-model="value"
                                           placeholder="cm"
                                           @blur="editing=false; $wire.updateMedicalField({{ $p->id }}, 'taille', value)"
                                           @keydown.enter="$refs.input.blur()"
                                           @keydown.escape="editing=false; value=@js($taille)"
                                           class="form-control form-control-sm" style="min-width:60px">
                                </template>
                            </td>

                            {{-- Poids --}}
                            @php $poids = $med?->poids ?? ''; @endphp
                            <td x-data="{ editing: false, value: @js($poids) }"
                                @click="if(!editing) { editing=true; $nextTick(()=>$refs.input.focus()) }"
                                class="small" style="cursor:pointer">
                                <template x-if="!editing">
                                    <span x-text="value ? value + ' kg' : '—'"></span>
                                </template>
                                <template x-if="editing">
                                    <input type="text" x-ref="input" x-model="value"
                                           placeholder="kg"
                                           @blur="editing=false; $wire.updateMedicalField({{ $p->id }}, 'poids', value)"
                                           @keydown.enter="$refs.input.blur()"
                                           @keydown.escape="editing=false; value=@js($poids)"
                                           class="form-control form-control-sm" style="min-width:60px">
                                </template>
                            </td>
                        @endif

                        {{-- Référé par --}}
                        <td class="small">
                            {{ $p->referePar?->displayName() ?? '—' }}
                        </td>

                        {{-- Notes icon --}}
                        @if($canSeeSensible)
                            <td class="small text-center">
                                @php $hasNotes = $p->donneesMedicales?->notes; @endphp
                                <button class="btn btn-sm btn-link p-0 {{ $hasNotes ? 'text-primary' : 'text-muted' }}"
                                        wire:click="openNotesModal({{ $p->id }})"
                                        title="{{ $hasNotes ? Str::limit(strip_tags($hasNotes), 60) : 'Ajouter des notes' }}">
                                    <i class="bi bi-journal-text"></i>
                                </button>
                            </td>
                        @endif

                        {{-- Actions --}}
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <button class="btn btn-sm btn-outline-secondary"
                                        wire:click="openEditModal({{ $p->id }})"
                                        title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger"
                                        wire:click="removeParticipant({{ $p->id }})"
                                        wire:confirm="Supprimer ce participant ?"
                                        title="Supprimer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canSeeSensible ? 13 : 7 }}" class="text-center text-muted py-4">
                            Aucun participant inscrit.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ═══════════════════════════════════════════════════════════
         ADD MODAL
         ═══════════════════════════════════════════════════════════ --}}
    @if($showAddModal)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.4);z-index:2000"
             wire:click.self="$set('showAddModal', false)">
            <div class="bg-white rounded p-4 shadow" style="width:480px;max-width:95vw">
                <h5 class="fw-bold mb-3">Ajouter un participant</h5>
                <p class="text-muted small mb-3">Recherchez un tiers existant ou créez-en un nouveau.</p>

                <div class="mb-3">
                    <livewire:tiers-autocomplete wire:model="addTiersId" filtre="tous" typeFiltre="particulier" context="participant" :key="'add-tiers-ac'" />
                    @error('addTiersId') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="d-flex justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showAddModal', false)">Annuler</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════
         EDIT MODAL
         ═══════════════════════════════════════════════════════════ --}}
    @if($showEditModal)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.4);z-index:2000"
             wire:click.self="$set('showEditModal', false)">
            <div class="bg-white rounded p-4 shadow" style="width:620px;max-width:95vw;max-height:90vh;overflow-y:auto">
                <h5 class="fw-bold mb-3">Modifier le participant</h5>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Nom</label>
                        <input type="text" wire:model="editNom" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Prénom</label>
                        <input type="text" wire:model="editPrenom" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Adresse</label>
                    <input type="text" wire:model="editAdresse" class="form-control form-control-sm">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Code postal</label>
                        <input type="text" wire:model="editCodePostal" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small">Ville</label>
                        <input type="text" wire:model="editVille" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Téléphone</label>
                        <input type="text" wire:model="editTelephone" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Email</label>
                        <input type="text" wire:model="editEmail" class="form-control form-control-sm">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Date d'inscription</label>
                    <x-date-input name="editDateInscription" :value="$editDateInscription" wire:model="editDateInscription" />
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Référé par</label>
                    <livewire:tiers-autocomplete wire:model="editReferePar" filtre="tous" :key="'edit-refere-par-'.$editParticipantId" />
                </div>

                @if($canSeeSensible)
                    <hr>
                    <h6 class="fw-bold text-muted mb-3">Données sécurisées</h6>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small">Date naissance</label>
                            <x-date-input name="editDateNaissance" :value="$editDateNaissance" wire:model="editDateNaissance" />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Sexe</label>
                            <select wire:model="editSexe" class="form-select form-select-sm">
                                <option value="">—</option>
                                <option value="F">F</option>
                                <option value="M">M</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Taille (cm)</label>
                            <input type="text" wire:model="editTaille" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Poids (kg)</label>
                            <input type="text" wire:model="editPoids" class="form-control form-control-sm">
                        </div>
                    </div>
                @endif

                <div class="d-flex gap-2 justify-content-end mt-4">
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showEditModal', false)">Annuler</button>
                    <button type="button" class="btn btn-sm btn-primary" wire:click="saveEdit">
                        <i class="bi bi-check-lg"></i> Enregistrer
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════
         NOTES MODAL
         ═══════════════════════════════════════════════════════════ --}}
    @if($showNotesModal)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.4);z-index:2000"
             wire:click.self="$set('showNotesModal', false)">
            <div class="bg-white rounded p-4 shadow" style="width:750px;max-width:95vw;max-height:90vh;overflow-y:auto">
                <h6 class="mb-3 text-muted">Notes sécurisées</h6>

                <div wire:ignore>
                    <div id="quill-notes-editor" style="min-height:300px"></div>
                </div>
                <input type="hidden" id="quill-notes-hidden" value="{{ $medNotes }}">

                <div class="d-flex gap-2 justify-content-end mt-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showNotesModal', false)">Annuler</button>
                    <button type="button" class="btn btn-sm btn-primary" onclick="
                        var hidden = document.getElementById('quill-notes-hidden');
                        if (window._quillNotesInstance) {
                            hidden.value = window._quillNotesInstance.root.innerHTML;
                        }
                        @this.set('medNotes', hidden.value);
                        @this.call('saveNotes');
                    ">
                        <i class="bi bi-check-lg"></i> Enregistrer
                    </button>
                </div>
            </div>
        </div>
        <script>
            (function initNotesQuill() {
                if (typeof Quill === 'undefined') {
                    setTimeout(initNotesQuill, 100);
                    return;
                }
                var el = document.getElementById('quill-notes-editor');
                if (!el || window._quillNotesInstance) return;
                window._quillNotesInstance = new Quill(el, {
                    theme: 'snow',
                    placeholder: 'Saisissez vos notes ici…',
                    modules: {
                        toolbar: [['bold', 'italic'], [{ list: 'bullet' }, { list: 'ordered' }]]
                    }
                });
                var initial = document.getElementById('quill-notes-hidden').value;
                if (initial) window._quillNotesInstance.root.innerHTML = initial;
                window._quillNotesInstance.focus();
            })();
        </script>
    @endif

    {{-- ═══════════════════════════════════════════════════════════
         JS SORTING
         ═══════════════════════════════════════════════════════════ --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const table = document.getElementById('participant-table');
            if (!table) return;

            const headers = table.querySelectorAll('th.sortable');
            let currentCol = null;
            let ascending = true;

            headers.forEach(function (th) {
                th.addEventListener('click', function () {
                    const col = th.dataset.col;
                    if (currentCol === col) {
                        ascending = !ascending;
                    } else {
                        currentCol = col;
                        ascending = true;
                    }

                    const tbody = table.querySelector('tbody');
                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    const colIndex = Array.from(th.parentElement.children).indexOf(th);

                    rows.sort(function (a, b) {
                        const aCell = a.children[colIndex];
                        const bCell = b.children[colIndex];
                        if (!aCell || !bCell) return 0;

                        const aVal = (aCell.dataset.sort || aCell.textContent || '').trim().toLowerCase();
                        const bVal = (bCell.dataset.sort || bCell.textContent || '').trim().toLowerCase();

                        const result = aVal.localeCompare(bVal, 'fr');
                        return ascending ? result : -result;
                    });

                    rows.forEach(function (row) { tbody.appendChild(row); });

                    // Update sort indicators
                    headers.forEach(function (h) {
                        const icon = h.querySelector('i');
                        if (icon) icon.className = 'bi bi-arrow-down-up';
                    });
                    const icon = th.querySelector('i');
                    if (icon) {
                        icon.className = ascending ? 'bi bi-arrow-down' : 'bi bi-arrow-up';
                    }
                });
            });
        });
    </script>
</div>
