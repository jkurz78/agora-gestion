<div>
    @if($flashMessage)
        <div class="alert alert-{{ $flashType }} alert-dismissible fade show">
            {{ $flashMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" wire:click="$set('flashMessage', '')"></button>
        </div>
    @endif

    @if(!$modalOnly)
    {{-- Toolbar --}}
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <div class="d-flex gap-2 align-items-center">
            <label class="form-label mb-0 small text-muted">Filtre :</label>
            <select wire:model.live="filter" class="form-select form-select-sm" style="width:auto">
                <option value="tous">Tous</option>
                <option value="actif">Actifs</option>
                <option value="inactif">Inactifs</option>
            </select>
        </div>
        <button class="btn btn-primary btn-sm" wire:click="openCreate">
            <i class="bi bi-plus-lg"></i> Nouveau type
        </button>
    </div>

    {{-- Table --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover" id="type-operation-table">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>Logo</th>
                    <th class="sortable" data-col="code" style="cursor:pointer">Code <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th class="sortable" data-col="nom" style="cursor:pointer">Nom <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th>Sous-catégorie</th>
                    <th class="text-center">Séances</th>
                    <th class="text-center">Confidentiel</th>
                    <th class="text-center">Adhérents</th>
                    <th class="text-center">Actif</th>
                    <th class="text-center">Tarifs</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody style="color:#555">
                @forelse($types as $type)
                    <tr class="{{ !$type->actif ? 'opacity-50' : '' }}">
                        {{-- Logo --}}
                        <td>
                            @if($type->logo_path)
                                <img src="{{ Storage::disk('public')->url($type->logo_path) }}"
                                     alt="{{ $type->nom }}" style="width:32px;height:32px;object-fit:cover;border-radius:4px">
                            @else
                                <span class="text-muted"><i class="bi bi-image" style="font-size:1.2rem"></i></span>
                            @endif
                        </td>
                        {{-- Code --}}
                        <td class="small fw-semibold" data-sort="{{ $type->code }}">{{ $type->code }}</td>
                        {{-- Nom --}}
                        <td class="small" data-sort="{{ $type->nom }}">{{ $type->nom }}</td>
                        {{-- Sous-catégorie --}}
                        <td class="small">{{ $type->sousCategorie?->nom ?? '—' }}</td>
                        {{-- Séances --}}
                        <td class="text-center small">{{ $type->nombre_seances ?? '—' }}</td>
                        {{-- Confidentiel --}}
                        <td class="text-center">
                            @if($type->confidentiel)
                                <i class="bi bi-circle-fill text-success" style="font-size:.6rem"></i>
                            @else
                                <i class="bi bi-circle-fill" style="font-size:.6rem;color:#ccc"></i>
                            @endif
                        </td>
                        {{-- Adhérents --}}
                        <td class="text-center">
                            @if($type->reserve_adherents)
                                <i class="bi bi-circle-fill text-success" style="font-size:.6rem"></i>
                            @else
                                <i class="bi bi-circle-fill" style="font-size:.6rem;color:#ccc"></i>
                            @endif
                        </td>
                        {{-- Actif --}}
                        <td class="text-center">
                            @if($type->actif)
                                <span class="badge bg-success">Actif</span>
                            @else
                                <span class="badge bg-secondary">Inactif</span>
                            @endif
                        </td>
                        {{-- Tarifs count --}}
                        <td class="text-center">
                            <span class="badge bg-info text-dark">{{ $type->tarifs->count() }}</span>
                        </td>
                        {{-- Actions --}}
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <button class="btn btn-sm btn-outline-primary"
                                        wire:click="openEdit({{ $type->id }})"
                                        title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger"
                                        wire:click="delete({{ $type->id }})"
                                        wire:confirm="Supprimer ce type d'opération ?"
                                        title="Supprimer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">
                            Aucun type d'opération enregistré.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @endif

    {{-- ═══════════════════════════════════════════════════════════
         MODAL CREATE/EDIT
         ═══════════════════════════════════════════════════════════ --}}
    @if($showModal)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.4);z-index:2000">
            <div class="bg-white rounded p-4 shadow" style="width:700px;max-width:95vw;max-height:90vh;overflow-y:auto">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold mb-0">
                            {{ $editingId ? 'Modifier le type d\'opération' : 'Nouveau type d\'opération' }}
                        </h5>
                        @if($activeTab > 1 && ($code || $nom))
                            <small class="text-muted">{{ $code }}{{ $code && $nom ? ' — ' : '' }}{{ $nom }}</small>
                        @endif
                    </div>
                    <button type="button" class="btn-close" wire:click="$set('showModal', false)" title="Fermer"></button>
                </div>

                {{-- Tab navigation --}}
                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item">
                        <button class="nav-link {{ $activeTab === 1 ? 'active' : '' }} {{ ($editingId !== null || $maxVisitedTab >= 1) ? '' : 'disabled' }}"
                                wire:click="goToTab(1)" type="button">Général</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link {{ $activeTab === 2 ? 'active' : '' }} {{ ($editingId !== null || $maxVisitedTab >= 2) ? '' : 'disabled' }}"
                                wire:click="goToTab(2)" type="button">Tarifs</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link {{ $activeTab === 3 ? 'active' : '' }} {{ ($editingId !== null || $maxVisitedTab >= 3) ? '' : 'disabled' }}"
                                wire:click="goToTab(3)" type="button">Emails</button>
                    </li>
                </ul>

                {{-- ── Onglet 1 : Général ─────────────────────────────────── --}}
                @if($activeTab === 1)

                {{-- Code + Nom --}}
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Code <span class="text-danger">*</span></label>
                        <input type="text" wire:model="code" class="form-control form-control-sm @error('code') is-invalid @enderror" maxlength="20">
                        @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small">Nom <span class="text-danger">*</span></label>
                        <input type="text" wire:model="nom" class="form-control form-control-sm @error('nom') is-invalid @enderror" maxlength="150">
                        @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                {{-- Description --}}
                <div class="mb-3">
                    <label class="form-label small">Description</label>
                    <textarea wire:model="description" class="form-control form-control-sm" rows="2"></textarea>
                </div>

                {{-- Sous-catégorie + Nb séances --}}
                <div class="row g-2 mb-3">
                    <div class="col-md-8">
                        <label class="form-label small">Sous-catégorie comptable <span class="text-danger">*</span></label>
                        <select wire:model="sous_categorie_id" class="form-select form-select-sm @error('sous_categorie_id') is-invalid @enderror">
                            <option value="">— Choisir —</option>
                            @foreach($sousCategories as $sc)
                                <option value="{{ $sc->id }}">{{ $sc->nom }} ({{ $sc->categorie?->nom }})</option>
                            @endforeach
                        </select>
                        @error('sous_categorie_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Nb séances</label>
                        <input type="number" wire:model="nombre_seances" class="form-control form-control-sm @error('nombre_seances') is-invalid @enderror" min="1">
                        @error('nombre_seances') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                {{-- Options --}}
                <div class="mb-3">
                    <div class="border rounded p-3 mb-2">
                        <div class="form-check form-switch">
                            <input type="checkbox" wire:model="confidentiel" class="form-check-input" id="optConfidentiel">
                            <label class="form-check-label fw-semibold" for="optConfidentiel">Données confidentielles</label>
                        </div>
                        <small class="text-muted d-block mt-1">
                            Active les champs médicaux (kiné, date de naissance, taille, poids) dans la fiche participant et les séances.
                            Masque la fonction de création de token pour le formulaire d'auto-saisie.
                        </small>
                    </div>
                    <div class="border rounded p-3 mb-2">
                        <div class="form-check form-switch">
                            <input type="checkbox" wire:model="reserve_adherents" class="form-check-input" id="optAdherents">
                            <label class="form-check-label fw-semibold" for="optAdherents">Réservé aux adhérents</label>
                        </div>
                        <small class="text-muted d-block mt-1">
                            Seuls les membres ayant une cotisation active sur l'exercice en cours peuvent s'inscrire.
                            Les participants non adhérents sont signalés en rouge dans la liste.
                        </small>
                    </div>
                    <div class="border rounded p-3">
                        <div class="form-check form-switch">
                            <input type="checkbox" wire:model="actif" class="form-check-input" id="optActif">
                            <label class="form-check-label fw-semibold" for="optActif">Actif</label>
                        </div>
                        <small class="text-muted d-block mt-1">
                            Un type inactif n'apparaît plus dans les sélecteurs lors de la création d'une opération.
                            Les opérations existantes conservent leur type.
                        </small>
                    </div>
                </div>

                {{-- Logo upload --}}
                <div class="mb-3">
                    <label class="form-label small">Logo</label>
                    <input type="file" wire:model="logo" class="form-control form-control-sm @error('logo') is-invalid @enderror" accept="image/*">
                    @error('logo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    @if($logo)
                        <div class="mt-2">
                            <img src="{{ $logo->temporaryUrl() }}" alt="Aperçu" style="max-height:64px;border-radius:4px">
                        </div>
                    @elseif($existingLogoPath !== '')
                        <div class="mt-2">
                            <img src="{{ Storage::disk('public')->url($existingLogoPath) }}" alt="Logo actuel" style="max-height:64px;border-radius:4px">
                            <span class="text-muted small ms-2">Logo actuel</span>
                        </div>
                    @endif
                </div>

                @endif

                {{-- ── Onglet 2 : Tarifs ──────────────────────────────────── --}}
                @if($activeTab === 2)

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

                {{-- Ajout d'un tarif (hors tableau) --}}
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

                @endif

                {{-- ── Onglet 3 : Emails ──────────────────────────────────── --}}
                @if($activeTab === 3)

                {{-- Adresse expéditeur --}}
                <div class="mb-3 p-3 bg-light rounded border">
                    <label class="form-label small fw-semibold">Adresse d'expédition</label>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <input type="text" wire:model="email_from_name" class="form-control form-control-sm" placeholder="Nom expéditeur">
                        </div>
                        <div class="col-md-6">
                            <input type="email" wire:model.live.debounce.500ms="email_from"
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
                </div>

                {{-- Sous-onglets email --}}
                <ul class="nav nav-pills nav-fill mb-3">
                    @foreach (\App\Enums\CategorieEmail::cases() as $cat)
                        <li class="nav-item">
                            <button class="nav-link {{ $emailSubTab === $cat->value ? 'active' : '' }}"
                                    wire:click="$set('emailSubTab', '{{ $cat->value }}')" type="button">
                                {{ $cat->label() }}
                            </button>
                        </li>
                    @endforeach
                </ul>

                {{-- Template content --}}
                @php $tplData = $emailTemplates[$emailSubTab] ?? null; @endphp
                @if($tplData)
                    <div class="border rounded p-3">
                        {{-- Modèle : boutons défaut / personnaliser --}}
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
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        wire:click="revenirAuDefaut('{{ $emailSubTab }}')">
                                    <i class="bi bi-arrow-counterclockwise"></i> Revenir au défaut
                                </button>
                            @endif
                        </div>

                        {{-- Subject --}}
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Objet</label>
                            <input type="text" class="form-control form-control-sm"
                                   wire:model="emailTemplates.{{ $emailSubTab }}.objet"
                                   {{ $tplData['is_default'] ? 'readonly' : '' }}>
                        </div>

                        {{-- Body — TinyMCE --}}
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Corps</label>
                        </div>
                        {{-- z-index TinyMCE dropdowns above modal --}}
                        <style>.tox-tinymce-aux { z-index: 2100 !important; }</style>
                        <div wire:key="tinymce-{{ $emailSubTab }}-{{ $tplData['is_default'] ? 'ro' : 'rw' }}"
                             wire:ignore.self
                             x-data="tinymceEditor('{{ $emailSubTab }}', {{ $tplData['is_default'] ? 'true' : 'false' }})"
                             x-init="init()">
                            <textarea x-ref="editor">{!! $tplData['corps'] !!}</textarea>
                        </div>

                        <div class="form-text small mt-2">
                            Variables :
                            @foreach (\App\Enums\CategorieEmail::from($emailSubTab)->variables() as $var => $desc)
                                <code title="{{ $desc }}">{{ $var }}</code>
                            @endforeach
                            @if($emailSubTab === 'formulaire')
                                — Le lien, le code et la date d'expiration sont ajoutés automatiquement sous le corps.
                            @else
                                — Le document est joint automatiquement à l'email.
                            @endif
                        </div>
                    </div>
                @endif

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

                {{-- Navigation buttons --}}
                <div class="d-flex justify-content-between mt-4">
                    @if($editingId !== null)
                        {{-- Mode édition : Annuler + Enregistrer en permanence --}}
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showModal', false)">Annuler</button>
                        <button type="button" class="btn btn-sm btn-primary" onclick="syncTinyMCEAndSave(this)">
                            <i class="bi bi-check-lg"></i> Enregistrer
                        </button>
                    @else
                        {{-- Mode création : Suivant/Précédent + Enregistrer sur dernier onglet --}}
                        @if($activeTab > 1)
                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="previousTab">
                                <i class="bi bi-arrow-left"></i> Précédent
                            </button>
                        @else
                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showModal', false)">Annuler</button>
                        @endif

                        @if($activeTab < 3)
                            <button type="button" class="btn btn-sm btn-primary" wire:click="nextTab">
                                Suivant <i class="bi bi-arrow-right"></i>
                            </button>
                        @else
                            <button type="button" class="btn btn-sm btn-primary" onclick="syncTinyMCEAndSave(this)">
                                <i class="bi bi-check-lg"></i> Enregistrer
                            </button>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════
         JS SORTING
         ═══════════════════════════════════════════════════════════ --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const table = document.getElementById('type-operation-table');
            if (!table) return;

            const headers = table.querySelectorAll('th.sortable');
            let currentCol = null;
            let ascending = true;

            headers.forEach(function (th) {
                th.addEventListener('click', function () {
                    const col = th.dataset.col;
                    if (currentCol === col) {
                        ascending = !ascending;
                    } else {
                        currentCol = col;
                        ascending = true;
                    }

                    const tbody = table.querySelector('tbody');
                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    const colIndex = Array.from(th.parentElement.children).indexOf(th);

                    rows.sort(function (a, b) {
                        const aCell = a.children[colIndex];
                        const bCell = b.children[colIndex];
                        if (!aCell || !bCell) return 0;

                        const aVal = (aCell.dataset.sort || aCell.textContent || '').trim().toLowerCase();
                        const bVal = (bCell.dataset.sort || bCell.textContent || '').trim().toLowerCase();

                        const result = aVal.localeCompare(bVal, 'fr');
                        return ascending ? result : -result;
                    });

                    rows.forEach(function (row) { tbody.appendChild(row); });

                    // Update sort indicators
                    headers.forEach(function (h) {
                        const icon = h.querySelector('i');
                        if (icon) icon.className = 'bi bi-arrow-down-up';
                    });
                    const icon = th.querySelector('i');
                    if (icon) {
                        icon.className = ascending ? 'bi bi-arrow-down' : 'bi bi-arrow-up';
                    }
                });
            });
        });
    </script>

    @script
    <script>
        // Sync TinyMCE content then call Livewire save
        function stripVariableSpans(html) {
            return html.replace(/<span class="mce-variable[^"]*">(\{[^}]+\})<\/span>/g, '$1');
        }

        window.syncTinyMCEAndSave = function (btn) {
            const content = {};
            if (typeof tinymce !== 'undefined') {
                tinymce.get().forEach(editor => {
                    const textarea = editor.getElement();
                    const wrap = textarea?.closest('[wire\\:key]');
                    if (wrap) {
                        const key = wrap.getAttribute('wire:key');
                        const match = key.match(/^tinymce-(\w+)-/);
                        if (match) {
                            content[match[1]] = stripVariableSpans(editor.getContent());
                        }
                    }
                });
            }
            $wire.call('saveWithEditorContent', content);
        };

        const emailVariables = {
            formulaire: {
                '{prenom}': 'Prénom', '{nom}': 'Nom', '{operation}': 'Opération',
                '{type_operation}': 'Type opération', '{date_debut}': 'Date début',
                '{date_fin}': 'Date fin', '{nb_seances}': 'Nb séances',
            },
            attestation: {
                '{prenom}': 'Prénom', '{nom}': 'Nom', '{operation}': 'Opération',
                '{type_operation}': 'Type opération', '{date_debut}': 'Date début',
                '{date_fin}': 'Date fin', '{nb_seances}': 'Nb séances',
                '{numero_seance}': 'N° séance', '{date_seance}': 'Date séance',
            },
            facture: {
                '{prenom}': 'Prénom', '{nom}': 'Nom', '{operation}': 'Opération',
                '{type_operation}': 'Type opération', '{date_debut}': 'Date début',
                '{date_fin}': 'Date fin', '{nb_seances}': 'Nb séances',
                '{numero_seance}': 'N° séance', '{date_seance}': 'Date séance',
                '{date_facture}': 'Date facture', '{numero_facture}': 'N° facture',
            },
        };

        Alpine.data('tinymceEditor', (categorie, isReadonly) => ({
            editor: null,

            init() {
                this.$nextTick(() => this.setup());
            },

            setup() {
                if (typeof tinymce === 'undefined') {
                    setTimeout(() => this.setup(), 300);
                    return;
                }

                const textarea = this.$refs.editor;
                if (!textarea) return;

                const self = this;

                const variables = emailVariables[categorie] || {};
                const menuItems = Object.entries(variables).map(([key, label]) => ({
                    type: 'menuitem',
                    text: key + ' — ' + label,
                    onAction: () => {
                        if (self.editor) {
                            self.editor.insertContent('<span class="mce-variable mce-noneditable">' + key + '</span>&nbsp;');
                        }
                    },
                }));

                tinymce.init({
                    target: textarea,
                    language: 'fr_FR',
                    language_url: '/vendor/tinymce/langs/fr_FR.js',
                    height: 250,
                    menubar: false,
                    statusbar: false,
                    plugins: 'lists link noneditable',
                    toolbar: isReadonly ? false : 'bold italic underline | bullist numlist | link | variablesButton',
                    readonly: isReadonly,
                    noneditable_class: 'mce-variable',
                    content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; } .mce-variable { background: #f3edff; border: 1px solid #d4c5f9; border-radius: 3px; padding: 1px 1px; font-family: monospace; font-size: 12px; color: #7c3aed; display: inline-block; }',
                    setup: function (editor) {
                        self.editor = editor;

                        if (!isReadonly) {
                            editor.ui.registry.addMenuButton('variablesButton', {
                                text: 'Variables',
                                fetch: function (callback) { callback(menuItems); },
                            });
                        }

                        // On init: convert {variable} text to styled spans
                        editor.on('init', function () {
                            let content = editor.getContent();
                            const allVarKeys = Object.keys(variables);
                            allVarKeys.forEach(v => {
                                const escaped = v.replace(/[{}]/g, '\\$&');
                                const regex = new RegExp('(?!<span[^>]*>)' + escaped + '(?!</span>)', 'g');
                                content = content.replace(regex, '<span class="mce-variable mce-noneditable">' + v + '</span>');
                            });
                            editor.setContent(content);
                        });
                    },
                });
            },

            destroy() {
                if (this.editor) {
                    try { tinymce.remove(this.editor); } catch (e) {}
                    this.editor = null;
                }
            },
        }));
    </script>
    @endscript
</div>
