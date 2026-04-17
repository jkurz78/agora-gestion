<h3>1. Identité de votre association</h3>
<p class="text-muted">Ces informations apparaîtront sur vos documents (factures, attestations…).</p>

<form wire:submit="saveStep1">
    <div class="mb-3">
        <label class="form-label">Adresse</label>
        <input type="text" wire:model="identiteAdresse" class="form-control @error('identiteAdresse') is-invalid @enderror">
        @error('identiteAdresse') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="row">
        <div class="col-md-4 mb-3">
            <label class="form-label">Code postal</label>
            <input type="text" wire:model="identiteCodePostal" class="form-control @error('identiteCodePostal') is-invalid @enderror">
            @error('identiteCodePostal') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-8 mb-3">
            <label class="form-label">Ville</label>
            <input type="text" wire:model="identiteVille" class="form-control @error('identiteVille') is-invalid @enderror">
            @error('identiteVille') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Email de contact</label>
            <input type="email" wire:model="identiteEmail" class="form-control @error('identiteEmail') is-invalid @enderror">
            @error('identiteEmail') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Téléphone</label>
            <input type="text" wire:model="identiteTelephone" class="form-control">
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">SIRET (14 chiffres)</label>
            <input type="text" wire:model="identiteSiret" class="form-control @error('identiteSiret') is-invalid @enderror" placeholder="12345678901234">
            @error('identiteSiret') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Forme juridique</label>
            <input type="text" wire:model="identiteFormeJuridique" class="form-control" placeholder="Association loi 1901">
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Logo (PNG/JPG, max 2 Mo)</label>
            <input type="file" wire:model="logoUpload" class="form-control @error('logoUpload') is-invalid @enderror" accept="image/*">
            @error('logoUpload') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <div wire:loading wire:target="logoUpload" class="text-muted small">Téléchargement…</div>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Cachet / signature (optionnel)</label>
            <input type="file" wire:model="cachetUpload" class="form-control @error('cachetUpload') is-invalid @enderror" accept="image/*">
            @error('cachetUpload') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>

    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Valider et continuer</button>
</form>
