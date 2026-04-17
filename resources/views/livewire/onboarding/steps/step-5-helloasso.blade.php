<h3>5. HelloAsso <small class="text-muted">(optionnel)</small></h3>
<p class="text-muted">Connectez votre compte HelloAsso pour recevoir automatiquement les dons et cotisations. Vous pouvez passer cette étape et la configurer plus tard depuis les paramètres.</p>

<form wire:submit="saveStep5">
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Client ID</label>
            <input type="text" wire:model="helloClientId" class="form-control @error('helloClientId') is-invalid @enderror">
            @error('helloClientId') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">
                Client Secret
                @if($helloSecretDejaEnregistre)<small class="text-muted">(laisser vide pour conserver)</small>@endif
            </label>
            <input type="password" wire:model="helloClientSecret" class="form-control @error('helloClientSecret') is-invalid @enderror" placeholder="{{ $helloSecretDejaEnregistre ? '••••••••' : '' }}">
            @error('helloClientSecret') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Slug de l'organisation HelloAsso</label>
            <input type="text" wire:model="helloOrganisationSlug" class="form-control @error('helloOrganisationSlug') is-invalid @enderror">
            @error('helloOrganisationSlug') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Environnement</label>
            <select wire:model="helloEnvironnement" class="form-select @error('helloEnvironnement') is-invalid @enderror">
                <option value="production">Production</option>
                <option value="sandbox">Sandbox (test)</option>
            </select>
            @error('helloEnvironnement') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>

    <div class="d-flex gap-2 justify-content-between mt-4">
        <button type="button" wire:click="goToStep(4)" class="btn btn-link">← Retour</button>
        <div class="d-flex gap-2">
            <button type="button" wire:click="skipStep5" class="btn btn-outline-secondary">Passer cette étape</button>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Valider et continuer</button>
        </div>
    </div>
</form>
