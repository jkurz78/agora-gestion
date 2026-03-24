<div>
    @if ($exerciceCloture)
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Exercice clôturé — la synchronisation HelloAsso est désactivée.
        </div>
    @else
    {{-- Erreurs bloquantes --}}
    @if ($configBloquante)
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-octagon me-1"></i>
            <strong>Configuration incomplète</strong>
            <ul class="mb-0 mt-1">
                @foreach ($configErrors as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
            <a href="{{ route('parametres.helloasso') }}" class="alert-link d-block mt-2">
                <i class="bi bi-gear me-1"></i> Paramètres → Connexion HelloAsso
            </a>
        </div>
    @else
        {{-- Avertissements non bloquants --}}
        @if (count($configWarnings) > 0)
            <div class="alert alert-warning small">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <ul class="mb-0">
                    @foreach ($configWarnings as $warn)
                        <li>{{ $warn }}</li>
                    @endforeach
                </ul>
                <a href="{{ route('parametres.helloasso') }}" class="alert-link d-block mt-1">
                    Configurer dans Paramètres → Connexion HelloAsso
                </a>
            </div>
        @endif

        {{-- Étape 1 --}}
        <div class="card mb-3 {{ $step === 1 ? 'border-primary' : '' }}"
             style="{{ $step === 1 ? 'border-width:2px' : '' }}">
            <div class="card-header d-flex align-items-center gap-2"
                 @if ($step > 1) wire:click="goToStep(1)" @endif
                 style="{{ $step > 1 ? 'cursor:pointer' : '' }}">
                <span class="badge rounded-pill {{ $step > 1 ? 'bg-success' : ($step === 1 ? 'bg-primary' : 'bg-secondary') }}">1</span>
                <strong>Mapping Formulaires → Opérations</strong>
                @if ($step > 1)
                    <span class="ms-auto small text-muted">{{ $stepOneSummary ?? '' }}</span>
                @endif
            </div>
            @if ($step === 1)
                <div class="card-body" wire:init="loadFormulaires">
                    @if ($formErreur)
                        <div class="alert alert-danger">{{ $formErreur }}</div>
                    @endif

                    @if ($formsLoading && ! $formsLoaded)
                        <div class="text-center py-3">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Chargement des formulaires HelloAsso...</p>
                        </div>
                    @elseif ($formsLoaded)
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="showAllForms"
                                   wire:model.live="showAllForms">
                            <label class="form-check-label small text-muted" for="showAllForms">
                                Afficher aussi les anciens formulaires
                            </label>
                        </div>

                        @if ($formMappings->isEmpty())
                            <p class="text-muted">Aucun formulaire trouvé pour cet exercice.</p>
                        @else
                            <table class="table table-sm">
                                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                    <tr>
                                        <th>Formulaire</th>
                                        <th>Type</th>
                                        <th>Période</th>
                                        <th>Statut</th>
                                        <th>Opération SVS</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($formMappings as $fm)
                                        <tr wire:key="fm-{{ $fm->id }}">
                                            <td class="small">{{ $fm->form_title ?? $fm->form_slug }}</td>
                                            <td class="small"><span class="badge text-bg-secondary">{{ $fm->form_type }}</span></td>
                                            <td class="small text-nowrap">
                                                @if ($fm->start_date || $fm->end_date)
                                                    {{ $fm->start_date?->format('d/m/Y') ?? '—' }}
                                                    → {{ $fm->end_date?->format('d/m/Y') ?? '…' }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="small">
                                                @if ($fm->state)
                                                    @php
                                                        $badgeClass = match($fm->state) {
                                                            'Public' => 'text-bg-success',
                                                            'Draft' => 'text-bg-warning',
                                                            'Private' => 'text-bg-info',
                                                            'Disabled' => 'text-bg-danger',
                                                            default => 'text-bg-secondary',
                                                        };
                                                    @endphp
                                                    <span class="badge {{ $badgeClass }}">{{ $fm->state }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <select wire:model="formOperations.{{ $fm->id }}" class="form-select form-select-sm">
                                                        <option value="">Ne pas suivre</option>
                                                        @foreach ($operations as $op)
                                                            <option value="{{ $op->id }}">{{ $op->nom }}</option>
                                                        @endforeach
                                                    </select>
                                                    <button wire:click="openCreateOperation({{ $fm->id }})"
                                                            class="btn btn-sm btn-outline-primary" title="Créer une opération"
                                                            style="padding:.15rem .5rem">
                                                        <i class="bi bi-plus-lg"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        @if ($creatingOperationForMapping === $fm->id)
                                            <tr wire:key="create-op-{{ $fm->id }}">
                                                <td colspan="5">
                                                    <div class="bg-light rounded p-3">
                                                        <h6 class="mb-2"><i class="bi bi-plus-circle me-1"></i> Nouvelle opération</h6>
                                                        <div class="row g-2 align-items-end">
                                                            <div class="col-md-3">
                                                                <label class="form-label small">Nom *</label>
                                                                <input type="text" wire:model="newOperationNom" class="form-control form-control-sm @error('newOperationNom') is-invalid @enderror">
                                                                @error('newOperationNom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                            </div>
                                                            <div class="col-md-2">
                                                                <label class="form-label small">Date début *</label>
                                                                <x-date-input name="new_op_debut" wire:model="newOperationDateDebut" :value="$newOperationDateDebut" />
                                                                @error('newOperationDateDebut') <div class="text-danger small">{{ $message }}</div> @enderror
                                                            </div>
                                                            <div class="col-md-2">
                                                                <label class="form-label small">Date fin</label>
                                                                <x-date-input name="new_op_fin" wire:model="newOperationDateFin" :value="$newOperationDateFin" />
                                                            </div>
                                                            <div class="col-md-3">
                                                                <label class="form-label small">Sous-catégorie</label>
                                                                <select wire:model="newOperationSousCategorieId" class="form-select form-select-sm">
                                                                    <option value="">Par défaut</option>
                                                                    @foreach ($sousCategoriesInscription as $sc)
                                                                        <option value="{{ $sc->id }}">{{ $sc->nom }}</option>
                                                                    @endforeach
                                                                </select>
                                                            </div>
                                                            <div class="col-md-2 d-flex gap-1">
                                                                <button wire:click="storeOperation" class="btn btn-sm btn-success">
                                                                    <i class="bi bi-check-lg"></i> Créer
                                                                </button>
                                                                <button wire:click="cancelCreateOperation" class="btn btn-sm btn-outline-secondary">
                                                                    <i class="bi bi-x-lg"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        @endif

                        <div class="d-flex justify-content-end mt-3">
                            <button wire:click="sauvegarderEtSuite" class="btn btn-primary">
                                Suite <i class="bi bi-arrow-right ms-1"></i>
                            </button>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Étape 2 --}}
        <div class="card mb-3 {{ $step === 2 ? 'border-primary' : '' }}"
             style="{{ $step === 2 ? 'border-width:2px' : '' }}">
            <div class="card-header d-flex align-items-center gap-2"
                 @if ($step > 2) wire:click="goToStep(2)" @endif
                 style="{{ $step > 2 ? 'cursor:pointer' : '' }}">
                <span class="badge rounded-pill {{ $step > 2 ? 'bg-success' : ($step === 2 ? 'bg-primary' : 'bg-secondary') }}">2</span>
                <strong>Rapprochement des Tiers</strong>
                @if ($step > 2)
                    <span class="ms-auto small text-muted">{{ $stepTwoSummary ?? '' }}</span>
                @endif
            </div>
            @if ($step === 2)
                <div class="card-body">
                    @if ($tiersErreur)
                        <div class="alert alert-danger">{{ $tiersErreur }}</div>
                    @endif

                    @if ($tiersLoading && ! $tiersFetched)
                        <div class="text-center py-3">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Récupération des tiers HelloAsso...</p>
                        </div>
                    @elseif ($tiersFetched)
                        @php
                            $unlinkedPersons = collect($persons)->whereNull('tiers_id');
                        @endphp

                        @if ($unlinkedPersons->isEmpty())
                            <div class="alert alert-success mb-3">
                                <i class="bi bi-check-circle me-1"></i> Tous les tiers HelloAsso sont déjà associés.
                            </div>
                        @else
                            <table class="table table-sm table-hover">
                                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                    <tr>
                                        <th>Personne HelloAsso</th>
                                        <th>Email</th>
                                        <th>Correspond à</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($persons as $index => $person)
                                        @if ($person['tiers_id'] === null)
                                            <tr wire:key="person-{{ $index }}">
                                                <td class="small">{{ $person['firstName'] }} {{ $person['lastName'] }}</td>
                                                <td class="small text-muted">{{ $person['email'] }}</td>
                                                <td>
                                                    <livewire:tiers-autocomplete
                                                        wire:model.live="selectedTiers.{{ $index }}"
                                                        filtre="recettes"
                                                        :key="'rapprochement-'.$index"
                                                    />
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column gap-1">
                                                        <button wire:click="associerTiers({{ $index }})"
                                                                class="btn btn-sm btn-outline-success py-0 px-2">
                                                            <i class="bi bi-link-45deg me-1"></i>Associer
                                                        </button>
                                                        <button wire:click="creerTiers({{ $index }})"
                                                                class="btn btn-sm btn-outline-primary py-0 px-2"
                                                                title="Créer un nouveau tiers à partir des données HelloAsso">
                                                            <i class="bi bi-person-plus me-1"></i>Ajouter depuis HelloAsso
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        @endif

                        <div class="d-flex justify-content-end mt-3">
                            <button wire:click="lancerSynchronisation" class="btn btn-success"
                                    wire:loading.attr="disabled" wire:target="lancerSynchronisation">
                                <span wire:loading wire:target="lancerSynchronisation" class="spinner-border spinner-border-sm me-1"></span>
                                <i class="bi bi-arrow-repeat me-1" wire:loading.remove wire:target="lancerSynchronisation"></i>
                                Lancer la synchronisation
                            </button>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Étape 3 --}}
        <div class="card mb-3 {{ $step === 3 ? 'border-primary' : '' }}"
             style="{{ $step === 3 ? 'border-width:2px' : '' }}">
            <div class="card-header d-flex align-items-center gap-2">
                <span class="badge rounded-pill {{ $step === 3 ? 'bg-primary' : 'bg-secondary' }}">3</span>
                <strong>Synchronisation</strong>
                @if ($step > 3)
                    <span class="ms-auto small text-muted">{{ $stepThreeSummary ?? '' }}</span>
                @endif
            </div>
            @if ($step === 3)
                <div class="card-body">
                    @if ($syncErreur)
                        <div class="alert alert-danger">{{ $syncErreur }}</div>
                    @endif

                    @if ($syncLoading)
                        <div class="text-center py-3">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Synchronisation en cours...</p>
                        </div>
                    @elseif ($syncResult)
                        <div class="alert {{ count($syncResult['errors']) > 0 ? 'alert-warning' : 'alert-success' }}">
                            <strong><i class="bi bi-check-circle me-1"></i> Synchronisation terminée</strong>
                            <ul class="mb-0 mt-2">
                                <li>Transactions : <strong>{{ $syncResult['transactionsCreated'] }} créée(s)</strong>, <strong>{{ $syncResult['transactionsUpdated'] }} mise(s) à jour</strong></li>
                                <li>Lignes : <strong>{{ $syncResult['lignesCreated'] }} créée(s)</strong>, <strong>{{ $syncResult['lignesUpdated'] }} mise(s) à jour</strong></li>
                                @if ($syncResult['ordersSkipped'] > 0)
                                    <li>Commandes ignorées : <strong>{{ $syncResult['ordersSkipped'] }}</strong></li>
                                @endif
                                @if (($syncResult['virementsCreated'] ?? 0) > 0)
                                    <li>Virements : <strong>{{ $syncResult['virementsCreated'] }} créé(s)</strong></li>
                                @endif
                                @if (($syncResult['rapprochementsCreated'] ?? 0) > 0)
                                    <li>Rapprochements auto-verrouillés : <strong>{{ $syncResult['rapprochementsCreated'] }}</strong></li>
                                @endif
                            </ul>
                        </div>

                        @if (! empty($syncResult['cashoutSkipped']))
                            <div class="alert alert-info small">
                                <i class="bi bi-info-circle me-1"></i> Versements non synchronisés : le compte de versement n'est pas configuré.
                            </div>
                        @endif

                        @if (! empty($syncResult['cashoutsIncomplets']))
                            <div class="alert alert-warning">
                                <strong><i class="bi bi-exclamation-triangle me-1"></i> Versements incomplets :</strong>
                                <ul class="mb-0 mt-1">
                                    @foreach ($syncResult['cashoutsIncomplets'] as $warning)
                                        <li class="small">{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if (count($syncResult['errors']) > 0)
                            <div class="alert alert-danger">
                                <strong><i class="bi bi-exclamation-triangle me-1"></i> {{ count($syncResult['errors']) }} erreur(s) :</strong>
                                <ul class="mb-0 mt-1">
                                    @foreach ($syncResult['errors'] as $error)
                                        <li class="small">{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endif
                </div>
            @endif
        </div>
    @endif
    @endif
</div>
