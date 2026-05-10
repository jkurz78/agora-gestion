<div>
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="d-flex gap-3 align-items-center mb-3 flex-wrap">
        <div class="btn-group" role="group">
            <input type="radio" class="btn-check" wire:model.live="filtre" value="actives" id="formules-actives">
            <label class="btn btn-outline-success" for="formules-actives">Actives</label>

            <input type="radio" class="btn-check" wire:model.live="filtre" value="inactives" id="formules-inactives">
            <label class="btn btn-outline-secondary" for="formules-inactives">Inactives</label>

            <input type="radio" class="btn-check" wire:model.live="filtre" value="toutes" id="formules-toutes">
            <label class="btn btn-outline-primary" for="formules-toutes">Toutes</label>
        </div>

        <button type="button" class="btn btn-primary btn-sm ms-auto" wire:click="openCreate">
            <i class="bi bi-plus-lg me-1"></i>Nouvelle formule
        </button>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>Nom</th>
                    <th>Sous-catégorie</th>
                    <th>Origine</th>
                    <th>Mode</th>
                    <th>Durée</th>
                    <th class="text-end">Montant par défaut</th>
                    <th>Déductible</th>
                    <th>État</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($formules as $formule)
                    <tr>
                        <td class="small fw-semibold">{{ $formule->nom }}</td>
                        <td class="small">{{ $formule->sousCategorie?->nom ?? '—' }}</td>
                        <td class="small">
                            @if ($formule->est_helloasso)
                                <span class="badge text-bg-info"><i class="bi bi-link-45deg"></i> HelloAsso</span>
                            @else
                                <span class="badge text-bg-secondary">Manuelle</span>
                            @endif
                        </td>
                        <td class="small">
                            @if($formule->isModeExercice())
                                <span class="badge text-bg-secondary">Exercice</span>
                            @elseif($formule->mode === 'illimite')
                                <span class="badge text-bg-primary">Permanente</span>
                            @else
                                <span class="badge text-bg-info">Durée</span>
                            @endif
                        </td>
                        <td class="small">
                            {{ $formule->duree_mois ? $formule->duree_mois.' mois' : '—' }}
                        </td>
                        <td class="text-end small">
                            {{ $formule->montant_par_defaut !== null ? number_format((float) $formule->montant_par_defaut, 2, ',', ' ').' €' : '—' }}
                        </td>
                        <td class="small text-center">
                            @if($formule->deductible_fiscal)
                                <i class="bi bi-check-circle-fill text-success"></i>
                            @else
                                <i class="bi bi-dash text-muted"></i>
                            @endif
                        </td>
                        <td>
                            @if($formule->actif)
                                <span class="badge text-bg-success">Active</span>
                            @else
                                <span class="badge text-bg-secondary">Inactive</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary" wire:click="openEdit({{ $formule->id }})" title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    wire:click="softDelete({{ $formule->id }})"
                                    wire:confirm="Supprimer cette formule ?"
                                    title="Supprimer">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted small py-3">
                            Aucune formule. Créez-en une avec « Nouvelle formule ».
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Modale création/édition --}}
    @if($showModal)
        <div x-on:keydown.escape.window="$wire.close()">
            <div class="modal fade show d-block" tabindex="-1" role="dialog" style="z-index:2055">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                {{ $editingId ? 'Modifier la formule' : 'Nouvelle formule' }}
                            </h5>
                            <button type="button" class="btn-close" wire:click="close"></button>
                        </div>
                        <div class="modal-body">
                            @if($this->isEditingHelloasso())
                                <div class="alert alert-info py-2 small mb-3">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Cette formule est <strong>synchronisée depuis HelloAsso</strong>. Seul son état (active/inactive) peut être modifié manuellement.
                                    Les autres champs sont mis à jour automatiquement par la synchronisation HelloAsso.
                                </div>
                            @endif

                            @if($errorMessage)
                                <div class="alert alert-danger py-2">{{ $errorMessage }}</div>
                            @endif

                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="formule-nom">Nom</label>
                                <input id="formule-nom"
                                       type="text"
                                       class="form-control form-control-sm @error('nom') is-invalid @enderror"
                                       wire:model="nom"
                                       maxlength="120"
                                       @if($this->isEditingHelloasso()) disabled @endif>
                                @error('nom')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="formule-description">Description (optionnel)</label>
                                <textarea id="formule-description"
                                          class="form-control form-control-sm"
                                          rows="2"
                                          wire:model="description"
                                          @if($this->isEditingHelloasso()) disabled @endif></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="formule-souscat">Sous-catégorie (usage Cotisation)</label>
                                <div class="d-flex gap-1">
                                    <select id="formule-souscat"
                                            class="form-select form-select-sm @error('sousCategorieId') is-invalid @enderror"
                                            wire:model="sousCategorieId"
                                            @if($this->isEditingHelloasso()) disabled @endif>
                                        <option value="">— Choisir —</option>
                                        @foreach($sousCategoriesCotisation as $sc)
                                            <option value="{{ $sc->id }}">{{ $sc->nom }}@if($sc->code_cerfa) ({{ $sc->code_cerfa }})@endif</option>
                                        @endforeach
                                    </select>
                                    @if(! $this->isEditingHelloasso())
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary"
                                                title="Créer une nouvelle sous-catégorie"
                                                wire:click="openCreateSousCat"
                                                style="padding:.15rem .5rem">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    @endif
                                </div>
                                @error('sousCategorieId')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

                                {{-- Sub-bloc inline : création nouvelle sous-cat --}}
                                @if($showCreateSousCat)
                                    <div class="bg-light rounded p-3 mt-2 border">
                                        <h6 class="mb-2"><i class="bi bi-plus-circle me-1"></i> Nouvelle sous-catégorie</h6>
                                        <p class="text-muted small mb-2">L'usage <strong>Cotisation</strong> sera automatiquement attaché.</p>

                                        @if($newSousCatErreur)
                                            <div class="alert alert-danger py-2 small">{{ $newSousCatErreur }}</div>
                                        @endif

                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label small mb-1" for="new-sc-nom">Nom *</label>
                                                <input id="new-sc-nom"
                                                       type="text"
                                                       class="form-control form-control-sm @error('newSousCatNom') is-invalid @enderror"
                                                       wire:model="newSousCatNom"
                                                       maxlength="255"
                                                       placeholder="Ex : Cotisations 2026">
                                                @error('newSousCatNom')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small mb-1" for="new-sc-cerfa">Code CERFA</label>
                                                <input id="new-sc-cerfa"
                                                       type="text"
                                                       class="form-control form-control-sm"
                                                       wire:model="newSousCatCodeCerfa"
                                                       maxlength="10"
                                                       placeholder="751">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small mb-1" for="new-sc-cat">Catégorie *</label>
                                                <select id="new-sc-cat"
                                                        class="form-select form-select-sm @error('newSousCatCategorieId') is-invalid @enderror"
                                                        wire:model="newSousCatCategorieId">
                                                    <option value="">— Choisir —</option>
                                                    @foreach($categories as $cat)
                                                        <option value="{{ $cat->id }}">{{ $cat->nom }}</option>
                                                    @endforeach
                                                </select>
                                                @error('newSousCatCategorieId')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                            </div>
                                        </div>
                                        <div class="d-flex gap-1 justify-content-end">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="cancelCreateSousCat">
                                                Annuler
                                            </button>
                                            <button type="button" class="btn btn-sm btn-success" wire:click="saveNewSousCat">
                                                <i class="bi bi-check-lg"></i> Créer et sélectionner
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold" for="formule-mode">Mode</label>
                                    <select id="formule-mode" class="form-select form-select-sm" wire:model.live="mode"
                                            @if($this->isEditingHelloasso()) disabled @endif>
                                        <option value="exercice">Par exercice</option>
                                        <option value="duree">Durée fixe</option>
                                        <option value="illimite">Permanente</option>
                                    </select>
                                </div>
                                @if($mode === 'duree')
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold" for="formule-duree">Durée (mois)</label>
                                        <input id="formule-duree"
                                               type="number"
                                               min="1"
                                               max="36"
                                               class="form-control form-control-sm @error('dureeMois') is-invalid @enderror"
                                               wire:model="dureeMois"
                                               @if($this->isEditingHelloasso()) disabled @endif>
                                        @error('dureeMois')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                @endif
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold" for="formule-montant">Montant par défaut (€, optionnel)</label>
                                    <input id="formule-montant"
                                           type="number"
                                           step="0.01"
                                           min="0"
                                           class="form-control form-control-sm @error('montantParDefaut') is-invalid @enderror"
                                           wire:model="montantParDefaut"
                                           @if($this->isEditingHelloasso()) disabled @endif>
                                    @error('montantParDefaut')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6 d-flex align-items-end gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               id="formule-deductible"
                                               wire:model="deductibleFiscal"
                                               @if($this->isEditingHelloasso()) disabled @endif>
                                        <label class="form-check-label" for="formule-deductible">Déductible fiscal</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               id="formule-actif"
                                               wire:model="actif">
                                        <label class="form-check-label" for="formule-actif">Active</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" wire:click="close">Annuler</button>
                            <button type="button" class="btn btn-primary btn-sm" wire:click="save">
                                <i class="bi bi-check-lg me-1"></i>Enregistrer
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-backdrop fade show" style="z-index:2054"></div>
        </div>
    @endif
</div>
