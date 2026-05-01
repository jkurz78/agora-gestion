<div>
    @if($isOpen)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)" wire:keydown.escape="close">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-arrow-counterclockwise text-warning me-2"></i>
                            Annuler la transaction
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"
                                wire:click="close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning mb-3" role="alert">
                            Cette opération crée une transaction d'extourne (montant négatif) qui annule comptablement la transaction
                            d'origine. La transaction d'origine est conservée intacte. Action réservée aux comptables et administrateurs.
                        </div>

                        <div class="mb-3">
                            <label for="extourne-libelle" class="form-label">Libellé de l'extourne</label>
                            <input type="text" class="form-control @error('libelle') is-invalid @enderror"
                                   id="extourne-libelle" wire:model="libelle" maxlength="255">
                            @error('libelle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="extourne-date" class="form-label">Date de l'extourne</label>
                                <input type="date" class="form-control @error('date') is-invalid @enderror"
                                       id="extourne-date" wire:model="date">
                                @error('date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="extourne-mode-paiement" class="form-label">Mode de paiement</label>
                                <select class="form-select @error('modePaiement') is-invalid @enderror"
                                        id="extourne-mode-paiement" wire:model="modePaiement">
                                    @foreach($modePaiementCases as $mp)
                                        <option value="{{ $mp->value }}">{{ $mp->label() }}</option>
                                    @endforeach
                                </select>
                                @error('modePaiement') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="extourne-notes" class="form-label">
                                Motif (optionnel)
                                <small class="text-muted">— pour documenter le contexte de l'annulation</small>
                            </label>
                            <textarea class="form-control @error('notes') is-invalid @enderror"
                                      id="extourne-notes" wire:model="notes" rows="2" maxlength="500"></textarea>
                            @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" wire:click="close">
                            Fermer
                        </button>
                        <button type="button" class="btn btn-warning" wire:click="submit" wire:loading.attr="disabled">
                            <span wire:loading wire:target="submit" class="spinner-border spinner-border-sm me-1"></span>
                            Confirmer l'annulation
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
