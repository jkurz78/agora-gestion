<h3>5. HelloAsso <small class="text-muted">(optionnel)</small></h3>
<p class="text-muted">Connectez votre compte HelloAsso pour recevoir automatiquement les dons et cotisations. Vous pouvez passer cette étape et la configurer plus tard depuis les paramètres.</p>

<form wire:submit="saveStep5">
    {{-- 1. Choix de l'environnement en tête --}}
    <div class="mb-4">
        <p class="fw-semibold mb-2">Sur quel environnement HelloAsso voulez-vous vous connecter ?</p>
        <div class="d-flex gap-4">
            <div class="form-check">
                <input class="form-check-input" type="radio" wire:model.live="helloEnvironnement"
                       value="production" id="hello-env-prod">
                <label class="form-check-label" for="hello-env-prod">Production</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" wire:model.live="helloEnvironnement"
                       value="sandbox" id="hello-env-sandbox">
                <label class="form-check-label" for="hello-env-sandbox">Sandbox (test)</label>
            </div>
        </div>
    </div>

    {{-- 2. Bloc d'aide dynamique selon l'environnement --}}
    @php
        $adminUrl = \App\Enums\HelloAssoEnvironnement::from($helloEnvironnement)->adminUrl();
    @endphp
    <div class="alert alert-info mb-4">
        <p class="mb-2">
            Pour connecter l'application, connectez-vous sur
            <a href="{{ $adminUrl }}" target="_blank" rel="noopener">{{ $adminUrl }}</a>
            avec un compte <strong>administrateur</strong> de l'association, puis&nbsp;:
        </p>
        <ol class="mb-0">
            <li>Allez dans <strong>Tableau de bord &gt; API &gt; Mes applications</strong></li>
            <li>Créez une nouvelle application</li>
            <li>Copiez le <strong>Client ID</strong> et le <strong>Client Secret</strong> dans les champs ci-dessous</li>
            <li>Le slug organisation est visible dans l'URL de votre espace&nbsp;:
                <code>helloasso.com/associations/<em>slug</em></code></li>
        </ol>
    </div>

    {{-- 3. Champs du formulaire --}}
    <div class="mb-3">
        <label class="form-label">Client ID</label>
        <input type="text" wire:model="helloClientId" autocomplete="off"
               class="form-control @error('helloClientId') is-invalid @enderror">
        @error('helloClientId') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="mb-3">
        <label class="form-label">Client Secret</label>
        <input type="password" wire:model="helloClientSecret" autocomplete="new-password"
               class="form-control @error('helloClientSecret') is-invalid @enderror"
               @if($helloSecretDejaEnregistre) placeholder="••••••••  (déjà enregistré)" @endif>
        <div class="form-text text-muted">
            Chiffré en base de données.
            @if($helloSecretDejaEnregistre) Laisser vide pour conserver la valeur actuelle. @endif
        </div>
        @error('helloClientSecret') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="mb-4">
        <label class="form-label">Slug organisation</label>
        <input type="text" wire:model="helloOrganisationSlug" placeholder="ex : mon-association"
               class="form-control @error('helloOrganisationSlug') is-invalid @enderror">
        <div class="form-text text-muted">
            Visible dans l'URL : helloasso.com/associations/<em>slug</em>
        </div>
        @error('helloOrganisationSlug') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="d-flex gap-2 justify-content-between mt-4">
        <button type="button" wire:click="goToStep(4)" class="btn btn-link">← Retour</button>
        <div class="d-flex gap-2">
            <button type="button" wire:click="skipStep5" class="btn btn-outline-secondary">Passer cette étape</button>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Valider et continuer</button>
        </div>
    </div>
</form>
