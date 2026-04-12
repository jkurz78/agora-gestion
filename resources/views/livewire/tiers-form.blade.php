{{-- resources/views/livewire/tiers-form.blade.php --}}
<div>
    {{-- Formulaire --}}
    @if ($showForm)
        <div class="position-fixed top-0 start-0 w-100 h-100"
             style="background:rgba(0,0,0,.5);z-index:2000;overflow-y:auto">
        <div class="container py-4">
        <div class="card mb-4 shadow-sm border-0">
            <div class="card-header fw-semibold" style="background:#722281;color:white">
                {{ $tiersId ? 'Modifier le tiers' : 'Nouveau tiers' }}
            </div>
            <div class="card-body">
                <div class="row g-3">

                    {{-- Type --}}
                    <div class="col-12 d-flex align-items-center gap-4">
                        <span class="fw-semibold">Type <span class="text-danger">*</span></span>
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="radio"
                                   wire:model.live="type" value="particulier" id="type_particulier">
                            <label class="form-check-label" for="type_particulier">Particulier</label>
                        </div>
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="radio"
                                   wire:model.live="type" value="entreprise" id="type_entreprise">
                            <label class="form-check-label" for="type_entreprise">Entreprise</label>
                        </div>
                        @error('type') <span class="text-danger small">{{ $message }}</span> @enderror
                        @if ($est_helloasso)
                            <span class="ms-auto badge" style="background:#722281" title="Tiers HelloAsso">HelloAsso</span>
                        @endif
                    </div>

                    {{-- Entreprise (type = entreprise) --}}
                    @if ($type === 'entreprise')
                        <div class="col-12" wire:key="field-entreprise">
                            <label class="form-label">Raison sociale <span class="text-danger">*</span></label>
                            <input type="text" wire:model="entreprise"
                                   class="form-control @error('entreprise') is-invalid @enderror"
                                   placeholder="Raison sociale">
                            @error('entreprise') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4" wire:key="field-ent-nom">
                            <label class="form-label text-muted">Nom du contact</label>
                            <input type="text" wire:model="nom" class="form-control"
                                   placeholder="Nom (optionnel)">
                        </div>
                        <div class="col-md-4" wire:key="field-ent-prenom">
                            <label class="form-label text-muted">Prénom du contact</label>
                            <input type="text" wire:model="prenom" class="form-control"
                                   placeholder="Prénom (optionnel)">
                        </div>
                    @else
                        {{-- Particulier --}}
                        <div class="col-md-4" wire:key="field-part-nom">
                            <label class="form-label">Nom <span class="text-danger">*</span></label>
                            <input type="text" wire:model="nom"
                                   class="form-control @error('nom') is-invalid @enderror"
                                   placeholder="Nom de famille">
                            @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4" wire:key="field-part-prenom">
                            <label class="form-label">Prénom</label>
                            <input type="text" wire:model="prenom" class="form-control" placeholder="Prénom">
                        </div>
                    @endif

                    {{-- Usage (hidden in participant context) --}}
                    @if($context !== 'participant')
                    <div class="col-12">
                        <label class="form-label fw-semibold">
                            Utilisation <span class="text-danger">*</span>
                        </label>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       wire:model="pour_depenses" id="pourDepenses">
                                <label class="form-check-label" for="pourDepenses">Dépenses</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       wire:model="pour_recettes" id="pourRecettes">
                                <label class="form-check-label" for="pourRecettes">Recettes</label>
                            </div>
                        </div>
                        @error('pour_depenses')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                    @endif

                    {{-- Toggle détails --}}
                    <div class="col-12">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                wire:click="$toggle('showDetails')">
                            {{ $showDetails ? '▲ Masquer les détails' : '▼ Détails (adresse, contact…)' }}
                        </button>
                    </div>

                    {{-- Section détails --}}
                    @if ($showDetails)
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" wire:model="email"
                                   class="form-control @error('email') is-invalid @enderror"
                                   placeholder="contact@exemple.fr">
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-check mb-3 mt-2">
                                <input class="form-check-input" type="checkbox" wire:model="email_optout" id="emailOptout">
                                <label class="form-check-label small" for="emailOptout">
                                    Désinscrit des communications <small class="text-muted">(RGPD)</small>
                                </label>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Téléphone</label>
                            <input type="text" wire:model="telephone" class="form-control" placeholder="06 …">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Adresse</label>
                            <input type="text" wire:model="adresse_ligne1" class="form-control"
                                   placeholder="N° et nom de rue">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Code postal</label>
                            <input type="text" wire:model="code_postal" class="form-control" placeholder="75001">
                        </div>

                        <div class="col-md-5">
                            <label class="form-label">Ville</label>
                            <input type="text" wire:model="ville" class="form-control" placeholder="Paris">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Pays</label>
                            <input type="text" wire:model="pays" class="form-control" placeholder="France">
                        </div>

                    @endif

                </div>

                {{-- Actions --}}
                <div class="d-flex gap-2 mt-4">
                    <button wire:click="resetForm" class="btn btn-outline-secondary">Annuler</button>
                    <button wire:click="save" class="btn text-white" style="background:#722281"
                            wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1"></span>
                        {{ $tiersId ? 'Mettre à jour' : 'Créer le tiers' }}
                    </button>
                </div>
            </div>
        </div>
        </div>
        </div>
    @endif
</div>
