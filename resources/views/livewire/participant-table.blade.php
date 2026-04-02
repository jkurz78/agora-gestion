<div>
    <style>
        .notes-preview-wrap { position: relative; display: inline-block; }
        .notes-preview-bubble {
            display: none;
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #fff;
            color: #333;
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid #ddd;
            font-size: 12px;
            line-height: 1.4;
            width: 280px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1050;
            box-shadow: 0 4px 16px rgba(0,0,0,.12);
            text-align: left;
        }
        .notes-preview-bubble::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: #ddd;
        }
        .notes-preview-wrap:hover .notes-preview-bubble { display: block; }
    </style>

    {{-- Toolbar --}}
    <div class="d-flex justify-content-end align-items-center mt-2 mb-3">
        <div class="d-flex gap-2">
            <div class="dropdown" x-data="{ sensible: false }">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-download"></i> Exporter
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="min-width:240px">
                    <li class="dropdown-header small text-muted">Excel</li>
                    <li>
                        <a class="dropdown-item" :href="'{{ route('gestion.operations.participants.export', $operation) }}' + (sensible ? '?confidentiel=1' : '')">
                            <i class="bi bi-file-earmark-spreadsheet me-2"></i>Télécharger .xlsx
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li class="dropdown-header small text-muted">PDF</li>
                    <li>
                        <a class="dropdown-item" target="_blank" :href="'{{ route('gestion.operations.participants.pdf', [$operation, 'format' => 'liste']) }}' + (sensible ? '&confidentiel=1' : '')">
                            <i class="bi bi-list-ul me-2"></i>Liste
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" target="_blank" :href="'{{ route('gestion.operations.participants.pdf', [$operation, 'format' => 'annuaire']) }}' + (sensible ? '&confidentiel=1' : '')">
                            <i class="bi bi-person-vcard me-2"></i>Annuaire
                        </a>
                    </li>
                    @if($canSeeSensible)
                        <li><hr class="dropdown-divider"></li>
                        <li class="px-3 py-1">
                            <div class="form-check mb-0">
                                <input type="checkbox" class="form-check-input" id="exportConfidentiel" x-model="sensible">
                                <label class="form-check-label small" for="exportConfidentiel">Données confidentielles</label>
                            </div>
                        </li>
                    @endif
                </ul>
            </div>
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
                    <th>Adhérent</th>
                    @if($operation->typeOperation?->tarifs->count())
                        <th class="sortable" data-col="tarif" style="cursor:pointer">Tarif <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    @endif
                    @if($canSeeSensible && $operation->typeOperation?->formulaire_parcours_therapeutique)
                        <th class="sortable" data-col="date_naissance" style="cursor:pointer">Date naissance <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                        <th class="sortable" data-col="age" style="cursor:pointer">Âge <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                        <th class="sortable" data-col="sexe" style="cursor:pointer">Sexe <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                        <th class="sortable" data-col="taille" style="cursor:pointer">Taille <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                        <th class="sortable" data-col="poids" style="cursor:pointer">Poids <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    @endif
                    <th class="sortable" data-col="refere_par" style="cursor:pointer">Référé par <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    @if($canSeeSensible && $operation->typeOperation?->formulaire_parcours_therapeutique)
                        <th>Notes</th>
                    @endif
                    @if($operation->typeOperation?->formulaire_actif)
                        <th class="text-center">Formulaire</th>
                    @endif
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody style="color:#555">
                @forelse($participants as $p)
                    <tr wire:key="participant-row-{{ $p->id }}-{{ $p->updated_at?->timestamp }}-{{ $p->tiers?->updated_at?->timestamp }}-{{ $p->donneesMedicales?->updated_at?->timestamp }}">
                        {{-- Nom --}}
                        <td class="small" data-sort="{{ $p->tiers->nom ?? '' }}">
                            <a href="{{ route('gestion.operations.participants.show', [$operation, $p]) }}" class="text-decoration-none fw-semibold">
                                {{ $p->tiers->nom ?? '—' }}
                            </a>
                        </td>

                        {{-- Prénom --}}
                        <td class="small" data-sort="{{ $p->tiers->prenom ?? '' }}">
                            <a href="{{ route('gestion.operations.participants.show', [$operation, $p]) }}" class="text-decoration-none fw-semibold">
                                {{ $p->tiers->prenom ?? '—' }}
                            </a>
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

                        {{-- Adhérent --}}
                        <td class="small">
                            @if($p->tiers && $this->isAdherent($p))
                                <span class="badge bg-success">Oui</span>
                            @elseif($operation->typeOperation?->reserve_adherents)
                                <span class="badge bg-danger">Non</span>
                            @endif
                        </td>

                        {{-- Tarif --}}
                        @if($operation->typeOperation?->tarifs->count())
                            <td class="small" data-sort="{{ $p->typeOperationTarif?->libelle ?? '' }}">
                                {{ $p->typeOperationTarif?->libelle ?? '—' }}
                            </td>
                        @endif

                        @if($canSeeSensible && $operation->typeOperation?->formulaire_parcours_therapeutique)
                            @php $med = $p->donneesMedicales; @endphp

                            {{-- Date naissance --}}
                            @php
                                $dateNais = $med?->date_naissance ?? '';
                                try {
                                    $dateNaisDisplay = $dateNais ? \Carbon\Carbon::parse($dateNais)->format('d/m/Y') : '';
                                } catch (\Throwable) {
                                    $dateNaisDisplay = $dateNais;
                                }
                            @endphp
                            <td x-data="{ editing: false, value: @js($dateNaisDisplay) }"
                                @click="if(!editing) { editing=true; $nextTick(()=>$refs.input.focus()) }"
                                data-sort="{{ $dateNais }}"
                                class="small text-nowrap" style="cursor:pointer">
                                <template x-if="!editing">
                                    <span x-text="value || '—'"></span>
                                </template>
                                <template x-if="editing">
                                    <input type="text" x-ref="input" x-model="value"
                                           placeholder="jj/mm/aaaa"
                                           @blur="editing=false;
                                               let parts = value.split('/');
                                               let iso = parts.length===3 ? parts[2]+'-'+parts[1]+'-'+parts[0] : value;
                                               $wire.updateMedicalField({{ $p->id }}, 'date_naissance', iso)"
                                           @keydown.enter="$refs.input.blur()"
                                           @keydown.escape="editing=false; value=@js($dateNaisDisplay)"
                                           class="form-control form-control-sm" style="min-width:100px">
                                </template>
                            </td>

                            {{-- Âge (calculé, non éditable) --}}
                            @php
                                $age = null;
                                if ($dateNais) {
                                    try { $age = \Carbon\Carbon::parse($dateNais)->age; } catch (\Throwable) {}
                                }
                            @endphp
                            <td class="small text-muted" data-sort="{{ $age ?? '' }}">
                                {{ $age !== null ? $age.' ans' : '—' }}
                            </td>

                            {{-- Sexe --}}
                            @php $sexe = $med?->sexe ?? ''; @endphp
                            <td x-data="{ editing: false, value: @js($sexe) }"
                                @click="if(!editing) { editing=true; $nextTick(()=>$refs.input.focus()) }"
                                data-sort="{{ $sexe }}"
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
                                data-sort="{{ $taille }}"
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
                                data-sort="{{ $poids }}"
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
                        <td class="small" data-sort="{{ $p->referePar?->displayName() ?? '' }}">
                            {{ $p->referePar?->displayName() ?? '—' }}
                        </td>

                        {{-- Notes icon --}}
                        @if($canSeeSensible && $operation->typeOperation?->formulaire_parcours_therapeutique)
                            <td class="small text-center" style="position:relative">
                                @php $hasNotes = $p->donneesMedicales?->notes; @endphp
                                <span class="notes-preview-wrap">
                                    <button class="btn btn-sm btn-link p-0 {{ $hasNotes ? 'text-primary' : 'text-muted' }}"
                                            wire:click="openNotesModal({{ $p->id }})">
                                        <i class="bi bi-journal-text"></i>
                                    </button>
                                    @if($hasNotes)
                                        <span class="notes-preview-bubble">{!! Str::limit($hasNotes, 300) !!}</span>
                                    @endif
                                </span>
                            </td>
                        @endif

                        {{-- Formulaire badge --}}
                        @if($operation->typeOperation?->formulaire_actif)
                        <td class="text-center small">
                            @if ($p->formulaireToken === null)
                                <button wire:click="genererToken({{ $p->id }})" class="btn btn-sm btn-outline-secondary" title="Générer un lien">
                                    <i class="bi bi-link-45deg"></i>
                                </button>
                            @elseif ($p->formulaireToken->isUtilise())
                                <span class="badge bg-success" title="Rempli le {{ $p->formulaireToken->rempli_at->format('d/m/Y') }}">
                                    <i class="bi bi-check-circle"></i> Rempli
                                </span>
                            @elseif ($p->formulaireToken->isExpire())
                                <span class="badge bg-secondary" role="button" wire:click="genererToken({{ $p->id }})" title="Expiré — cliquer pour regénérer">
                                    <i class="bi bi-clock-history"></i> Expiré
                                </span>
                            @else
                                <span class="badge bg-warning text-dark" role="button" wire:click="ouvrirToken({{ $p->id }})" title="En attente — cliquer pour voir le code">
                                    <i class="bi bi-hourglass"></i> En attente
                                </span>
                            @endif
                        </td>
                        @endif

                        {{-- Actions --}}
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <button class="btn btn-sm btn-outline-secondary"
                                        onclick="window.location='{{ route('gestion.operations.participants.show', [$operation, $p]) }}'"
                                        title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <a class="btn btn-sm btn-outline-info" href="{{ route('gestion.operations.participants.fiche-pdf', [$operation, $p]) }}" target="_blank" title="Fiche PDF">
                                    <i class="bi bi-file-person"></i>
                                </a>
                                @if($operation->typeOperation?->formulaire_droit_image && $p->droit_image)
                                <a class="btn btn-sm btn-outline-info" href="{{ route('gestion.operations.participants.droit-image-pdf', [$operation, $p]) }}" target="_blank" title="Autorisation photo">
                                    <i class="bi bi-camera"></i>
                                </a>
                                @endif
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
                        <td colspan="99" class="text-center text-muted py-4">
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

                @if($operation->typeOperation?->tarifs->count())
                    <div class="mb-3">
                        <label class="form-label">Tarif</label>
                        <select wire:model="addTypeOperationTarifId" class="form-select form-select-sm">
                            <option value="">— Aucun —</option>
                            @foreach($operation->typeOperation->tarifs as $tarif)
                                <option value="{{ $tarif->id }}">{{ $tarif->libelle }} — {{ number_format($tarif->montant, 2, ',', ' ') }} €</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showAddModal', false)">Annuler</button>
                    <button type="button" class="btn btn-sm btn-primary" wire:click="addParticipant">
                        <i class="bi bi-plus-lg me-1"></i> Ajouter
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Edit modal removed — participant editing now handled by ParticipantShow --}}
    {{-- ═══════════════════════════════════════════════════════════
         NOTES MODAL
         ═══════════════════════════════════════════════════════════ --}}
    @if($showNotesModal)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.4);z-index:2000"
             wire:click.self="$set('showNotesModal', false)">
            <div class="bg-white rounded p-4 shadow" style="width:750px;max-width:95vw;max-height:90vh;overflow-y:auto"
                 x-data x-init="$nextTick(() => $refs.notesArea.focus())">
                <h6 class="mb-3 text-muted">Notes sécurisées</h6>

                <div class="btn-group btn-group-sm mb-2">
                    <button type="button" class="btn btn-outline-secondary" title="Gras"
                            onclick="document.execCommand('bold')"><i class="bi bi-type-bold"></i></button>
                    <button type="button" class="btn btn-outline-secondary" title="Italique"
                            onclick="document.execCommand('italic')"><i class="bi bi-type-italic"></i></button>
                    <button type="button" class="btn btn-outline-secondary" title="Liste à puces"
                            onclick="document.execCommand('insertUnorderedList')"><i class="bi bi-list-ul"></i></button>
                    <button type="button" class="btn btn-outline-secondary" title="Liste numérotée"
                            onclick="document.execCommand('insertOrderedList')"><i class="bi bi-list-ol"></i></button>
                </div>

                <div x-ref="notesArea" contenteditable="true"
                     class="form-control" style="min-height:300px;overflow-y:auto"
                     wire:ignore>{!! $medNotes !!}</div>

                <div class="d-flex gap-2 justify-content-end mt-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showNotesModal', false)">Annuler</button>
                    <button type="button" class="btn btn-sm btn-primary"
                            x-on:click="$wire.set('medNotes', $refs.notesArea.innerHTML); $wire.call('saveNotes');">
                        <i class="bi bi-check-lg"></i> Enregistrer
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════
         TOKEN MODAL
         ═══════════════════════════════════════════════════════════ --}}
    @if($showTokenModal)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.4);z-index:2000"
             wire:click.self="$set('showTokenModal', false)">
            <div class="bg-white rounded p-4 shadow" style="width:520px;max-width:95vw"
                 x-data="{ copied: false }">
                <h5 class="fw-bold mb-3">Lien formulaire participant</h5>

                {{-- Token code --}}
                <div class="text-center mb-3" x-data="{ copiedCode: false }">
                    <span class="d-inline-block px-3 py-2 rounded bg-light border" style="font-size:1.6rem;font-family:monospace;letter-spacing:3px">
                        {{ $tokenCode }}
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2" title="Copier le code"
                            x-on:click="navigator.clipboard.writeText('{{ $tokenCode }}'); copiedCode = true; setTimeout(() => copiedCode = false, 1500)">
                        <template x-if="!copiedCode"><i class="bi bi-clipboard"></i></template>
                        <template x-if="copiedCode"><i class="bi bi-check-lg text-success"></i></template>
                    </button>
                </div>

                {{-- Full URL --}}
                <div class="mb-3">
                    <label class="form-label small text-muted">Lien complet</label>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control form-control-sm" value="{{ $tokenUrl }}" readonly id="tokenUrlField">
                        <button class="btn btn-outline-secondary" type="button"
                                x-on:click="navigator.clipboard.writeText(document.getElementById('tokenUrlField').value); copied = true; setTimeout(() => copied = false, 2000)">
                            <template x-if="!copied"><i class="bi bi-clipboard"></i></template>
                            <template x-if="copied"><i class="bi bi-check-lg text-success"></i></template>
                        </button>
                    </div>
                </div>

                {{-- Expiration date --}}
                <div class="mb-3">
                    <label class="form-label small text-muted">Date d'expiration</label>
                    <div class="input-group input-group-sm">
                        <input type="date" class="form-control form-control-sm" wire:model="tokenExpireAt">
                        <button class="btn btn-outline-primary" type="button" wire:click="genererTokenAvecDate" title="Regénérer avec cette date">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                </div>

                {{-- Email sending --}}
                @if($tokenEmailMessage)
                    <div class="alert alert-{{ $tokenEmailType }} py-1 small mt-3 mb-0">{{ $tokenEmailMessage }}</div>
                @endif

                <div class="d-flex gap-2 justify-content-end mt-3">
                    @php
                        $participantTiers = $tokenParticipantId ? \App\Models\Participant::find($tokenParticipantId)?->tiers : null;
                        $hasEmail = (bool) $participantTiers?->email;
                        $hasFromEmail = (bool) $operation->typeOperation?->email_from;
                        $canSendEmail = $hasEmail && $hasFromEmail;
                    @endphp
                    <button type="button"
                            class="btn btn-sm btn-outline-primary"
                            wire:click="envoyerTokenParEmail"
                            {{ $canSendEmail ? '' : 'disabled' }}
                            title="{{ !$hasFromEmail ? 'Email expéditeur non configuré sur le type d\'opération' : (!$hasEmail ? 'Pas d\'email sur la fiche du participant' : 'Envoyer le lien par email à ' . $participantTiers->email) }}">
                        <span wire:loading.remove wire:target="envoyerTokenParEmail"><i class="bi bi-envelope"></i> Envoyer par email</span>
                        <span wire:loading wire:target="envoyerTokenParEmail">Envoi...</span>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showTokenModal', false)">Fermer</button>
                </div>
            </div>
        </div>
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
