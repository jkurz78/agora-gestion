<div>
    {{-- ── 1. En-tête ──────────────────────────────────────────────────────── --}}
    <h4 class="mb-1">Mon profil</h4>
    <p class="text-muted mb-4">Vos informations personnelles et préférences.</p>

    {{-- Message de succès --}}
    @if (session()->has('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <form wire:submit.prevent="save">

        {{-- ── 2. Identité (lecture seule) ─────────────────────────────────── --}}
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-lock-fill text-secondary"></i>
                <span class="fw-semibold">Identité</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label text-muted small">Civilité</label>
                        <input type="text" class="form-control" value="{{ $locked['civilite'] }}" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-muted small">Prénom</label>
                        <input type="text" class="form-control" value="{{ $locked['prenom'] }}" disabled>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label text-muted small">Nom</label>
                        <input type="text" class="form-control" value="{{ $locked['nom'] }}" disabled>
                    </div>
                    <div class="col-12">
                        <label class="form-label text-muted small">Email</label>
                        <input type="email" class="form-control" value="{{ $locked['email'] }}" disabled>
                    </div>
                </div>
                <p class="text-muted small mt-3 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Pour modifier ces informations, contactez l'association —
                    <a href="{{ $mailtoContact }}">contactez-nous</a>.
                </p>
            </div>
        </div>

        {{-- ── 3. Coordonnées (modifiables) ────────────────────────────────── --}}
        <div class="card mb-4">
            <div class="card-header fw-semibold">Coordonnées</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Adresse</label>
                        <input type="text"
                               name="adresse_ligne1"
                               class="form-control @error('adresse_ligne1') is-invalid @enderror"
                               wire:model="adresse_ligne1"
                               maxlength="255">
                        @error('adresse_ligne1')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Code postal</label>
                        <input type="text"
                               name="code_postal"
                               class="form-control @error('code_postal') is-invalid @enderror"
                               wire:model="code_postal"
                               maxlength="20">
                        @error('code_postal')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Ville</label>
                        <input type="text"
                               name="ville"
                               class="form-control @error('ville') is-invalid @enderror"
                               wire:model="ville"
                               maxlength="120">
                        @error('ville')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Pays</label>
                        <input type="text"
                               name="pays"
                               class="form-control @error('pays') is-invalid @enderror"
                               wire:model="pays"
                               maxlength="80">
                        @error('pays')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Téléphone</label>
                        <input type="tel"
                               name="telephone"
                               class="form-control @error('telephone') is-invalid @enderror"
                               wire:model="telephone"
                               maxlength="30">
                        @error('telephone')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- ── 4. Préférences de communication ─────────────────────────────── --}}
        <div class="card mb-4">
            <div class="card-header fw-semibold">Préférences de communication</div>
            <div class="card-body">
                <div class="form-check form-switch">
                    <input class="form-check-input"
                           type="checkbox"
                           role="switch"
                           id="email_optout"
                           name="email_optout"
                           wire:model="email_optout">
                    <label class="form-check-label" for="email_optout">
                        Je ne souhaite pas recevoir les emails de communication de l'association
                    </label>
                </div>
                <p class="text-muted small mt-2 mb-0">
                    Cette préférence s'applique aux communications générales.
                    Les emails transactionnels importants (confirmations, reçus) vous seront toujours envoyés.
                </p>
            </div>
        </div>

        {{-- ── 6. Boutons ───────────────────────────────────────────────────── --}}
        <div class="d-flex gap-2 mb-4">
            <button type="submit"
                    class="btn text-white"
                    style="background:#3d5473;"
                    wire:loading.attr="disabled">
                <span wire:loading.remove>Enregistrer mes changements</span>
                <span wire:loading>Enregistrement…</span>
            </button>
            <a href="{{ \App\Support\PortailRoute::to('home', $association) }}"
               class="btn btn-outline-secondary">
                Annuler
            </a>
        </div>

    </form>

    {{-- ── 5. Section RGPD ──────────────────────────────────────────────────── --}}
    <div class="card border-danger-subtle mb-4">
        <div class="card-header text-danger-emphasis fw-semibold">
            <i class="bi bi-shield-exclamation me-1"></i>
            Données personnelles (RGPD)
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                Conformément au Règlement Général sur la Protection des Données (RGPD),
                vous pouvez demander la suppression de votre compte et de vos données personnelles.
            </p>
            @if ($assocEmail !== '')
                <a href="{{ $mailtoRgpd }}" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash me-1"></i>
                    Demander la suppression de mon compte
                </a>
            @else
                <p class="text-muted small mb-0">
                    Contactez l'association pour toute demande de suppression.
                </p>
            @endif
        </div>
    </div>
</div>
