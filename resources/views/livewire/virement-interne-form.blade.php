<div>
    @if ($showForm)
        <div class="position-fixed top-0 start-0 w-100 h-100" style="background:rgba(0,0,0,.5);z-index:1040;overflow-y:auto" wire:click.self="resetForm">
        <div class="container py-4">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">{{ $exerciceCloture ? 'Visualiser le virement' : ($virementId ? 'Modifier le virement' : 'Nouveau virement interne') }}</h5>
                <button wire:click="resetForm" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i> Annuler
                </button>
            </div>
            <div class="card-body">
                <form wire:submit="save">
                    <div class="row g-3 mb-4">
                        <div class="col-md-2">
                            <label for="date" class="form-label">Date <span class="text-danger">*</span></label>
                            <x-date-input name="date" wire:model="date" :value="$date" :disabled="$exerciceCloture" />
                            @error('date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label for="reference" class="form-label">Référence</label>
                            <input type="text" wire:model="reference" id="reference" class="form-control"
                                   {{ $exerciceCloture ? 'disabled' : '' }}>
                        </div>
                        <div class="col-md-2">
                            <label for="montant" class="form-label">Montant <span class="text-danger">*</span></label>
                            <input type="number" wire:model="montant" id="montant" step="0.01" min="0.01"
                                   class="form-control @error('montant') is-invalid @enderror"
                                   {{ $exerciceCloture ? 'disabled' : '' }}>
                            @error('montant') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label for="compte_source_id" class="form-label">Compte source <span class="text-danger">*</span></label>
                            <select wire:model="compte_source_id" id="compte_source_id"
                                    class="form-select @error('compte_source_id') is-invalid @enderror"
                                    {{ $exerciceCloture ? 'disabled' : '' }}>
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
                                    class="form-select @error('compte_destination_id') is-invalid @enderror"
                                    {{ $exerciceCloture ? 'disabled' : '' }}>
                                <option value="">-- Choisir --</option>
                                @foreach ($comptes as $compte)
                                    <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                @endforeach
                            </select>
                            @error('compte_destination_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-12">
                            <label for="notes" class="form-label">Notes</label>
                            <input type="text" wire:model="notes" id="notes" class="form-control"
                                   {{ $exerciceCloture ? 'disabled' : '' }}>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" wire:click="resetForm" class="btn btn-secondary">{{ $exerciceCloture ? 'Fermer' : 'Annuler' }}</button>
                        @if (! $exerciceCloture)
                        <button type="submit" class="btn btn-success">
                            {{ $virementId ? 'Mettre à jour' : 'Enregistrer' }}
                        </button>
                        @endif
                    </div>
                </form>
            </div>
        </div>
        </div>
        </div>
    @endif
</div>
