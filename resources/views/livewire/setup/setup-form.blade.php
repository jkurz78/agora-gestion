<form class="card shadow-sm" wire:submit.prevent="submit">
    <div class="card-body p-4">
        <h1 class="h4 mb-1"><i class="bi bi-stars me-2" aria-hidden="true"></i>Bienvenue sur AgoraGestion</h1>
        <p class="text-muted small mb-4">Configurons votre instance en quelques secondes.</p>

        <div>
            <label for="setup-nom" class="form-label small fw-semibold">Nom complet</label>
            <input type="text" id="setup-nom" class="form-control" wire:model="nom" autocomplete="name" required maxlength="100" autofocus>
            @error('nom')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
        </div>

        <div class="mt-3">
            <label for="setup-email" class="form-label small fw-semibold">Email</label>
            <input type="email" id="setup-email" class="form-control" wire:model="email" autocomplete="username" required>
            @error('email')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
        </div>

        <div class="mt-3">
            <label for="setup-password" class="form-label small fw-semibold">Mot de passe</label>
            <div class="input-group">
                <input type="password" id="setup-password" class="form-control" wire:model="password" autocomplete="new-password" required minlength="8">
                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('setup-password', 'setup-password-icon')" aria-label="Afficher/masquer le mot de passe">
                    <i class="bi bi-eye" id="setup-password-icon" aria-hidden="true"></i>
                </button>
            </div>
            <small class="text-muted">Minimum 8 caractères.</small>
            @error('password')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
        </div>

        <div class="mt-3">
            <label for="setup-password-confirmation" class="form-label small fw-semibold">Confirmer le mot de passe</label>
            <div class="input-group">
                <input type="password" id="setup-password-confirmation" class="form-control" wire:model="password_confirmation" autocomplete="new-password" required minlength="8">
                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('setup-password-confirmation', 'setup-password-confirmation-icon')" aria-label="Afficher/masquer le mot de passe">
                    <i class="bi bi-eye" id="setup-password-confirmation-icon" aria-hidden="true"></i>
                </button>
            </div>
            @error('password')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
        </div>

        <hr class="my-4">

        <div class="mt-3">
            <label for="setup-nom-asso" class="form-label small fw-semibold">Nom de votre association</label>
            <input type="text" id="setup-nom-asso" class="form-control" wire:model="nomAsso" autocomplete="organization" required maxlength="100">
            <small class="text-muted">Vous pourrez ajuster les autres informations (forme juridique, exercice comptable, …) juste après.</small>
            @error('nomAsso')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
        </div>

        <div class="d-grid mt-4">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Créer mon instance
            </button>
        </div>
    </div>
</form>
