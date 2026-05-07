<div>
    @if ($showModal)
        <div class="modal d-block" tabindex="-1" style="background: rgba(0,0,0,.5);">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Créer le tiers</h5>
                        <button type="button" class="btn-close" wire:click="close" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Type</label>
                            <select wire:model="type" class="form-select">
                                <option value="particulier">Particulier</option>
                                <option value="entreprise">Entreprise</option>
                            </select>
                            @error('type') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">Prénom</label>
                                <input type="text" wire:model="prenom" class="form-control" maxlength="100">
                                @error('prenom') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Nom</label>
                                <input type="text" wire:model="nom" class="form-control" maxlength="120">
                                @error('nom') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="mb-3 mt-3">
                            <label class="form-label">Email</label>
                            <input type="email" wire:model="email" class="form-control" maxlength="255">
                            @error('email') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" wire:model="pour_recettes" id="pourRecettes">
                            <label class="form-check-label" for="pourRecettes">
                                Disponible pour recettes (par défaut, à confirmer)
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="close" wire:loading.attr="disabled">Annuler</button>
                        <button type="button" class="btn btn-success" wire:click="save" wire:loading.attr="disabled">Créer</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
