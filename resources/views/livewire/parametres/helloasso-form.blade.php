{{-- resources/views/livewire/parametres/helloasso-form.blade.php --}}
<div>
    @if (session('success'))
        <div class="alert alert-success alert-dismissible mb-4">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card" style="max-width: 640px;">
        <div class="card-body">

            {{-- 1. Choix de l'environnement en tête --}}
            <div class="mb-4">
                <p class="fw-semibold mb-2">Sur quel environnement HelloAsso voulez-vous vous connecter ?</p>
                <div class="d-flex gap-4">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" wire:model.live="environnement"
                               value="production" id="env-prod">
                        <label class="form-check-label" for="env-prod">Production</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" wire:model.live="environnement"
                               value="sandbox" id="env-sandbox">
                        <label class="form-check-label" for="env-sandbox">Sandbox</label>
                    </div>
                </div>
            </div>

            {{-- 2. Bloc d'aide dynamique selon l'environnement --}}
            @php
                $adminUrl = \App\Enums\HelloAssoEnvironnement::from($environnement)->adminUrl();
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
                <input type="text" class="form-control @error('clientId') is-invalid @enderror"
                       wire:model="clientId" autocomplete="off">
                @error('clientId') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Client Secret</label>
                <input type="password" class="form-control @error('clientSecret') is-invalid @enderror"
                       wire:model="clientSecret" autocomplete="new-password"
                       @if($secretDejaEnregistre) placeholder="••••••••  (déjà enregistré)" @endif>
                <div class="form-text text-muted">
                    Chiffré en base de données.
                    @if($secretDejaEnregistre) Laisser vide pour conserver la valeur actuelle. @endif
                </div>
                @error('clientSecret') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-4">
                <label class="form-label">Slug organisation</label>
                <input type="text" class="form-control @error('organisationSlug') is-invalid @enderror"
                       wire:model="organisationSlug" placeholder="ex : association-svs">
                <div class="form-text text-muted">
                    Visible dans l'URL : helloasso.com/associations/<em>slug</em>
                </div>
                @error('organisationSlug') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            {{-- 4. Boutons --}}
            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-primary" wire:click="sauvegarder"
                        wire:loading.attr="disabled" wire:target="sauvegarder">
                    <span wire:loading.remove wire:target="sauvegarder">Enregistrer</span>
                    <span wire:loading wire:target="sauvegarder">
                        <span class="spinner-border spinner-border-sm" role="status"></span> Enregistrement…
                    </span>
                </button>
                <button type="button" class="btn btn-outline-secondary" wire:click="testerConnexion"
                        wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="testerConnexion">Tester la connexion</span>
                    <span wire:loading wire:target="testerConnexion">
                        <span class="spinner-border spinner-border-sm" role="status"></span> Test en cours…
                    </span>
                </button>
            </div>

            {{-- 5. Résultat du test --}}
            @if ($testResult !== null)
                @if ($testResult['success'])
                    <div class="alert alert-success mb-0">
                        <i class="bi bi-check-circle-fill"></i>
                        Connexion réussie — Organisation : <strong>{{ $testResult['organisationNom'] }}</strong>
                    </div>
                @else
                    <div class="alert alert-danger mb-0">
                        <i class="bi bi-x-circle-fill"></i>
                        {{ $testResult['erreur'] }}
                    </div>
                @endif
            @endif

        </div>
    </div>
</div>
