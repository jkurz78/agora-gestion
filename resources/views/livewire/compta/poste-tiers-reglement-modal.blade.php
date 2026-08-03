<div class="modal fade"
     id="poste-tiers-reglement-modal"
     tabindex="-1"
     aria-labelledby="poste-tiers-reglement-modal-title"
     aria-hidden="true"
     wire:ignore.self
     x-data
     x-on:poste-tiers-reglement-modal-open.window="bootstrap.Modal.getOrCreateInstance($el).show()"
     x-on:poste-tiers-reglement-modal-close.window="bootstrap.Modal.getOrCreateInstance($el).hide()">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="poste-tiers-reglement-modal-title">{{ $titre ?: 'Règlement tiers' }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer" wire:click="fermer"></button>
            </div>

            <div class="modal-body">
                @if($posteOrigine !== '')
                    <div class="alert alert-light border py-2 small">
                        <strong>Pièce d’origine :</strong> {{ $posteOrigine }}
                    </div>
                @endif

                @error('reglement')
                    <div class="alert alert-danger py-2" role="alert">{{ $message }}</div>
                @enderror

                <div class="mb-3">
                    <label for="poste-tiers-reglement-montant" class="form-label">Montant</label>
                    <div class="input-group">
                        <input id="poste-tiers-reglement-montant" type="text"
                               class="form-control @error('montant') is-invalid @enderror"
                               wire:model="montant" inputmode="decimal">
                        <span class="input-group-text">€</span>
                        @error('montant') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="poste-tiers-reglement-date" class="form-label">Date du règlement</label>
                    <input id="poste-tiers-reglement-date" type="date"
                           class="form-control @error('dateReglement') is-invalid @enderror"
                           wire:model="dateReglement">
                    @error('dateReglement') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label for="poste-tiers-reglement-mode" class="form-label">Mode de paiement</label>
                    <select id="poste-tiers-reglement-mode" class="form-select @error('mode') is-invalid @enderror" wire:model.live="mode">
                        <option value="">Sélectionner un mode</option>
                        @foreach($modesPaiement as $modePaiement)
                            <option value="{{ $modePaiement->value }}">{{ $modePaiement->label() }}</option>
                        @endforeach
                    </select>
                    @error('mode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                @if($compteBancaireRequis)
                    <div class="mb-0">
                        <label for="poste-tiers-reglement-compte" class="form-label">
                            Compte bancaire <span class="text-danger">*</span>
                        </label>
                        <select id="poste-tiers-reglement-compte" class="form-select @error('compteBancaireId') is-invalid @enderror" wire:model="compteBancaireId">
                            <option value="">Sélectionner un compte bancaire</option>
                            @foreach($comptesBancaires as $compteBancaire)
                                <option value="{{ $compteBancaire->id }}">{{ $compteBancaire->nom }}</option>
                            @endforeach
                        </select>
                        @error('compteBancaireId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">{{ $aideCompteBancaire }}</div>
                    </div>
                @endif
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" wire:click="fermer">Fermer</button>
                <button type="button" class="btn btn-primary" wire:click="enregistrer" wire:loading.attr="disabled">
                    <span wire:loading wire:target="enregistrer" class="spinner-border spinner-border-sm me-1"></span>
                    Enregistrer le règlement
                </button>
            </div>
        </div>
    </div>
</div>
