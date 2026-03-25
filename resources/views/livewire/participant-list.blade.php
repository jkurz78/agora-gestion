<div>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Participants</h5>
            <button wire:click="openAddModal" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> Ajouter
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover mb-0">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Participant</th>
                            <th>Date inscription</th>
                            @if($canSeeSensible)
                                <th>Date naissance</th>
                                <th>Sexe</th>
                                <th>Poids</th>
                            @endif
                            <th>Notes</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody style="color:#555">
                        @forelse($participants as $participant)
                            <tr>
                                <td class="small">
                                    {{ $participant->tiers->displayName() }}
                                    @if($participant->est_helloasso)
                                        <span class="badge bg-info ms-1" style="font-size:.65rem">HelloAsso</span>
                                    @endif
                                </td>
                                <td class="small" data-sort="{{ $participant->date_inscription->format('Y-m-d') }}">
                                    {{ $participant->date_inscription->format('d/m/Y') }}
                                </td>
                                @if($canSeeSensible)
                                    @php $med = $participant->donneesMedicales; @endphp
                                    <td class="small">
                                        @if($med?->date_naissance)
                                            {{ \Carbon\Carbon::parse($med->date_naissance)->format('d/m/Y') }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="small">{{ $med?->sexe ?? '—' }}</td>
                                    <td class="small">
                                        @if($med?->poids)
                                            {{ $med->poids }} kg
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="small text-muted">{{ $participant->notes ?? '—' }}</td>
                                <td class="text-end">
                                    <div class="d-flex gap-1 justify-content-end">
                                        @if($canSeeSensible)
                                            <button wire:click="openMedicalModal({{ $participant->id }})"
                                                    class="btn btn-sm btn-outline-secondary"
                                                    title="Données médicales">
                                                <i class="bi bi-heart-pulse"></i>
                                            </button>
                                        @endif
                                        <button wire:click="removeParticipant({{ $participant->id }})"
                                                wire:confirm="Êtes-vous sûr de vouloir retirer ce participant ?"
                                                class="btn btn-sm btn-outline-danger"
                                                title="Retirer">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canSeeSensible ? 7 : 4 }}" class="text-center text-muted py-4">
                                    Aucun participant inscrit.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Modal ajout participant --}}
    @if($showAddModal)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.5);z-index:1040"
             wire:click.self="$set('showAddModal', false)">
            <div class="bg-white rounded p-4" style="width:500px;max-width:95vw">
                <h6 class="fw-bold mb-3">Ajouter un participant</h6>

                <form wire:submit="addParticipant">
                    <div class="mb-3">
                        <label class="form-label">Participant <span class="text-danger">*</span></label>
                        <livewire:tiers-autocomplete wire:model="selectedTiersId" filtre="tous" typeFiltre="particulier" />
                        @error('selectedTiersId')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="dateInscription" class="form-label">Date d'inscription <span class="text-danger">*</span></label>
                        <input type="date" wire:model="dateInscription" id="dateInscription"
                               class="form-control @error('dateInscription') is-invalid @enderror">
                        @error('dateInscription')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea wire:model="notes" id="notes" class="form-control" rows="2"
                                  placeholder="Notes facultatives..."></textarea>
                        @error('notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                wire:click="$set('showAddModal', false)">Annuler</button>
                        <button type="submit" class="btn btn-sm btn-primary">Ajouter</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal données médicales --}}
    @if($showMedicalModal)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.5);z-index:1040"
             wire:click.self="$set('showMedicalModal', false)">
            <div class="bg-white rounded p-4" style="width:450px;max-width:95vw">
                <h6 class="fw-bold mb-3">Données médicales</h6>

                <form wire:submit="saveMedicalData">
                    <div class="mb-3">
                        <label class="form-label">Date de naissance</label>
                        <x-date-input wire:model="medDateNaissance" :value="$medDateNaissance" />
                        @error('medDateNaissance')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="medSexe" class="form-label">Sexe</label>
                        <select wire:model="medSexe" id="medSexe"
                                class="form-select @error('medSexe') is-invalid @enderror">
                            <option value="">-- Choisir --</option>
                            <option value="M">Masculin</option>
                            <option value="F">Féminin</option>
                        </select>
                        @error('medSexe')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="medPoids" class="form-label">Poids (kg)</label>
                        <input type="text" wire:model="medPoids" id="medPoids"
                               class="form-control @error('medPoids') is-invalid @enderror"
                               placeholder="Ex : 75">
                        @error('medPoids')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                wire:click="$set('showMedicalModal', false)">Annuler</button>
                        <button type="submit" class="btn btn-sm btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
