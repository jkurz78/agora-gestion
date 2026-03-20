<div
    x-data
    x-init="
        $wire.$watch('showForm', value => {
            if (value && $wire.donId === null) {
                const saved = localStorage.getItem('don_defaults');
                if (saved) {
                    const d = JSON.parse(saved);
                    $wire.applyStoredDefaults(d.sous_categorie_id ?? null, d.mode_paiement ?? '', d.compte_id ?? null);
                }
            }
        });
    "
>
    @if($showForm)
        <div class="position-fixed top-0 start-0 w-100 h-100" style="background:rgba(0,0,0,.5);z-index:1040;overflow-y:auto" wire:click.self="resetForm">
        <div class="container py-4">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">{{ $donId ? 'Modifier le don' : 'Nouveau don' }}</h5>
                <button wire:click="resetForm" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i> Annuler
                </button>
            </div>
            <div class="card-body">
                <form wire:submit="save"
                      x-on:submit="localStorage.setItem('don_defaults', JSON.stringify({
                          sous_categorie_id: $wire.sous_categorie_id || null,
                          mode_paiement: $wire.mode_paiement || '',
                          compte_id: $wire.compte_id || null,
                      }))"
                >
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label for="sous_categorie_id" class="form-label">Nature du don <span class="text-danger">*</span></label>
                            <select wire:model="sous_categorie_id" id="sous_categorie_id"
                                    class="form-select @error('sous_categorie_id') is-invalid @enderror">
                                <option value="">-- Choisir --</option>
                                @foreach ($naturesdon as $sc)
                                    <option value="{{ $sc->id }}">{{ $sc->nom }}</option>
                                @endforeach
                            </select>
                            @error('sous_categorie_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-2">
                            <label for="date" class="form-label">Date <span class="text-danger">*</span></label>
                            <x-date-input name="date" wire:model="date" :value="$date" />
                            @error('date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-2">
                            <label for="montant" class="form-label">Montant <span class="text-danger">*</span></label>
                            <input type="number" wire:model="montant" id="montant" step="0.01" min="0.01"
                                   class="form-control @error('montant') is-invalid @enderror">
                            @error('montant')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-2">
                            <label for="mode_paiement" class="form-label">Mode paiement <span class="text-danger">*</span></label>
                            <select wire:model="mode_paiement" id="mode_paiement"
                                    class="form-select @error('mode_paiement') is-invalid @enderror">
                                <option value="">-- Choisir --</option>
                                @foreach ($modesPaiement as $mode)
                                    <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                                @endforeach
                            </select>
                            @error('mode_paiement')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-3">
                            <label for="objet" class="form-label">Objet</label>
                            <input type="text" wire:model="objet" id="objet" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label for="compte_id" class="form-label">Compte bancaire</label>
                            <select wire:model="compte_id" id="compte_id" class="form-select">
                                <option value="">-- Aucun --</option>
                                @foreach ($comptes as $compte)
                                    <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Tiers (donateur) section --}}
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tiers (donateur)</label>
                            <livewire:tiers-autocomplete wire:model="tiers_id" filtre="dons" :key="'don-tiers-'.($donId ?? 'new')" />
                            @error('tiers_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    {{-- Operation / Seance --}}
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label for="operation_id" class="form-label">Opération</label>
                            <select wire:model.live="operation_id" id="operation_id" class="form-select">
                                <option value="">-- Aucune --</option>
                                @foreach ($operations as $op)
                                    <option value="{{ $op->id }}">{{ $op->nom }}</option>
                                @endforeach
                            </select>
                        </div>
                        @php
                            $selectedOp = $operation_id ? $operations->firstWhere('id', (int) $operation_id) : null;
                            $nbSeances = $selectedOp?->nombre_seances;
                        @endphp
                        @if ($nbSeances)
                            <div class="col-md-2">
                                <label for="seance" class="form-label">Séance</label>
                                <select wire:model="seance" id="seance"
                                        class="form-select @error('seance') is-invalid @enderror">
                                    <option value="">--</option>
                                    @for ($s = 1; $s <= $nbSeances; $s++)
                                        <option value="{{ $s }}">{{ $s }}</option>
                                    @endfor
                                </select>
                                @error('seance')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        @endif
                    </div>

                    <div class="d-flex gap-2">
                        <div class="ms-auto">
                            <button type="button" wire:click="resetForm" class="btn btn-secondary">Annuler</button>
                            <button type="submit" class="btn btn-success">
                                {{ $donId ? 'Mettre à jour' : 'Enregistrer' }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        </div>
        </div>
    @endif
</div>
