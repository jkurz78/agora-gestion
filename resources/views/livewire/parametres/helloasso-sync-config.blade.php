<div
    x-data="{ isDirty: false, ready: false, showUnsavedModal: false, pendingUrl: '' }"
    x-on:focusin.once="$nextTick(() => ready = true)"
    x-on:input="if (ready) isDirty = true"
    x-on:change="if (ready) isDirty = true"
    x-on:form-saved.window="isDirty = false"
    x-on:click.window="
        if (isDirty) {
            const link = $event.target.closest('a[href]');
            if (link && link.getAttribute('href') !== '#'
                && !link.classList.contains('btn-primary')
                && !link.getAttribute('target')
                && !link.closest('.dropdown-menu')) {
                $event.preventDefault();
                pendingUrl = link.href;
                showUnsavedModal = true;
            }
        }
    "
>
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
                        @foreach($comptesHelloasso as $c)
                            <option value="{{ $c->id }}">{{ $c->nom }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">Seuls les comptes marqués <em>saisie automatisée</em> sont proposés.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Compte de versement (destination)</label>
                    <select wire:model="compteVersementId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($comptesVersement as $c)
                            <option value="{{ $c->id }}">{{ $c->nom }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">Compte bancaire réel où HelloAsso reverse périodiquement les fonds.</small>
                </div>
            </div>

            {{-- Mapping sous-catégories --}}
            <h6 class="mt-3">Sous-catégories par défaut</h6>
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

    {{-- Modale modifications non enregistrées --}}
    <template x-if="showUnsavedModal">
        <div class="modal-backdrop fade show" style="z-index: 1050;"></div>
    </template>
    <template x-if="showUnsavedModal">
        <div class="modal fade show" tabindex="-1" style="display: block; z-index: 1055;">
            <div class="modal-dialog modal-sm">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">Modifications non enregistrées</h6>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">Vous avez des modifications non enregistrées. Que souhaitez-vous faire ?</p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-sm btn-outline-secondary" @click="showUnsavedModal = false; window.location = pendingUrl;">
                            Abandonner
                        </button>
                        <button class="btn btn-sm btn-primary" @click="$wire.sauvegarder().then(() => { isDirty = false; showUnsavedModal = false; window.location = pendingUrl; })">
                            Enregistrer et quitter
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
