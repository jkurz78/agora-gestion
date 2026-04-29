<form class="card shadow-sm" wire:submit.prevent="submit" autocomplete="off">
    <div class="card-body p-4">
        <h1 class="h4 mb-1"><i class="bi bi-stars me-2"></i>Bienvenue sur AgoraGestion</h1>
        <p class="text-muted small mb-4">Configurons votre instance en quelques secondes.</p>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label small fw-semibold">Prénom</label>
                <input type="text" class="form-control" wire:model="prenom" required maxlength="50">
                @error('prenom')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-semibold">Nom</label>
                <input type="text" class="form-control" wire:model="nom" required maxlength="50">
                @error('nom')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="mt-3">
            <label class="form-label small fw-semibold">Email</label>
            <input type="email" class="form-control" wire:model="email" required>
            @error('email')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
        </div>

        <div class="mt-3">
            <label class="form-label small fw-semibold">Mot de passe</label>
            <input type="password" class="form-control" wire:model="password" required minlength="8">
            <small class="text-muted">Minimum 8 caractères.</small>
            @error('password')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
        </div>

        <hr class="my-4">

        <div class="mt-3">
            <label class="form-label small fw-semibold">Nom de votre association</label>
            <input type="text" class="form-control" wire:model="nomAsso" required maxlength="100">
            <small class="text-muted">Vous pourrez ajuster les autres informations (forme juridique, exercice comptable, …) juste après.</small>
            @error('nomAsso')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
        </div>

        <div class="d-grid mt-4">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Créer mon instance
            </button>
        </div>
    </div>
</form>
