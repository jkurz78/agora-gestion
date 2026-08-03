<div class="modal fade"
     id="poste-tiers-reglement-annulation-modal"
     tabindex="-1"
     aria-labelledby="poste-tiers-reglement-annulation-modal-title"
     aria-hidden="true"
     wire:ignore.self
     x-data
     x-on:poste-tiers-reglement-annulation-modal-open.window="bootstrap.Modal.getOrCreateInstance($el).show()"
     x-on:poste-tiers-reglement-annulation-modal-close.window="bootstrap.Modal.getOrCreateInstance($el).hide()">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="poste-tiers-reglement-annulation-modal-title">Annuler le règlement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer" wire:click="fermer"></button>
            </div>

            <div class="modal-body">
                @error('annulation')
                    <div class="alert alert-danger py-2" role="alert">{{ $message }}</div>
                @enderror

                <p class="mb-2">Vous allez annuler le règlement suivant :</p>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Date</dt>
                    <dd class="col-sm-8">{{ $dateReglement }}</dd>
                    <dt class="col-sm-4">Montant</dt>
                    <dd class="col-sm-8">{{ $montantReglement }}</dd>
                </dl>
                <div class="alert alert-warning py-2 mt-3 mb-0">
                    Le poste tiers sera de nouveau ouvert à hauteur de ce règlement.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" wire:click="fermer">Conserver le règlement</button>
                <button type="button" class="btn btn-danger" wire:click="annuler" wire:loading.attr="disabled">
                    <span wire:loading wire:target="annuler" class="spinner-border spinner-border-sm me-1"></span>
                    Annuler le règlement
                </button>
            </div>
        </div>
    </div>
</div>
