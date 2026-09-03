{{-- resources/views/livewire/parametres/association-form.blade.php --}}
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

    <div class="row mt-3">
        <div class="col-lg-8">
            {{-- Cadre Identité --}}
            <div class="card mb-3">
                <div class="card-header py-2"><span class="small fw-semibold">Identité</span></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small">Nom de l'association <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm @error('nom') is-invalid @enderror"
                               wire:model="nom">
                        @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">SIRET</label>
                        <input type="text" class="form-control form-control-sm @error('siret') is-invalid @enderror"
                               wire:model="siret" maxlength="14" placeholder="14 chiffres">
                        @error('siret') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-0">
                        <label class="form-label small">Forme juridique</label>
                        <input type="text" class="form-control form-control-sm @error('forme_juridique') is-invalid @enderror"
                               wire:model="forme_juridique" placeholder="Ex : Association loi 1901">
                        @error('forme_juridique') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>

            {{-- Cadre Coordonnées --}}
            <div class="card mb-3">
                <div class="card-header py-2"><span class="small fw-semibold">Coordonnées</span></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small">Adresse</label>
                        <input type="text" class="form-control form-control-sm" wire:model="adresse">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small">Code postal</label>
                            <input type="text" class="form-control form-control-sm" wire:model="code_postal">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small">Ville</label>
                            <input type="text" class="form-control form-control-sm" wire:model="ville">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Email</label>
                        <input type="email" class="form-control form-control-sm @error('email') is-invalid @enderror"
                               wire:model="email">
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-0">
                        <label class="form-label small">Téléphone</label>
                        <input type="text" class="form-control form-control-sm" wire:model="telephone">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            {{-- Cadre Logo --}}
            <div class="card mb-3">
                <div class="card-header py-2"><span class="small fw-semibold">Logo</span></div>
                <div class="card-body">
                    @if ($logoUrl)
                        <div class="mb-2">
                            <img src="{{ $logoUrl }}" alt="Logo association" style="max-height: 80px; border-radius: 4px;">
                        </div>
                    @endif
                    <x-zone-depot>
                        <input type="file" class="form-control form-control-sm @error('logo') is-invalid @enderror"
                               wire:model="logo" accept=".png,.jpg,.jpeg">
                    </x-zone-depot>
                    <div class="form-text" style="font-size:11px">PNG ou JPG, max 2 Mo</div>
                    @error('logo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>

            {{-- Cadre Cachet --}}
            <div class="card mb-3">
                <div class="card-header py-2"><span class="small fw-semibold">Cachet et signature du président</span></div>
                <div class="card-body">
                    <div class="form-text mb-2" style="font-size:11px">
                        Apposé sur les <strong>attestations</strong> générées par l'application. PNG ou JPG avec fond transparent de préférence.
                    </div>
                    @if ($cachetUrl)
                        <div class="mb-2">
                            <img src="{{ $cachetUrl }}" alt="Cachet" style="max-height: 80px; border-radius: 4px;">
                        </div>
                    @endif
                    <x-zone-depot>
                        <input type="file" wire:model="cachet" class="form-control form-control-sm" accept="image/png,image/jpeg">
                    </x-zone-depot>
                    @error('cachet') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>
    </div>

    <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
        <span wire:loading.remove><i class="bi bi-floppy"></i> Enregistrer</span>
        <span wire:loading>Enregistrement…</span>
    </button>

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
