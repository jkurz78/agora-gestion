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

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-infos" data-bs-toggle="tab" data-bs-target="#pane-infos"
                    type="button" role="tab" aria-controls="pane-infos" aria-selected="true">
                <i class="bi bi-building"></i> Informations
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-facturation" data-bs-toggle="tab" data-bs-target="#pane-facturation"
                    type="button" role="tab" aria-controls="pane-facturation" aria-selected="false">
                <i class="bi bi-receipt"></i> Facturation
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-ocr" data-bs-toggle="tab" data-bs-target="#pane-ocr"
                    type="button" role="tab" aria-controls="pane-ocr" aria-selected="false">
                <i class="bi bi-robot"></i> OCR / IA
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-communication" data-bs-toggle="tab" data-bs-target="#pane-communication"
                    type="button" role="tab" aria-controls="pane-communication" aria-selected="false">
                <i class="bi bi-envelope"></i> Communication
            </button>
        </li>
    </ul>

    <div class="tab-content">
        {{-- Onglet Informations --}}
        <div class="tab-pane fade show active" id="pane-infos" role="tabpanel" aria-labelledby="tab-infos">
            <div class="row mt-3">
                <div class="col-lg-8">
                    {{-- Cadre Identité --}}
                    <div class="card mb-3">
                        <div class="card-header py-2"><span class="small fw-semibold">Identité</span></div>
                        <div class="card-body">
                            <div class="mb-0">
                                <label class="form-label small">Nom de l'association <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm @error('nom') is-invalid @enderror"
                                       wire:model="nom">
                                @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
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
                            <input type="file" class="form-control form-control-sm @error('logo') is-invalid @enderror"
                                   wire:model="logo" accept=".png,.jpg,.jpeg">
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
                            <input type="file" wire:model="cachet" class="form-control form-control-sm" accept="image/png,image/jpeg">
                            @error('cachet') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>
            </div>

            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                <span wire:loading.remove><i class="bi bi-floppy"></i> Enregistrer</span>
                <span wire:loading>Enregistrement…</span>
            </button>
        </div>

        {{-- Onglet Facturation --}}
        <div class="tab-pane fade" id="pane-facturation" role="tabpanel" aria-labelledby="tab-facturation">
            <div class="mt-3">
                    <p class="text-muted small mb-4">
                        Ces informations apparaissent sur les <strong>factures</strong> émises par l'application (pied de page, mentions légales, coordonnées bancaires).
                    </p>

                    <div class="mb-3">
                        <label class="form-label">SIRET</label>
                        <input type="text" class="form-control @error('siret') is-invalid @enderror"
                               wire:model="siret" maxlength="14" placeholder="14 chiffres">
                        @error('siret') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Forme juridique</label>
                        <input type="text" class="form-control @error('forme_juridique') is-invalid @enderror"
                               wire:model="forme_juridique" placeholder="Ex : Association loi 1901">
                        @error('forme_juridique') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Conditions de règlement</label>
                        <textarea class="form-control @error('facture_conditions_reglement') is-invalid @enderror"
                                  wire:model="facture_conditions_reglement" rows="2"
                                  placeholder="Ex : Payable à réception"></textarea>
                        @error('facture_conditions_reglement') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Mentions légales</label>
                        <textarea class="form-control @error('facture_mentions_legales') is-invalid @enderror"
                                  wire:model="facture_mentions_legales" rows="3"
                                  placeholder="Ex : TVA non applicable, art. 261-7-1° du CGI"></textarea>
                        @error('facture_mentions_legales') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Mentions pénalités B2B</label>
                        <textarea class="form-control @error('facture_mentions_penalites') is-invalid @enderror"
                                  wire:model="facture_mentions_penalites" rows="3"
                                  placeholder="Pénalités de retard, indemnité forfaitaire de recouvrement…"></textarea>
                        @error('facture_mentions_penalites') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Compte bancaire par défaut</label>
                        <select class="form-select @error('facture_compte_bancaire_id') is-invalid @enderror"
                                wire:model="facture_compte_bancaire_id">
                            <option value="">— Aucun —</option>
                            @foreach($comptesBancaires as $compte)
                                <option value="{{ $compte->id }}">{{ $compte->nom }}@if($compte->iban) — {{ $compte->iban }}@endif</option>
                            @endforeach
                        </select>
                        @error('facture_compte_bancaire_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                        <span wire:loading.remove><i class="bi bi-floppy"></i> Enregistrer</span>
                        <span wire:loading>Enregistrement…</span>
                    </button>

            </div>
        </div>

        {{-- Onglet OCR / IA --}}
        <div class="tab-pane fade" id="pane-ocr" role="tabpanel" aria-labelledby="tab-ocr">
            <div class="mt-3">
                    <p class="text-muted small mb-3">
                        Renseignez une clé API Anthropic pour activer l'analyse automatique des factures fournisseur.
                        L'analyse utilise Claude Vision pour extraire la date, le tiers, les lignes et montants.
                    </p>

                    <div class="mb-4">
                        <label class="form-label">Clé API Anthropic</label>
                        <input type="password" class="form-control @error('anthropic_api_key') is-invalid @enderror"
                               wire:model="anthropic_api_key"
                               placeholder="sk-ant-api03-...">
                        @error('anthropic_api_key') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        @if($anthropic_api_key)
                            <div class="form-text text-success"><i class="bi bi-check-circle"></i> Clé configurée — OCR actif</div>
                        @else
                            <div class="form-text text-muted">OCR désactivé — aucune clé configurée</div>
                        @endif
                    </div>

                    <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                        <span wire:loading.remove><i class="bi bi-floppy"></i> Enregistrer</span>
                        <span wire:loading>Enregistrement…</span>
                    </button>
            </div>
        </div>

        {{-- Onglet Communication --}}
        <div class="tab-pane fade" id="pane-communication" role="tabpanel" aria-labelledby="tab-communication">
            <div class="mt-3">
                <p class="text-muted small mb-3">
                    Adresse d'expédition utilisée pour les communications de masse aux tiers,
                    et en repli pour les types d'opération qui n'ont pas d'adresse configurée.
                </p>

                <div class="mb-4 p-3 bg-light rounded border">
                    <label class="form-label small fw-semibold">Adresse d'expédition</label>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <input type="text" class="form-control form-control-sm @error('email_from_name') is-invalid @enderror"
                                   wire:model="email_from_name" placeholder="Nom expéditeur">
                            @error('email_from_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <input type="email" class="form-control form-control-sm @error('email_from') is-invalid @enderror"
                                   wire:model="email_from" placeholder="noreply@monasso.fr">
                            @error('email_from') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-sm btn-outline-secondary w-100"
                                    {{ $email_from ? '' : 'disabled' }}
                                    wire:click="openTestEmailModal">
                                <i class="bi bi-envelope"></i> Tester
                            </button>
                        </div>
                    </div>
                </div>

                <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                    <span wire:loading.remove><i class="bi bi-floppy"></i> Enregistrer</span>
                    <span wire:loading>Enregistrement…</span>
                </button>
            </div>

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
        </div>
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
