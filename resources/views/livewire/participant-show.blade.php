<div x-data="{ tab: @entangle('activeTab'), isDirty: false, confirmLeave(cb) { if (this.isDirty) { if (confirm('Des modifications non enregistrées seront perdues. Continuer ?')) { cb(); } } else { cb(); } } }"
     x-on:input="isDirty = true"
     x-on:beforeunload.window="if (isDirty) { $event.preventDefault(); $event.returnValue = ''; }">

    {{-- Header with back link + PDF buttons --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="#" @click.prevent="confirmLeave(() => $wire.dispatch('close-participant'))" class="text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i> Retour à la liste des participants
        </a>
        <div class="d-flex gap-2">
            <a href="{{ route('gestion.operations.participants.fiche-pdf', [$operation, $participant]) }}" target="_blank" class="btn btn-sm btn-outline-info">
                <i class="bi bi-file-person"></i> Fiche PDF
            </a>
            @if($operation->typeOperation?->formulaire_droit_image && $participant->droit_image)
            <a href="{{ route('gestion.operations.participants.droit-image-pdf', [$operation, $participant]) }}" target="_blank" class="btn btn-sm btn-outline-info">
                <i class="bi bi-camera"></i> Autorisation photo
            </a>
            @endif
        </div>
    </div>

    <h5 class="fw-bold mb-3">{{ $editPrenom }} {{ $editNom }}</h5>

    {{-- Tab navigation --}}
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link" :class="tab === 'coordonnees' && 'active'" @click.prevent="tab = 'coordonnees'" href="#">Coordonnées</a>
        </li>
        @if($hasParcours)
            <li class="nav-item">
                <a class="nav-link" :class="tab === 'parcours' && 'active'" @click.prevent="tab = 'parcours'" href="#">Données personnelles</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" :class="tab === 'contacts_medicaux' && 'active'" @click.prevent="tab = 'contacts_medicaux'" href="#">Contacts médicaux</a>
            </li>
        @endif
        @if($hasPrescripteur)
            <li class="nav-item">
                <a class="nav-link" :class="tab === 'prescripteur' && 'active'" @click.prevent="tab = 'prescripteur'" href="#">Adressé par</a>
            </li>
        @endif
        @if($hasParcours)
            <li class="nav-item">
                <a class="nav-link" :class="tab === 'notes' && 'active'" @click.prevent="tab = 'notes'" href="#">Notes</a>
            </li>
        @endif
        <li class="nav-item">
            <a class="nav-link" :class="tab === 'engagements' && 'active'" @click.prevent="tab = 'engagements'" href="#">Engagements</a>
        </li>
        @if($hasDocuments)
            <li class="nav-item">
                <a class="nav-link" :class="tab === 'documents' && 'active'" @click.prevent="tab = 'documents'" href="#">Documents</a>
            </li>
        @endif
        <li class="nav-item">
            <a class="nav-link" :class="tab === 'historique' && 'active'" @click.prevent="tab = 'historique'" href="#">Historique</a>
        </li>
    </ul>

    {{-- Tab content --}}
    <div style="max-width:800px;">

        {{-- ── Tab: Coordonnées ───────────────────────── --}}
        <div x-show="tab === 'coordonnees'" x-cloak>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label small">Nom</label>
                    <input type="text" wire:model="editNom" class="form-control form-control-sm">
                </div>
                <div class="col-md-6">
                    <label class="form-label small">Prénom</label>
                    <input type="text" wire:model="editPrenom" class="form-control form-control-sm">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small">Adresse</label>
                <input type="text" wire:model="editAdresse" class="form-control form-control-sm">
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <label class="form-label small">Code postal</label>
                    <input type="text" wire:model="editCodePostal" class="form-control form-control-sm">
                </div>
                <div class="col-md-8">
                    <label class="form-label small">Ville</label>
                    <input type="text" wire:model="editVille" class="form-control form-control-sm">
                </div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label small">Téléphone</label>
                    <input type="text" wire:model="editTelephone" class="form-control form-control-sm">
                </div>
                <div class="col-md-6">
                    <label class="form-label small">Email</label>
                    <input type="text" wire:model="editEmail" class="form-control form-control-sm">
                </div>
            </div>

        </div>

        {{-- ── Tab: Données personnelles ────────────── --}}
        @if($hasParcours)
            <div x-show="tab === 'parcours'" x-cloak>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Nom de jeune fille</label>
                        <input type="text" wire:model="editNomJeuneFille" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Nationalité</label>
                        <input type="text" wire:model="editNationalite" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Date de naissance</label>
                        <x-date-input name="editDateNaissance" :value="$editDateNaissance" wire:model="editDateNaissance" />
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small">Sexe</label>
                        <select wire:model="editSexe" class="form-select form-select-sm">
                            <option value="">—</option>
                            <option value="F">F</option>
                            <option value="M">M</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Taille (cm)</label>
                        <input type="text" wire:model="editTaille" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Poids (kg)</label>
                        <input type="text" wire:model="editPoids" class="form-control form-control-sm">
                    </div>
                </div>
            </div>

        {{-- ── Tab: Contacts médicaux ──────────────────── --}}
            <div x-show="tab === 'contacts_medicaux'" x-cloak>
                <h6 class="fw-bold text-muted mb-3"><i class="bi bi-heart-pulse me-1"></i> Médecin traitant</h6>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Nom</label>
                        <input type="text" wire:model="editMedecinNom" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Prénom</label>
                        <input type="text" wire:model="editMedecinPrenom" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Téléphone</label>
                        <input type="text" wire:model="editMedecinTelephone" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Email</label>
                        <input type="text" wire:model="editMedecinEmail" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Adresse</label>
                    <input type="text" wire:model="editMedecinAdresse" class="form-control form-control-sm">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Code postal</label>
                        <input type="text" wire:model="editMedecinCodePostal" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small">Ville</label>
                        <input type="text" wire:model="editMedecinVille" class="form-control form-control-sm">
                    </div>
                </div>

                {{-- Mapping Tiers — Médecin --}}
                @php $medecinTiers = $participant->medecinTiers ?? null; @endphp
                @if($medecinTiers)
                    <div class="alert alert-success py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-link-45deg"></i> <strong>Tiers associé :</strong> {{ $medecinTiers->nom }} {{ $medecinTiers->prenom }}</span>
                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="unlinkMedecinTiers">
                                <i class="bi bi-x-lg"></i> Dissocier
                            </button>
                        </div>
                    </div>
                @else
                    <div class="p-2 bg-light rounded">
                        <label class="form-label small fw-bold">Associer à un tiers</label>
                        <div class="d-flex gap-2 align-items-end">
                            <div class="flex-grow-1">
                                <livewire:tiers-autocomplete
                                    wire:model.live="mapMedecinTiersId"
                                    filtre="tous"
                                    :key="'show-map-medecin-' . $participant->id"
                                />
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-success" wire:click="mapMedecinTiers" @disabled(!$mapMedecinTiersId)>
                                <i class="bi bi-link-45deg"></i> Associer
                            </button>
                        </div>
                        @if($editMedecinNom && $editMedecinPrenom)
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" wire:click="createMedecinTiers">
                            <i class="bi bi-plus-lg"></i> Créer un tiers depuis ces données
                        </button>
                        @endif
                    </div>
                @endif

                <hr>
                <h6 class="fw-bold text-muted mb-3"><i class="bi bi-person-badge me-1"></i> Thérapeute référent</h6>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Nom</label>
                        <input type="text" wire:model="editTherapeuteNom" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Prénom</label>
                        <input type="text" wire:model="editTherapeutePrenom" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Téléphone</label>
                        <input type="text" wire:model="editTherapeuteTelephone" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Email</label>
                        <input type="text" wire:model="editTherapeuteEmail" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Adresse</label>
                    <input type="text" wire:model="editTherapeuteAdresse" class="form-control form-control-sm">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Code postal</label>
                        <input type="text" wire:model="editTherapeuteCodePostal" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small">Ville</label>
                        <input type="text" wire:model="editTherapeuteVille" class="form-control form-control-sm">
                    </div>
                </div>

                {{-- Mapping Tiers — Thérapeute --}}
                @php $therapeuteTiers = $participant->therapeuteTiers ?? null; @endphp
                @if($therapeuteTiers)
                    <div class="alert alert-success py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-link-45deg"></i> <strong>Tiers associé :</strong> {{ $therapeuteTiers->nom }} {{ $therapeuteTiers->prenom }}</span>
                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="unlinkTherapeuteTiers">
                                <i class="bi bi-x-lg"></i> Dissocier
                            </button>
                        </div>
                    </div>
                @else
                    <div class="p-2 bg-light rounded">
                        <label class="form-label small fw-bold">Associer à un tiers</label>
                        <div class="d-flex gap-2 align-items-end">
                            <div class="flex-grow-1">
                                <livewire:tiers-autocomplete
                                    wire:model.live="mapTherapeuteTiersId"
                                    filtre="tous"
                                    :key="'show-map-therapeute-' . $participant->id"
                                />
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-success" wire:click="mapTherapeuteTiers" @disabled(!$mapTherapeuteTiersId)>
                                <i class="bi bi-link-45deg"></i> Associer
                            </button>
                        </div>
                        @if($editTherapeuteNom && $editTherapeutePrenom)
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" wire:click="createTherapeuteTiers">
                            <i class="bi bi-plus-lg"></i> Créer un tiers depuis ces données
                        </button>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- ── Tab: Adressé par (prescripteur) ────────── --}}
        @if($hasPrescripteur)
            <div x-show="tab === 'prescripteur'" x-cloak>
                <div class="mb-3">
                    <label class="form-label small">Établissement</label>
                    <input type="text" wire:model="editAdresseParEtablissement" class="form-control form-control-sm">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Nom</label>
                        <input type="text" wire:model="editAdresseParNom" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Prénom</label>
                        <input type="text" wire:model="editAdresseParPrenom" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Téléphone</label>
                        <input type="text" wire:model="editAdresseParTelephone" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Email</label>
                        <input type="text" wire:model="editAdresseParEmail" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Adresse</label>
                    <input type="text" wire:model="editAdresseParAdresse" class="form-control form-control-sm">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Code postal</label>
                        <input type="text" wire:model="editAdresseParCodePostal" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small">Ville</label>
                        <input type="text" wire:model="editAdresseParVille" class="form-control form-control-sm">
                    </div>
                </div>

                {{-- Mapping Tiers — Adressé par --}}
                @php $refTiers = $participant->referePar ?? null; @endphp
                @if($refTiers)
                    <div class="alert alert-success py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-link-45deg"></i> <strong>Tiers associé :</strong> {{ $refTiers->nom }} {{ $refTiers->prenom }}</span>
                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="unlinkAdresseParTiers">
                                <i class="bi bi-x-lg"></i> Dissocier
                            </button>
                        </div>
                    </div>
                @else
                    <div class="mt-3 p-2 bg-light rounded">
                        <label class="form-label small fw-bold">Associer à un tiers</label>
                        <div class="d-flex gap-2 align-items-end">
                            <div class="flex-grow-1">
                                <livewire:tiers-autocomplete
                                    wire:model.live="mapAdresseParTiersId"
                                    filtre="tous"
                                    :key="'show-map-prescripteur-' . $participant->id"
                                />
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-success" wire:click="mapAdresseParTiers" @disabled(!$mapAdresseParTiersId)>
                                <i class="bi bi-link-45deg"></i> Associer
                            </button>
                        </div>
                        @if($editAdresseParNom && $editAdresseParPrenom)
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" wire:click="createAdresseParTiers">
                            <i class="bi bi-plus-lg"></i> Créer un tiers depuis ces données
                        </button>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- ── Tab: Notes ─────────────────────────────── --}}
        @if($hasParcours)
            <div x-show="tab === 'notes'" x-cloak>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Notes médicales sécurisées</label>
                    <textarea wire:model="medNotes" class="form-control" rows="15" placeholder="Saisir les notes ici..."></textarea>
                </div>
            </div>
        @endif

        {{-- ── Tab: Engagements ───────────────────────── --}}
            <div x-show="tab === 'engagements'" x-cloak>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Date d'inscription</label>
                        <x-date-input name="editDateInscription" :value="$editDateInscription" wire:model="editDateInscription" />
                    </div>
                    @if($operation->typeOperation?->tarifs->count())
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Tarif</label>
                            <select wire:model="editTypeOperationTarifId" class="form-select form-select-sm">
                                <option value="">— Aucun —</option>
                                @foreach($operation->typeOperation->tarifs as $tarif)
                                    <option value="{{ $tarif->id }}">{{ $tarif->libelle }} — {{ number_format($tarif->montant, 2, ',', ' ') }} €</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>

                @if($hasEngagements)
                    <hr class="my-3">

                    @if($editFormulaireRempliAt)
                        <div class="alert alert-info py-2 small mb-3">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            Formulaire soumis le {{ $editFormulaireRempliAt }}
                        </div>
                    @else
                        <div class="alert alert-secondary py-2 small mb-3">
                            <i class="bi bi-hourglass me-1"></i>
                            Formulaire non soumis
                        </div>
                    @endif

                    <table class="table table-sm table-borderless">
                        <tbody>
                            @if($typeOp?->formulaire_droit_image)
                                <tr>
                                    <td class="text-muted small" style="width:200px">Droit à l'image</td>
                                    <td class="small">
                                        @if($editDroitImageLabel)
                                            {{ $editDroitImageLabel }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                            @if($typeOp?->formulaire_parcours_therapeutique)
                                <tr>
                                    <td class="text-muted small">Mode de paiement</td>
                                    <td class="small">
                                        @if($editModePaiement)
                                            {{ $editModePaiement }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted small">Moyen de paiement</td>
                                    <td class="small">
                                        @if($editMoyenPaiement)
                                            {{ $editMoyenPaiement }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted small">Autorisation contact médecin</td>
                                    <td class="small">
                                        @if($editAutorisationContactMedecin !== null)
                                            @if($editAutorisationContactMedecin)
                                                <i class="bi bi-check-lg text-success"></i> Oui
                                            @else
                                                <i class="bi bi-x-lg text-danger"></i> Non
                                            @endif
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                            <tr>
                                <td class="text-muted small">RGPD accepté</td>
                                <td class="small">
                                    @if($editRgpdAccepteAt)
                                        <i class="bi bi-check-lg text-success"></i> {{ $editRgpdAccepteAt }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </table>
                @endif
            </div>

        {{-- ── Tab: Documents ─────────────────────────── --}}
        @if($hasDocuments)
            <div x-show="tab === 'documents'" x-cloak>
                @if(count($editDocuments) > 0)
                    <ul class="list-group list-group-sm">
                        @foreach ($editDocuments as $doc)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span class="small">{{ $doc['name'] }} ({{ number_format($doc['size'] / 1024, 0) }} Ko)</span>
                                <a href="{{ $doc['url'] }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-download"></i>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-muted text-center py-4">Aucun document joint.</p>
                @endif
            </div>
        @endif

        {{-- ── Tab: Historique ──────────────────────────── --}}
        <div x-show="tab === 'historique'" x-cloak>
            @if($timeline->isEmpty())
                <p class="text-muted text-center py-4">Aucun événement enregistré.</p>
            @else
                <div class="list-group list-group-flush">
                    @foreach($timeline as $event)
                        <div class="list-group-item px-0">
                            <div class="d-flex align-items-start gap-3">
                                <div class="text-{{ $event['color'] }}" style="font-size:1.2rem;">
                                    <i class="bi {{ $event['icon'] }}"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold small">{{ $event['description'] }}</div>
                                    @if($event['detail'])
                                        <div class="text-muted small d-flex align-items-center gap-1">
                                            <span>{{ $event['detail'] }}</span>
                                            @if($event['copyable'] ?? false)
                                                <button type="button" class="btn btn-link btn-sm p-0 text-muted" title="Copier"
                                                    x-data x-on:click="navigator.clipboard.writeText('{{ $event['copyable'] }}'); $el.innerHTML = '<i class=\'bi bi-check-lg text-success\'></i>'; setTimeout(() => $el.innerHTML = '<i class=\'bi bi-clipboard\'></i>', 1500)">
                                                    <i class="bi bi-clipboard"></i>
                                                </button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                <div class="text-muted small text-nowrap">
                                    {{ $event['date']->format('d/m/Y à H:i') }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    </div>

    {{-- Save button --}}
    <div class="mt-4 d-flex justify-content-between align-items-center">
        @if($successMessage)
            <span class="text-success"><i class="bi bi-check-lg"></i> {{ $successMessage }}</span>
        @else
            <span></span>
        @endif
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="confirmLeave(() => $wire.dispatch('close-participant'))">
                Annuler
            </button>
            <button type="button" class="btn btn-sm btn-primary" wire:click="save" x-on:click="isDirty = false">
                <i class="bi bi-check-lg"></i> Enregistrer
            </button>
        </div>
    </div>
</div>
