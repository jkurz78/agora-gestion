<div>
    @if ($successMessage)
        <div class="alert alert-success alert-dismissible">
            {{ $successMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card">
        <div class="card-header">Mes informations</div>
        <div class="card-body">
            <form wire:submit="save">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="profil-nom" class="form-label">Nom <span class="text-danger">*</span></label>
                        <input type="text" id="profil-nom" wire:model="nom"
                               class="form-control @error('nom') is-invalid @enderror">
                        @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="profil-email" class="form-label">Adresse email <span class="text-danger">*</span></label>
                        <input type="email" id="profil-email" wire:model="email"
                               class="form-control @error('email') is-invalid @enderror">
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">
                            Un email de confirmation sera envoyé en cas de modification.
                        </div>
                    </div>
                </div>

                <hr class="my-3">
                <h6 class="text-muted">Changer le mot de passe <span class="fw-normal">(laisser vide pour ne pas modifier)</span></h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="profil-password" class="form-label">Nouveau mot de passe</label>
                        <input type="password" id="profil-password" wire:model="password"
                               class="form-control @error('password') is-invalid @enderror">
                        @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="profil-password-confirm" class="form-label">Confirmer</label>
                        <input type="password" id="profil-password-confirm" wire:model="password_confirmation" class="form-control">
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
