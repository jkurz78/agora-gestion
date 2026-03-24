<div>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-gear me-1"></i> Configuration de la synchronisation</h5>
        </div>
        <div class="card-body">
            @if($erreur)
                <div class="alert alert-danger">{{ $erreur }}</div>
            @endif
            @if($message)
                <div class="alert alert-success">{{ $message }}</div>
            @endif

            {{-- Comptes --}}
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Compte HelloAsso (réception)</label>
                    <select wire:model="compteHelloassoId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($comptes as $c)
                            <option value="{{ $c->id }}">{{ $c->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Compte de versement (destination)</label>
                    <select wire:model="compteVersementId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($comptes as $c)
                            <option value="{{ $c->id }}">{{ $c->nom }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Mapping sous-catégories --}}
            <h6 class="mt-3">Mapping des sous-catégories</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small">Dons (Donation)</label>
                    <select wire:model="sousCategorieDonId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($sousCategoriesDon as $sc)
                            <option value="{{ $sc->id }}">{{ $sc->nom }} ({{ $sc->code_cerfa }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Cotisations (Membership)</label>
                    <select wire:model="sousCategorieCotisationId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($sousCategoriesCotisation as $sc)
                            <option value="{{ $sc->id }}">{{ $sc->nom }} ({{ $sc->code_cerfa }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Inscriptions (Registration)</label>
                    <select wire:model="sousCategorieInscriptionId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($sousCategoriesInscription as $sc)
                            <option value="{{ $sc->id }}">{{ $sc->nom }} ({{ $sc->code_cerfa }})</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <button wire:click="sauvegarder" class="btn btn-sm btn-primary">
                <i class="bi bi-check-lg me-1"></i> Enregistrer la configuration
            </button>
        </div>
    </div>

</div>
