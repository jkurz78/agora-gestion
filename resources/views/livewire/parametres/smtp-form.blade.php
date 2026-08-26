{{-- resources/views/livewire/parametres/smtp-form.blade.php --}}
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
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible mb-4">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="form-check form-switch mb-4">
        <input class="form-check-input" type="checkbox"
               id="smtpEnabled" wire:click="toggleEnabled"
               @checked($enabled) @disabled(\App\Support\Demo::isActive())>
        <label class="form-check-label" for="smtpEnabled">
            Utiliser cette configuration SMTP
            @if ($enabled)
                <span class="badge bg-success ms-1">ACTIVE</span>
            @else
                <span class="badge bg-secondary ms-1">DÉSACTIVÉE — envois via .env</span>
            @endif
        </label>
    </div>

    <div class="row g-3" style="max-width: 680px;">
        <div class="col-md-7">
            <label class="form-label">Hôte SMTP</label>
            <input type="text" class="form-control @error('smtpHost') is-invalid @enderror"
                   wire:model="smtpHost" placeholder="mail.monasso.fr" autocomplete="off"
                   @disabled(\App\Support\Demo::isActive())>
            @error('smtpHost') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-2">
            <label class="form-label">Port</label>
            <input type="number" class="form-control @error('smtpPort') is-invalid @enderror"
                   wire:model="smtpPort" @disabled(\App\Support\Demo::isActive())>
            @error('smtpPort') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-3">
            <label class="form-label">Chiffrement</label>
            <select class="form-select @error('smtpEncryption') is-invalid @enderror"
                    wire:model="smtpEncryption" @disabled(\App\Support\Demo::isActive())>
                <option value="tls">TLS (587)</option>
                <option value="ssl">SSL (465)</option>
                <option value="starttls">STARTTLS</option>
                <option value="none">Aucun</option>
            </select>
            @error('smtpEncryption') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-7">
            <label class="form-label">Utilisateur</label>
            <input type="text" class="form-control @error('smtpUsername') is-invalid @enderror"
                   wire:model="smtpUsername" placeholder="envoi@monasso.fr" autocomplete="off"
                   @disabled(\App\Support\Demo::isActive())>
            @error('smtpUsername') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-5">
            <label class="form-label">
                Mot de passe
                @if ($passwordDejaEnregistre)
                    <span class="badge bg-info ms-1">déjà enregistré</span>
                @endif
            </label>
            <input type="password" class="form-control @error('smtpPassword') is-invalid @enderror"
                   wire:model="smtpPassword" autocomplete="new-password"
                   placeholder="{{ $passwordDejaEnregistre ? '●●●●●●●● (laisser vide pour conserver)' : '' }}"
                   @disabled(\App\Support\Demo::isActive())>
            <div class="form-text text-muted">
                Chiffré en base de données.
                @if ($passwordDejaEnregistre) Laisser vide pour conserver la valeur actuelle. @endif
            </div>
            @error('smtpPassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-3">
            <label class="form-label">Timeout (s)</label>
            <input type="number" class="form-control @error('timeout') is-invalid @enderror"
                   wire:model="timeout" min="5" max="120" @disabled(\App\Support\Demo::isActive())>
            @error('timeout') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>

    <hr class="my-4" style="max-width: 680px;">

    <h6 class="mb-2"><i class="bi bi-envelope-paper"></i> Expéditeur des e-mails</h6>
    <p class="text-muted small mb-3" style="max-width: 680px;">
        Adresse d'expédition utilisée pour les communications de masse aux tiers,
        et en repli pour les types d'opération qui n'ont pas d'adresse configurée.
    </p>

    <div class="row g-2" style="max-width: 680px;">
        <div class="col-md-3">
            <input type="text" class="form-control form-control-sm @error('email_from_name') is-invalid @enderror"
                   wire:model="email_from_name" placeholder="Nom expéditeur" @disabled(\App\Support\Demo::isActive())>
            @error('email_from_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6">
            <input type="email" class="form-control form-control-sm @error('email_from') is-invalid @enderror"
                   wire:model="email_from" placeholder="noreply@monasso.fr" @disabled(\App\Support\Demo::isActive())>
            @error('email_from') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-3">
            @unless(\App\Support\Demo::isActive())
            <button type="button" class="btn btn-sm btn-outline-secondary w-100"
                    {{ $email_from ? '' : 'disabled' }}
                    wire:click="openTestEmailModal">
                <i class="bi bi-envelope"></i> Tester
            </button>
            @endunless
        </div>
    </div>

    @unless(\App\Support\Demo::isActive())
    <div class="d-flex gap-2 mt-4">
        <button type="button" class="btn btn-primary"
                wire:click="sauvegarder"
                wire:loading.attr="disabled" wire:target="sauvegarder">
            <span wire:loading.remove wire:target="sauvegarder">
                <i class="bi bi-floppy"></i> Enregistrer
            </span>
            <span wire:loading wire:target="sauvegarder">
                <span class="spinner-border spinner-border-sm" role="status"></span> Enregistrement…
            </span>
        </button>
        <button type="button" class="btn btn-outline-secondary"
                wire:click="testerConnexion"
                wire:loading.attr="disabled" wire:target="testerConnexion">
            <span wire:loading.remove wire:target="testerConnexion">
                <i class="bi bi-plug"></i> Tester la connexion
            </span>
            <span wire:loading wire:target="testerConnexion">
                <span class="spinner-border spinner-border-sm" role="status"></span> Test en cours…
            </span>
        </button>
    </div>
    @endunless

    @if ($testResult !== null)
        <div class="mt-3 alert {{ $testResult['success'] ? 'alert-success' : 'alert-danger' }} mb-0"
             style="max-width: 680px;">
            @if ($testResult['success'])
                <i class="bi bi-check-circle-fill"></i>
                Connexion SMTP établie.
                @if ($testResult['banner'])
                    <br><small class="text-muted">{{ $testResult['banner'] }}</small>
                @endif
            @else
                <i class="bi bi-x-circle-fill"></i>
                Échec : {{ $testResult['error'] }}
            @endif
        </div>
    @endif

    {{-- Mini-modale test email --}}
    @if($showTestEmailModal)
    <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
         style="background:rgba(0,0,0,.3);z-index:2100"
         wire:click.self="$set('showTestEmailModal', false)">
        <div class="bg-white rounded-3 shadow p-4" style="max-width:400px;width:100%">
            <h6 class="mb-3"><i class="bi bi-envelope me-1"></i> Envoyer un email de test</h6>
            <p class="small text-muted mb-2">Expéditeur : {{ $email_from_name ? $email_from_name . ' <' . $email_from . '>' : $email_from }}</p>
            <div class="mb-3">
                <label class="form-label small">Adresse destinataire</label>
                <input type="email" wire:model="testEmailTo"
                       class="form-control form-control-sm @error('testEmailTo') is-invalid @enderror"
                       placeholder="votre@email.fr">
                @error('testEmailTo')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            @if($testFlashMessage)
                <div class="alert alert-{{ $testFlashType }} py-1 small">{{ $testFlashMessage }}</div>
            @endif
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        wire:click="$set('showTestEmailModal', false)">
                    Fermer
                </button>
                <button type="button" class="btn btn-sm btn-primary" wire:click="sendTestEmail">
                    <span wire:loading.remove wire:target="sendTestEmail">Envoyer</span>
                    <span wire:loading wire:target="sendTestEmail">Envoi...</span>
                </button>
            </div>
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
