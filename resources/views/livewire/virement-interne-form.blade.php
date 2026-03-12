<div>
    @if (! $showForm)
        <div class="mb-3">
            <button wire:click="showNewForm" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Nouveau virement
            </button>
        </div>
    @else
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">{{ $virementId ? 'Modifier le virement' : 'Nouveau virement interne' }}</h5>
                <button wire:click="resetForm" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i> Annuler
                </button>
            </div>
            <div class="card-body">
                <form wire:submit="save">
                    <div class="row g-3 mb-3">
                        <div class="col-md-2">
                            <label for="date" class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" wire:model="date" id="date"
                                   class="form-control @error('date') is-invalid @enderror">
                            @error('date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label for="reference" class="form-label">Référence</label>
                            <input type="text" wire:model="reference" id="reference" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label for="montant" class="form-label">Montant <span class="text-danger">*</span></label>
                            <input type="number" wire:model="montant" id="montant" step="0.01" min="0.01"
                                   class="form-control @error('montant') is-invalid @enderror">
                            @error('montant') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label for="compte_source_id" class="form-label">Compte source <span class="text-danger">*</span></label>
                            <select wire:model="compte_source_id" id="compte_source_id"
                                    class="form-select @error('compte_source_id') is-invalid @enderror">
                                <option value="">-- Choisir --</option>
                                @foreach ($comptes as $compte)
                                    <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                @endforeach
                            </select>
                            @error('compte_source_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label for="compte_destination_id" class="form-label">Compte destination <span class="text-danger">*</span></label>
                            <select wire:model="compte_destination_id" id="compte_destination_id"
                                    class="form-select @error('compte_destination_id') is-invalid @enderror">
                                <option value="">-- Choisir --</option>
                                @foreach ($comptes as $compte)
                                    <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                @endforeach
                            </select>
                            @error('compte_destination_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-12">
                            <label for="notes" class="form-label">Notes</label>
                            <input type="text" wire:model="notes" id="notes" class="form-control">
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" wire:click="resetForm" class="btn btn-secondary">Annuler</button>
                        <button type="submit" class="btn btn-success">
                            {{ $virementId ? 'Mettre à jour' : 'Enregistrer' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
