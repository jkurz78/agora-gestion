<form wire:submit="submit" class="col-md-8 mx-auto">
    <h2>Nouvelle association</h2>
    <p class="text-muted">Création du shell minimal. L'admin invité complètera l'onboarding au premier login.</p>

    <div class="mb-3">
        <label class="form-label">Nom de l'association</label>
        <input type="text" wire:model="nom" class="form-control @error('nom') is-invalid @enderror">
        @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="mb-3">
        <label class="form-label">Slug (URL-safe)</label>
        <input type="text" wire:model="slug" class="form-control @error('slug') is-invalid @enderror" placeholder="mon-asso">
        <small class="form-text text-muted">lettres minuscules, chiffres et tirets uniquement.</small>
        @error('slug') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="mb-3">
        <label class="form-label">Email de l'admin initial</label>
        <input type="email" wire:model="email_admin" class="form-control @error('email_admin') is-invalid @enderror">
        @error('email_admin') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="mb-3">
        <label class="form-label">Nom de l'admin</label>
        <input type="text" wire:model="nom_admin" class="form-control @error('nom_admin') is-invalid @enderror">
        @error('nom_admin') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Créer et envoyer l'invitation</button>
    <a href="{{ route('super-admin.associations.index') }}" class="btn btn-link">Annuler</a>
</form>
