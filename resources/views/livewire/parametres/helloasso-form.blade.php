{{-- resources/views/livewire/parametres/helloasso-form.blade.php --}}
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
    @if(\App\Support\Demo::isActive())
        <x-demo-readonly-banner />
    @endif

    @if (session('success'))
        <div class="alert alert-success alert-dismissible mb-4">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-plug me-1"></i> Connexion HelloAsso</h5>
        </div>
        <div class="card-body">

            {{-- 1. Choix de l'environnement en tête --}}
            <div class="mb-4">
                <p class="fw-semibold mb-2">Sur quel environnement HelloAsso voulez-vous vous connecter ?</p>
                <div class="d-flex gap-4">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" wire:model.live="environnement"
                               value="production" id="env-prod"
                               @disabled(\App\Support\Demo::isActive())>
                        <label class="form-check-label" for="env-prod">Production</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" wire:model.live="environnement"
                               value="sandbox" id="env-sandbox"
                               @disabled(\App\Support\Demo::isActive())>
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
                       wire:model="clientId" autocomplete="off"
                       @disabled(\App\Support\Demo::isActive())>
                @error('clientId') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Client Secret</label>
                <input type="password" class="form-control @error('clientSecret') is-invalid @enderror"
                       wire:model="clientSecret" autocomplete="new-password"
                       @if($secretDejaEnregistre) placeholder="••••••••  (déjà enregistré)" @endif
                       @disabled(\App\Support\Demo::isActive())>
                <div class="form-text text-muted">
                    Chiffré en base de données.
                    @if($secretDejaEnregistre) Laisser vide pour conserver la valeur actuelle. @endif
                </div>
                @error('clientSecret') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-4">
                <label class="form-label">Slug organisation</label>
                <input type="text" class="form-control @error('organisationSlug') is-invalid @enderror"
                       wire:model="organisationSlug" placeholder="ex : mon-association"
                       @disabled(\App\Support\Demo::isActive())>
                <div class="form-text text-muted">
                    Visible dans l'URL : helloasso.com/associations/<em>slug</em>
                </div>
                @error('organisationSlug') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            {{-- 4. Boutons --}}
            @unless(\App\Support\Demo::isActive())
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
            @endunless

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

@php $callbackUrl = $this->getCallbackUrl(); @endphp
@if ($callbackUrl)
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-bell me-1"></i> Notification de callback</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-info mb-3">
            <p class="mb-1">Pour recevoir les notifications HelloAsso en temps réel, copiez l'URL ci-dessous
            et collez-la dans votre espace HelloAsso :</p>
            <ol class="mb-0">
                <li>Connectez-vous sur <strong>admin.helloasso.com</strong></li>
                <li>Allez dans <strong>Paramètres API → Notifications</strong></li>
                <li>Collez l'URL dans le champ <strong>« Mon URL de callback »</strong></li>
            </ol>
        </div>

        <div class="input-group mb-3">
            <input type="text" class="form-control font-monospace" value="{{ $callbackUrl }}" readonly
                   id="callback-url-input">
            <button class="btn btn-outline-secondary" type="button"
                    onclick="navigator.clipboard.writeText(document.getElementById('callback-url-input').value).then(() => { this.innerHTML = '<i class=\'bi bi-check2\'></i> Copié'; setTimeout(() => this.innerHTML = '<i class=\'bi bi-clipboard\'></i> Copier', 2000) })">
                <i class="bi bi-clipboard"></i> Copier
            </button>
        </div>

        <button type="button" class="btn btn-outline-warning btn-sm"
                wire:click="regenererToken"
                wire:confirm="Attention : si vous régénérez le token, l'ancienne URL ne fonctionnera plus. Vous devrez mettre à jour l'URL sur HelloAsso. Continuer ?">
            <i class="bi bi-arrow-repeat"></i> Régénérer le token
        </button>
    </div>
</div>
@endif

    @unless(\App\Support\Demo::isActive())
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
                        <button class="btn btn-sm btn-primary" @click="$wire.save().then(() => { isDirty = false; showUnsavedModal = false; window.location = pendingUrl; })">
                            Enregistrer et quitter
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
    @endunless
</div>
