{{-- resources/views/livewire/tiers-form.blade.php --}}
<div>
    {{-- Bouton nouveau --}}
    @unless ($showForm)
        <button wire:click="showNewForm" class="btn text-white mb-3" style="background:#722281">
            + Nouveau tiers
        </button>
    @endunless

    {{-- Formulaire --}}
    @if ($showForm)
        <div class="position-fixed top-0 start-0 w-100 h-100" style="background:rgba(0,0,0,.5);z-index:1040;overflow-y:auto" wire:click.self="resetForm">
        <div class="container py-4">
        <div class="card mb-4 shadow-sm border-0">
            <div class="card-header fw-semibold" style="background:#722281;color:white">
                {{ $tiersId ? 'Modifier le tiers' : 'Nouveau tiers' }}
            </div>
            <div class="card-body">
                <div class="row g-3">
                    {{-- Type --}}
                    <div class="col-md-4">
                        <label class="form-label">Type <span class="text-danger">*</span></label>
                        <select wire:model.live="type" class="form-select @error('type') is-invalid @enderror">
                            <option value="particulier">Particulier</option>
                            <option value="entreprise">Entreprise</option>
                        </select>
                        @error('type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Nom --}}
                    <div class="col-md-4">
                        <label class="form-label">
                            {{ $type === 'entreprise' ? 'Raison sociale' : 'Nom' }}
                            <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            wire:model="nom"
                            class="form-control @error('nom') is-invalid @enderror"
                            placeholder="{{ $type === 'entreprise' ? 'Raison sociale' : 'Nom de famille' }}"
                        >
                        @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Prenom (particulier seulement) --}}
                    @if ($type === 'particulier')
                        <div class="col-md-4">
                            <label class="form-label">Prenom</label>
                            <input type="text" wire:model="prenom" class="form-control" placeholder="Prenom">
                        </div>
                    @endif

                    {{-- Email --}}
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input
                            type="email"
                            wire:model="email"
                            class="form-control @error('email') is-invalid @enderror"
                            placeholder="contact@exemple.fr"
                        >
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Telephone --}}
                    <div class="col-md-4">
                        <label class="form-label">Telephone</label>
                        <input type="text" wire:model="telephone" class="form-control" placeholder="06 ...">
                    </div>

                    {{-- Adresse --}}
                    <div class="col-12">
                        <label class="form-label">Adresse</label>
                        <textarea wire:model="adresse_ligne1" class="form-control" rows="2" placeholder="Adresse postale"></textarea>
                    </div>

                    {{-- Flags --}}
                    <div class="col-12">
                        <label class="form-label fw-semibold">
                            Utilisation <span class="text-danger">*</span>
                        </label>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" wire:model="pour_depenses" id="pourDepenses">
                                <label class="form-check-label" for="pourDepenses">Depenses (fournisseur, intervenant...)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" wire:model="pour_recettes" id="pourRecettes">
                                <label class="form-check-label" for="pourRecettes">Recettes (dons, cotisations, ventes...)</label>
                            </div>
                        </div>
                        @error('pour_depenses')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                {{-- Actions --}}
                <div class="d-flex gap-2 mt-4">
                    <button wire:click="resetForm" class="btn btn-outline-secondary">Annuler</button>
                    <button wire:click="save" class="btn text-white" style="background:#722281">
                        {{ $tiersId ? 'Mettre a jour' : 'Creer le tiers' }}
                    </button>
                </div>
            </div>
        </div>
        </div>
        </div>
    @endif
</div>
