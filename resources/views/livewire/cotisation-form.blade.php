<div>
    @if (! $showForm)
        <div class="mb-3">
            <button wire:click="showNewForm" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Nouvelle cotisation
            </button>
        </div>
    @else
        <div class="position-fixed top-0 start-0 w-100 h-100" style="background:rgba(0,0,0,.5);z-index:1040;overflow-y:auto" wire:click.self="resetForm">
        <div class="container py-4">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Nouvelle cotisation</h5>
                <button wire:click="resetForm" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i> Annuler
                </button>
            </div>
            <div class="card-body">
                <form wire:submit="save">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tiers (membre) <span class="text-danger">*</span></label>
                            <livewire:tiers-autocomplete wire:model="tiers_id" filtre="tous" :key="'cotisation-tiers-new'" />
                            @error('tiers_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label for="date_paiement" class="form-label">Date paiement <span class="text-danger">*</span></label>
                            <input type="date" wire:model="date_paiement" id="date_paiement"
                                   class="form-control @error('date_paiement') is-invalid @enderror">
                            @error('date_paiement')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-2">
                            <label for="montant" class="form-label">Montant <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" wire:model="montant" id="montant" step="0.01" min="0.01"
                                       class="form-control @error('montant') is-invalid @enderror">
                                <span class="input-group-text">&euro;</span>
                            </div>
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
                        <div class="col-md-2">
                            <label for="compte_id" class="form-label">Compte bancaire</label>
                            <select wire:model="compte_id" id="compte_id" class="form-select">
                                <option value="">-- Aucun --</option>
                                @foreach ($comptes as $compte)
                                    <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" wire:click="resetForm" class="btn btn-secondary">Annuler</button>
                        <button type="submit" class="btn btn-success">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
        </div>
        </div>
    @endif
</div>
