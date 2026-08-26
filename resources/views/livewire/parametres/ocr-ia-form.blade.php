{{-- resources/views/livewire/parametres/ocr-ia-form.blade.php --}}
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
    @if (session('success'))
        <div class="alert alert-success alert-dismissible mb-4">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="mt-3">
        <p class="text-muted small mb-3">
            Renseignez une clé API Anthropic pour activer l'analyse automatique des factures fournisseur.
            L'analyse utilise Claude Vision pour extraire la date, le tiers, les lignes et montants.
        </p>

        <div class="mb-4">
            <label class="form-label">
                Clé API Anthropic
                @if ($cleDejaEnregistree)
                    <span class="badge bg-info ms-1">déjà enregistrée</span>
                @endif
            </label>
            <input type="password" class="form-control @error('anthropic_api_key') is-invalid @enderror"
                   wire:model="anthropic_api_key" autocomplete="new-password"
                   placeholder="{{ $cleDejaEnregistree ? '●●●●●●●● (laisser vide pour conserver)' : 'sk-ant-api03-...' }}">
            @error('anthropic_api_key') <div class="invalid-feedback">{{ $message }}</div> @enderror
            @if ($cleDejaEnregistree)
                <div class="form-text text-success"><i class="bi bi-check-circle"></i> Clé configurée — OCR actif. Laisser vide pour conserver la valeur actuelle.</div>
            @else
                <div class="form-text text-muted">OCR désactivé — aucune clé configurée</div>
            @endif
        </div>

        <div class="mb-4">
            <label class="form-label">Modèle d'analyse</label>
            <div class="input-group">
                <select class="form-select @error('invoice_ocr_model') is-invalid @enderror"
                        wire:model="invoice_ocr_model">
                    <option value="">Modèle par défaut</option>
                    @foreach($availableOcrModels as $modelId => $modelLabel)
                        <option value="{{ $modelId }}">{{ $modelLabel }}</option>
                    @endforeach
                </select>
                <button type="button" class="btn btn-outline-secondary"
                        wire:click="chargerModelesOcr" wire:loading.attr="disabled" wire:target="chargerModelesOcr">
                    <span wire:loading.remove wire:target="chargerModelesOcr"><i class="bi bi-arrow-repeat"></i> Charger les modèles disponibles</span>
                    <span wire:loading wire:target="chargerModelesOcr">Chargement…</span>
                </button>
            </div>
            @error('invoice_ocr_model') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            @if($ocrModelsFlash)
                <div class="form-text text-{{ $ocrModelsFlashType === 'success' ? 'success' : ($ocrModelsFlashType === 'danger' ? 'danger' : 'warning') }}">
                    {{ $ocrModelsFlash }}
                </div>
            @else
                <div class="form-text text-muted">
                    Cliquez sur « Charger les modèles disponibles » pour lister les modèles de votre compte Anthropic, puis sélectionnez-en un. Laissé sur « par défaut », l'application choisit un modèle vision adapté.
                </div>
            @endif
        </div>

        <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
            <span wire:loading.remove><i class="bi bi-floppy"></i> Enregistrer</span>
            <span wire:loading>Enregistrement…</span>
        </button>
    </div>

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
</div>
