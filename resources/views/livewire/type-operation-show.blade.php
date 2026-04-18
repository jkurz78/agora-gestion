<div x-data="{
    isDirty: false,
    ready: false,
    showUnsavedModal: false,
    pendingUrl: '',
    navigateTo(url) {
        if (this.isDirty) {
            this.pendingUrl = url;
            this.showUnsavedModal = true;
        } else {
            window.location = url;
        }
    }
}"
x-on:focusin.once="$nextTick(() => ready = true)"
x-on:input="if (ready) isDirty = true"
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
">
    @if($flashMessage)
        <div class="alert alert-{{ $flashType }} alert-dismissible fade show">
            {{ $flashMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" wire:click="$set('flashMessage', '')"></button>
        </div>
    @endif

    {{-- Zone haute : onglets (fond gris, identique OperationDetail) --}}
    <style>
        .nav-gestion .nav-link { color: #666; background: transparent; border: 1px solid transparent; font-size: 13px; padding: 6px 12px; }
        .nav-gestion .nav-link:hover:not(.disabled) { color: #A9014F; }
        .nav-gestion .nav-link.active { color: #A9014F; font-weight: 600; background: #fff; border-color: #dee2e6 #dee2e6 #fff; }
    </style>
    <div style="background: #eef0f3; margin: -1rem -1rem 0; padding: 1rem 1rem 0;">
        @if($typeOperationId)
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    @if($existingLogoPath && $existingLogoUrl)
                        <img src="{{ $existingLogoUrl }}" alt="" style="width:28px;height:28px;object-fit:cover;border-radius:4px">
                    @endif
                    <span class="fw-semibold">{{ $nom }}</span>
                    @if(!$actif)
                        <span class="badge bg-secondary">Inactif</span>
                    @endif
                </div>
                @if($operationsCount > 0)
                    <span data-bs-toggle="tooltip" title="Utilisé par {{ $operationsCount }} opération(s)">
                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled style="pointer-events:none">
                            <i class="bi bi-trash me-1"></i>Supprimer
                        </button>
                    </span>
                @else
                    <button type="button" class="btn btn-sm btn-outline-danger"
                            wire:click="delete"
                            wire:confirm="Supprimer ce type d'opération ? Cette action est irréversible.">
                        <i class="bi bi-trash me-1"></i>Supprimer
                    </button>
                @endif
            </div>
        @endif

        <ul class="nav nav-tabs nav-gestion mb-0" style="border-bottom: none;">
            <li class="nav-item">
                <button class="nav-link {{ $activeTab === 'general' ? 'active' : '' }}" wire:click="setTab('general')">
                    <i class="bi bi-gear me-1"></i>Général
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link {{ $activeTab === 'tarifs' ? 'active' : '' }}" wire:click="setTab('tarifs')">
                    <i class="bi bi-currency-euro me-1"></i>Tarifs ({{ count($tarifs) }})
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link {{ $activeTab === 'seances' ? 'active' : '' }}" wire:click="setTab('seances')">
                    <i class="bi bi-calendar-week me-1"></i>Séances ({{ count($seanceTitres) }})
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link {{ $activeTab === 'emails' ? 'active' : '' }}" wire:click="setTab('emails')">
                    <i class="bi bi-envelope me-1"></i>Emails
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link {{ $activeTab === 'formulaire' ? 'active' : '' }}" wire:click="setTab('formulaire')">
                    <i class="bi bi-ui-checks me-1"></i>Formulaire
                </button>
            </li>
        </ul>
    </div>

    {{-- ── Onglet Général ─────────────────────────────────────────── --}}
    @if($activeTab === 'general')
    <div class="mt-3">
        {{-- Toggle Actif en haut --}}
        <div class="border rounded p-3 mb-3 d-flex align-items-center justify-content-between">
            <div>
                <div class="form-check form-switch mb-0">
                    <input type="checkbox" wire:model="actif" class="form-check-input" id="optActif">
                    <label class="form-check-label fw-semibold" for="optActif">Actif</label>
                </div>
                <small class="text-muted">Un type inactif n'apparaît plus dans les sélecteurs lors de la création d'une opération.</small>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                {{-- Cadre Identification --}}
                <div class="card mb-3">
                    <div class="card-header py-2"><span class="small fw-semibold">Identification</span></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label small">Nom <span class="text-danger">*</span></label>
                            <input type="text" wire:model="nom" class="form-control form-control-sm @error('nom') is-invalid @enderror" maxlength="150">
                            @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small">Libellé avec article</label>
                            <input type="text" wire:model="libelle_article" class="form-control form-control-sm" maxlength="150"
                                   placeholder="Ex: le parcours, la formation, la journée de sensibilisation...">
                            <div class="form-text" style="font-size:11px">Utilisé dans les attestations et emails : « dans le cadre <strong>du</strong> parcours... », « inscription <strong>à la</strong> formation... »</div>
                        </div>

                        <div class="mb-0">
                            <label class="form-label small">Description</label>
                            <textarea wire:model="description" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>
                </div>

                {{-- Cadre Comptabilité --}}
                <div class="card mb-3">
                    <div class="card-header py-2"><span class="small fw-semibold">Comptabilité</span></div>
                    <div class="card-body">
                        <div class="mb-0">
                            <label class="form-label small">Activité (sous-catégorie comptable) <span class="text-danger">*</span></label>
                            <select wire:model="sous_categorie_id" class="form-select form-select-sm @error('sous_categorie_id') is-invalid @enderror">
                                <option value="">— Choisir —</option>
                                @foreach($sousCategories as $sc)
                                    <option value="{{ $sc->id }}">{{ $sc->nom }} ({{ $sc->categorie?->nom }})</option>
                                @endforeach
                            </select>
                            @error('sous_categorie_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>

                {{-- Cadre Options --}}
                <div class="card mb-3">
                    <div class="card-header py-2"><span class="small fw-semibold">Options</span></div>
                    <div class="card-body">
                        <div class="form-check form-switch">
                            <input type="checkbox" wire:model="reserve_adherents" class="form-check-input" id="optAdherents">
                            <label class="form-check-label fw-semibold" for="optAdherents">Réservé aux adhérents</label>
                        </div>
                        <small class="text-muted d-block mb-0">
                            Seuls les membres ayant une cotisation active sur l'exercice en cours peuvent s'inscrire.
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                {{-- Cadre Logo --}}
                <div class="card mb-3">
                    <div class="card-header py-2"><span class="small fw-semibold">Logo</span></div>
                    <div class="card-body">
                        <div class="form-text small mb-2">Optionnel. Si défini, remplace le logo de l'association sur les documents produits pour ce type d'opération (émargement, attestations...).</div>
                        <input type="file" wire:model="logo" class="form-control form-control-sm @error('logo') is-invalid @enderror" accept="image/*">
                        @error('logo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        @if($logo)
                            <div class="mt-2">
                                <img src="{{ $logo->temporaryUrl() }}" alt="Aperçu" style="max-height:64px;border-radius:4px">
                            </div>
                        @elseif($existingLogoPath !== '' && $existingLogoUrl)
                            <div class="mt-2">
                                <img src="{{ $existingLogoUrl }}" alt="Logo actuel" style="max-height:64px;border-radius:4px">
                                <span class="text-muted small ms-2">Logo actuel</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ── Onglet Tarifs ──────────────────────────────────────────── --}}
    @if($activeTab === 'tarifs')
    <div class="mt-3 mx-auto" style="max-width:600px">
        <div class="table-responsive">
            <table class="table table-sm table-striped">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Libellé</th>
                        <th class="text-end" style="width:140px">Montant</th>
                        <th style="width:50px"></th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $sortedTarifs = collect($tarifs)->sortByDesc(fn ($t) => (float) str_replace(',', '.', $t['montant']));
                    @endphp
                    @forelse ($sortedTarifs as $index => $tarif)
                        <tr>
                            <td class="small">{{ $tarif['libelle'] }}</td>
                            <td class="text-end small">{{ number_format((float) str_replace(',', '.', $tarif['montant']), 2, ',', ' ') }}&nbsp;&euro;</td>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-link text-danger p-0"
                                        wire:click="removeTarif({{ $index }})" title="Retirer">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-muted small text-center py-3">Aucun tarif défini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <p class="small text-muted mb-2"><i class="bi bi-plus-circle me-1"></i> Ajouter un tarif</p>
            <div class="row g-2 align-items-end">
                <div class="col">
                    <input type="text" wire:model="newTarifLibelle" class="form-control form-control-sm" placeholder="Libellé du tarif">
                </div>
                <div class="col-auto" style="width:140px">
                    <input type="text" wire:model="newTarifMontant" class="form-control form-control-sm text-end" placeholder="0,00">
                    @error('newTarifMontant') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-sm btn-outline-success" wire:click="addTarif" title="Ajouter">
                        <i class="bi bi-plus-lg"></i> Ajouter
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ── Onglet Séances ─────────────────────────────────────────── --}}
    @if($activeTab === 'seances')
    <div class="mt-3 mx-auto" style="max-width:600px">
        {{-- Nombre de séances avec +/- --}}
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="small fw-semibold">Nombre de séances :</span>
            <div class="d-flex align-items-center gap-1">
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="decrementSeances"
                        x-on:click="isDirty = true"
                        {{ ($nombre_seances === '' || (int)$nombre_seances <= 0) ? 'disabled' : '' }}>
                    <i class="bi bi-dash"></i>
                </button>
                <span class="fw-bold px-2" style="min-width:30px;text-align:center">{{ $nombre_seances !== '' ? $nombre_seances : '0' }}</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="incrementSeances"
                        x-on:click="isDirty = true">
                    <i class="bi bi-plus"></i>
                </button>
            </div>
        </div>

        @if(count($seanceTitres) === 0)
            <div class="text-center text-muted py-4">
                <i class="bi bi-calendar-week" style="font-size:2rem"></i>
                <p class="mt-2 mb-0">Ajoutez des séances avec le bouton + ci-dessus.</p>
            </div>
        @else
            <p class="small text-muted mb-3">Définissez les thèmes par défaut de chaque séance. Ces titres seront pré-remplis lors de la création d'une opération utilisant ce type.</p>
            <div class="table-responsive">
                <table class="table table-sm table-striped">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th style="width:60px" class="text-center">N°</th>
                            <th>Titre / Thème</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($seanceTitres as $idx => $seance)
                            <tr>
                                <td class="text-center align-middle fw-semibold">{{ $seance['numero'] }}</td>
                                <td>
                                    <input type="text" wire:model="seanceTitres.{{ $idx }}.titre"
                                           class="form-control form-control-sm"
                                           placeholder="Séance {{ $seance['numero'] }}">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    @endif

    {{-- ── Onglet Emails ──────────────────────────────────────────── --}}
    @if($activeTab === 'emails')
    <div class="mt-3">
        <div class="mb-3 p-3 bg-light rounded border">
            <label class="form-label small fw-semibold">Adresse d'expédition</label>
            <div class="row g-2">
                <div class="col-md-3">
                    <input type="text" wire:model="email_from_name" class="form-control form-control-sm" placeholder="Nom expéditeur">
                </div>
                <div class="col-md-6">
                    <input type="email" wire:model.blur="email_from"
                           class="form-control form-control-sm @error('email_from') is-invalid @enderror"
                           placeholder="adresse@exemple.fr">
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
            <div class="form-text mt-2" style="font-size:11px">
                Si non renseignée, l'<a href="{{ route('parametres.association') }}" target="_blank">adresse de l'association</a> sera utilisée en repli.
            </div>
        </div>

        <ul class="nav nav-pills nav-fill mb-3">
            @foreach (\App\Enums\CategorieEmail::cases() as $cat)
                @if($cat === \App\Enums\CategorieEmail::Message || $cat === \App\Enums\CategorieEmail::Communication) @continue @endif
                <li class="nav-item">
                    <button class="nav-link {{ $emailSubTab === $cat->value ? 'active' : '' }}"
                            wire:click="$set('emailSubTab', '{{ $cat->value }}')" type="button">
                        {{ $cat->label() }}
                    </button>
                </li>
            @endforeach
        </ul>

        @php
            $tplData = $emailTemplates[$emailSubTab] ?? null;
            $emailHelp = match($emailSubTab) {
                'formulaire' => 'Envoyé au participant avec le lien vers le formulaire d\'inscription en ligne. Contient le code d\'accès et la date d\'expiration.',
                'attestation' => 'Envoyé au participant avec son attestation de présence en pièce jointe, après chaque séance ou en récapitulatif.',
                'document' => 'Envoyé lors de la transmission d\'un document (facture, devis, avoir...) au destinataire.',
                default => null,
            };
        @endphp
        @if($emailHelp)
            <div class="alert alert-light border py-2 px-3 small mb-3">
                <i class="bi bi-info-circle me-1"></i> {{ $emailHelp }}
            </div>
        @endif
        @if($tplData)
            <div class="border rounded p-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="d-flex gap-2 align-items-center">
                        <span class="small fw-semibold">Modèle :</span>
                        @if($tplData['is_default'])
                            <span class="badge bg-secondary">Par défaut</span>
                        @else
                            <span class="badge bg-primary">Personnalisé — {{ $nom ?: 'ce type' }}</span>
                        @endif
                    </div>
                    @if($tplData['is_default'])
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                wire:click="personnaliserTemplate('{{ $emailSubTab }}')">
                            <i class="bi bi-pencil"></i> Personnaliser
                        </button>
                    @else
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    wire:click="revenirAuDefaut('{{ $emailSubTab }}')"
                                    wire:confirm="Supprimer la personnalisation et revenir au modèle par défaut ? Cette action est immédiate.">
                                <i class="bi bi-arrow-counterclockwise"></i> Revenir au défaut
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning"
                                    wire:click="$set('showPromoteConfirm', true)">
                                <i class="bi bi-arrow-up-circle"></i> Promouvoir en défaut
                            </button>
                        </div>
                    @endif
                </div>

                <div class="mb-3">
                    @php $isEditable = !$tplData['is_default']; @endphp
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <label class="form-label small fw-semibold mb-0">Objet</label>
                        @if($isEditable)
                        <div x-data="{ openGroup: null }" @click.outside="openGroup = null" class="position-relative">
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1" style="font-size:11px" @click="openGroup = openGroup ? null : 'root'">
                                <i class="bi bi-braces me-1"></i>Variables
                            </button>
                            <div class="dropdown-menu" :class="openGroup && 'show'" style="min-width:160px;font-size:12px;z-index:2200">
                                @php
                                    $objtVarGroups = match($emailSubTab) {
                                        'formulaire' => [
                                            'Participant' => ['{prenom}' => 'Prénom', '{nom}' => 'Nom'],
                                            'Opération' => ['{operation}' => 'Opération', '{type_operation}' => 'Type', '{date_debut}' => 'Date début', '{date_fin}' => 'Date fin', '{nb_seances}' => 'Nb séances'],
                                            'Formulaire' => ['{url}' => 'URL', '{code}' => 'Code', '{date_expiration}' => 'Date expiration'],
                                            'Association' => ['{association}' => 'Nom'],
                                        ],
                                        'attestation' => [
                                            'Participant' => ['{prenom}' => 'Prénom', '{nom}' => 'Nom'],
                                            'Opération' => ['{operation}' => 'Opération', '{type_operation}' => 'Type', '{date_debut}' => 'Date début', '{date_fin}' => 'Date fin', '{nb_seances}' => 'Nb séances'],
                                            'Séance' => ['{numero_seance}' => 'N° séance', '{date_seance}' => 'Date séance'],
                                            'Association' => ['{association}' => 'Nom'],
                                        ],
                                        'document' => [
                                            'Destinataire' => ['{prenom}' => 'Prénom', '{nom}' => 'Nom'],
                                            'Document' => ['{type_document}' => 'Type', '{type_document_uc}' => 'Type (maj.)', '{type_document_article}' => 'Avec article', '{numero_document}' => 'Numéro', '{date_document}' => 'Date', '{montant_total}' => 'Montant'],
                                            'Association' => ['{association}' => 'Nom'],
                                        ],
                                        default => [],
                                    };
                                @endphp
                                @foreach($objtVarGroups as $groupName => $vars)
                                    <div class="position-relative" @mouseenter="openGroup = '{{ $groupName }}'" @mouseleave="openGroup = openGroup === '{{ $groupName }}' ? 'root' : openGroup">
                                        <a class="dropdown-item d-flex justify-content-between" href="#">
                                            {{ $groupName }} <i class="bi bi-chevron-right"></i>
                                        </a>
                                        <div class="dropdown-menu" :class="openGroup === '{{ $groupName }}' && 'show'"
                                             style="position:absolute;left:100%;top:0;min-width:260px;z-index:2200">
                                            @foreach($vars as $var => $desc)
                                                @if(!str_starts_with($var, '{logo'))
                                                <a class="dropdown-item" href="#"
                                                   @click.prevent="
                                                       const input = document.getElementById('objet-input-{{ $emailSubTab }}');
                                                       const pos = input.selectionStart || input.value.length;
                                                       const val = input.value;
                                                       input.value = val.substring(0, pos) + '{{ $var }}' + val.substring(pos);
                                                       input.dispatchEvent(new Event('input', { bubbles: true }));
                                                       openGroup = null;
                                                       input.focus();
                                                   ">
                                                    <code class="text-primary">{{ $var }}</code> <span class="text-muted">{{ $desc }}</span>
                                                </a>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        @endif
                    </div>
                    <input type="text" class="form-control form-control-sm"
                           id="objet-input-{{ $emailSubTab }}"
                           wire:model="emailTemplates.{{ $emailSubTab }}.objet"
                           {{ $tplData['is_default'] ? 'readonly' : '' }}>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-semibold">Corps</label>
                </div>
                <div wire:key="tinymce-{{ $emailSubTab }}-{{ $tplData['is_default'] ? 'ro' : 'rw' }}"
                     wire:ignore
                     x-data="tinymceEditor('{{ $emailSubTab }}', {{ $tplData['is_default'] ? 'true' : 'false' }})"
                     x-init="init()">
                    <textarea x-ref="editor">{!! $tplData['corps'] !!}</textarea>
                </div>

                @if($emailSubTab === 'formulaire')
                    <div class="form-text small mt-2">Le lien, le code et la date d'expiration sont ajoutés automatiquement sous le corps.</div>
                @elseif($emailSubTab === 'document')
                    <div class="form-text small mt-2">Le document est joint automatiquement à l'email.</div>
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
                    <input type="email" wire:model="testEmailTo" class="form-control form-control-sm @error('testEmailTo') is-invalid @enderror"
                           placeholder="votre@email.fr">
                    @error('testEmailTo')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                @if($flashMessage && ($flashType === 'success' || $flashType === 'danger'))
                    <div class="alert alert-{{ $flashType }} py-1 small">{{ $flashMessage }}</div>
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

        {{-- Promote confirm mini-modal --}}
        @if($showPromoteConfirm)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.3);z-index:2200"
             wire:click.self="$set('showPromoteConfirm', false)">
            <div class="bg-white rounded-3 shadow p-4" style="max-width:400px;width:100%">
                <h6 class="mb-3"><i class="bi bi-arrow-up-circle me-1"></i> Promouvoir en défaut</h6>
                <p class="small mb-3">Remplacer le modèle par défaut <strong>{{ \App\Enums\CategorieEmail::tryFrom($emailSubTab)?->label() }}</strong> par cette version personnalisée ? Tous les types d'opération sans personnalisation utiliseront ce nouveau défaut.</p>
                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            wire:click="$set('showPromoteConfirm', false)">
                        Annuler
                    </button>
                    <button type="button" class="btn btn-sm btn-warning"
                            onclick="syncTinyMCEForPromote('{{ $emailSubTab }}')">
                        Confirmer
                    </button>
                </div>
            </div>
        </div>
        @endif
    </div>
    @endif

    {{-- ── Onglet Formulaire ──────────────────────────────────────── --}}
    @if($activeTab === 'formulaire')
    <div class="mt-3">
        <div class="form-check form-switch mb-3">
            <input type="checkbox" wire:model.live="formulaireActif" class="form-check-input" id="optFormulaireActif">
            <label class="form-check-label fw-semibold" for="optFormulaireActif">Utiliser l'envoi de formulaires</label>
        </div>

        <div class="ms-4" x-data x-bind:class="!$wire.formulaireActif && 'opacity-50'">
            <div class="form-check form-switch mb-2">
                <input type="checkbox" wire:model.live="formulairePrescripteur" class="form-check-input" id="optPrescripteur"
                       x-bind:disabled="!$wire.formulaireActif">
                <label class="form-check-label" for="optPrescripteur">Demander les coordonnées du prescripteur</label>
            </div>
            <div class="ms-4 mb-3" x-show="$wire.formulairePrescripteur && $wire.formulaireActif" x-cloak>
                <label class="form-label small">Titre du bloc</label>
                <input type="text" wire:model="formulairePrescripteurTitre" class="form-control form-control-sm"
                       placeholder="Je vous suis adressé(e) par">
            </div>

            <div class="form-check form-switch mb-2">
                <input type="checkbox" wire:model.live="formulaireParcoursTherapeutique" class="form-check-input" id="optParcours"
                       x-bind:disabled="!$wire.formulaireActif">
                <label class="form-check-label" for="optParcours">Récolter les informations nécessaires aux parcours thérapeutiques</label>
            </div>
            <div class="ms-4 mb-3" x-show="$wire.formulaireParcoursTherapeutique && $wire.formulaireActif" x-cloak>
                <label class="form-label small">Attestation médicale (pièce jointe)</label>
                <div class="form-text small mb-2">Document joint au formulaire d'inscription que le participant doit imprimer, faire remplir par son médecin et renvoyer.</div>
                <input type="file" wire:model="attestationMedicale" class="form-control form-control-sm @error('attestationMedicale') is-invalid @enderror" accept=".pdf,.doc,.docx">
                @error('attestationMedicale') <div class="invalid-feedback">{{ $message }}</div> @enderror
                @if($existingAttestationPath && $existingAttestationUrl)
                    <div class="mt-1">
                        <a href="{{ $existingAttestationUrl }}" target="_blank" class="small">
                            <i class="bi bi-file-earmark-pdf"></i> Voir le fichier actuel
                        </a>
                    </div>
                @endif
            </div>

            <div class="form-check form-switch mb-2">
                <input type="checkbox" wire:model.live="formulaireDroitImage" class="form-check-input" id="optDroitImage"
                       x-bind:disabled="!$wire.formulaireActif">
                <label class="form-check-label" for="optDroitImage">Demander les autorisations photo et vidéo</label>
            </div>
            <div class="ms-4 mb-3" x-show="$wire.formulaireDroitImage && $wire.formulaireActif" x-cloak>
                <label class="form-label small">Qualificatif des parcours</label>
                <input type="text" wire:model="formulaireQualificatifAtelier" class="form-control form-control-sm"
                       placeholder="thérapeutique">
            </div>
        </div>
    </div>
    @endif

    {{-- ── Bouton Enregistrer (visible quand dirty) ─────────────────── --}}
    <div x-show="isDirty" x-transition
         style="position: sticky; bottom: 20px; text-align: right; z-index: 1040;">
        <button type="button" class="btn btn-primary shadow"
                onclick="syncTinyMCEAndSave(this)"
                x-on:click="$nextTick(() => isDirty = false)">
            <i class="bi bi-check-lg me-1"></i> Enregistrer
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
                        <button class="btn btn-sm btn-primary" @click="
                            const editorContent = {};
                            (typeof tinymce !== 'undefined' ? tinymce.get() : []).forEach(ed => {
                                const cat = ed.getBody()?.dataset?.categorie;
                                if (cat) editorContent[cat] = ed.getContent();
                            });
                            const method = Object.keys(editorContent).length > 0 ? $wire.saveWithEditorContent(editorContent) : $wire.save();
                            method.then(() => { isDirty = false; showUnsavedModal = false; window.location = pendingUrl; });
                        ">
                            Enregistrer et quitter
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    @push('scripts')
    <script>
        function tinymceEditor(categorie, readonly) {
            return {
                init() {
                    const el = this.$refs.editor;
                    if (!el) return;

                    const varGroups = {
                        'formulaire': {
                            'Participant': {'{prenom}': 'Prénom', '{nom}': 'Nom'},
                            'Opération': {'{operation}': 'Opération', '{type_operation}': 'Type', '{logo_operation}': 'Logo opération', '{date_debut}': 'Date début', '{date_fin}': 'Date fin', '{nb_seances}': 'Nb séances'},
                            'Formulaire': {'{url}': 'URL', '{code}': 'Code', '{date_expiration}': 'Date expiration'},
                            'Association': {'{association}': 'Nom', '{logo}': 'Logo'},
                        },
                        'attestation': {
                            'Participant': {'{prenom}': 'Prénom', '{nom}': 'Nom'},
                            'Opération': {'{operation}': 'Opération', '{type_operation}': 'Type', '{logo_operation}': 'Logo opération', '{date_debut}': 'Date début', '{date_fin}': 'Date fin', '{nb_seances}': 'Nb séances'},
                            'Séance': {'{numero_seance}': 'N° séance', '{date_seance}': 'Date séance'},
                            'Association': {'{association}': 'Nom', '{logo}': 'Logo'},
                        },
                        'document': {
                            'Destinataire': {'{prenom}': 'Prénom', '{nom}': 'Nom'},
                            'Document': {'{type_document}': 'Type', '{type_document_uc}': 'Type (maj.)', '{type_document_article}': 'Avec article', '{numero_document}': 'Numéro', '{date_document}': 'Date', '{montant_total}': 'Montant'},
                            'Association': {'{association}': 'Nom', '{logo}': 'Logo'},
                        },
                    };

                    const groups = varGroups[categorie] || {};
                    let editorRef = null;

                    const menuItems = Object.entries(groups).map(([groupName, vars]) => ({
                        type: 'nestedmenuitem',
                        text: groupName,
                        getSubmenuItems: () => Object.entries(vars).map(([key, label]) => ({
                            type: 'menuitem',
                            text: key + ' — ' + label,
                            onAction: () => {
                                if (editorRef) {
                                    editorRef.focus();
                                    editorRef.insertContent(key);
                                }
                            },
                        })),
                    }));

                    tinymce.init({
                        target: el,
                        language: 'fr_FR',
                        language_url: '/vendor/tinymce/langs/fr_FR.js',
                        height: 350,
                        menubar: readonly ? false : 'edit insert format table',
                        statusbar: !readonly,
                        promotion: false,
                        plugins: 'link lists noneditable table image media code',
                        toolbar: readonly
                            ? false
                            : [
                                'undo redo | blocks fontfamily fontsize | bold italic underline forecolor backcolor',
                                'alignleft aligncenter alignright | bullist numlist | table image media link | variablesButton | code',
                            ],
                        readonly: readonly,
                        block_formats: 'Paragraphe=p; Titre 1=h1; Titre 2=h2; Titre 3=h3',
                        font_family_formats: 'Arial=arial,helvetica,sans-serif; Georgia=georgia,serif; Courier New=courier new,courier,monospace; Verdana=verdana,geneva',
                        font_size_formats: '10px 12px 14px 16px 18px 20px 24px 28px 32px',
                        table_default_styles: { 'border-collapse': 'collapse', 'width': '100%' },
                        table_default_attributes: { border: '1', cellpadding: '6', cellspacing: '0' },
                        image_title: true,
                        image_caption: true,
                        image_advtab: true,
                        image_class_list: [
                            { title: 'En ligne (défaut)', value: '' },
                            { title: 'Flottante à gauche', value: 'img-float-left' },
                            { title: 'Flottante à droite', value: 'img-float-right' },
                            { title: 'Centrée (bloc)', value: 'img-center' },
                        ],
                        automatic_uploads: false,
                        images_upload_handler: function (blobInfo) {
                            return new Promise(function (resolve) {
                                var reader = new FileReader();
                                reader.onload = function () { resolve(reader.result); };
                                reader.readAsDataURL(blobInfo.blob());
                            });
                        },
                        file_picker_types: 'image',
                        file_picker_callback: function (callback, value, meta) {
                            if (meta.filetype === 'image') {
                                var input = document.createElement('input');
                                input.type = 'file'; input.accept = 'image/*';
                                input.onchange = function () {
                                    var reader = new FileReader();
                                    reader.onload = function () { callback(reader.result, { alt: input.files[0].name }); };
                                    reader.readAsDataURL(input.files[0]);
                                };
                                input.click();
                            }
                        },
                        content_style: 'body { font-family: Arial, sans-serif; font-size: 13px; } table { border-collapse: collapse; } td, th { border: 1px solid #ccc; padding: 6px; } .img-float-left { float: left; margin: 0 16px 12px 0; } .img-float-right { float: right; margin: 0 0 12px 16px; } .img-center { display: block; margin: 12px auto; } img { max-width: 100%; height: auto; }',
                        setup: (editor) => {
                            editorRef = editor;
                            editor.ui.registry.addMenuButton('variablesButton', {
                                text: 'Variables',
                                fetch: (callback) => callback(menuItems),
                            });
                            editor.on('init', () => {
                                editor.getBody().dataset.categorie = categorie;
                            });
                            editor.on('ObjectResized', (e) => {
                                if (e.target.nodeName === 'IMG') {
                                    e.target.style.height = 'auto';
                                    e.target.removeAttribute('height');
                                }
                            });
                            editor.on('input Change', () => {
                                el.closest('[x-data]')?.dispatchEvent(new Event('input', { bubbles: true }));
                            });
                        }
                    });
                }
            };
        }

        function syncTinyMCEAndSave(btn) {
            const editorContent = {};
            tinymce.get().forEach(ed => {
                const cat = ed.getBody()?.dataset?.categorie;
                if (cat) {
                    editorContent[cat] = ed.getContent();
                }
            });

            if (Object.keys(editorContent).length > 0) {
                @this.call('saveWithEditorContent', editorContent);
            } else {
                @this.call('save');
            }
        }

        function syncTinyMCEForPromote(categorie) {
            const editor = tinymce.get().find(ed => ed.getBody()?.dataset?.categorie === categorie);
            if (editor) {
                @this.set('emailTemplates.' + categorie + '.corps', editor.getContent());
            }
            @this.call('promouvoirEnDefaut', categorie);
            @this.set('showPromoteConfirm', false);
        }
    </script>
    @endpush
</div>
