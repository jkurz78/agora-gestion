@php $errors ??= new \Illuminate\Support\ViewErrorBag; @endphp
<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">
            <i class="bi bi-receipt me-1"></i>
            {{ $noteDeFrais ? 'Modifier la note de frais' : 'Nouvelle note de frais' }}
        </h2>
    </div>

    @if (session('portail.success'))
        <div class="alert alert-success">{{ session('portail.success') }}</div>
    @endif

    @if ($errors->has('submit'))
        <div class="alert alert-danger">
            @foreach ($errors->get('submit') as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    {{-- En-tête --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="dateInput" class="form-label">Date <span class="text-danger">*</span></label>
                    <input type="date"
                           id="dateInput"
                           wire:model.live="dateInput"
                           class="form-control @error('dateInput') is-invalid @enderror">
                    @error('dateInput')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-8">
                    <label for="libelle" class="form-label">Libellé <span class="text-danger">*</span></label>
                    <input type="text"
                           id="libelle"
                           wire:model.live="libelle"
                           class="form-control @error('libelle') is-invalid @enderror"
                           placeholder="Objet de la note de frais">
                    @error('libelle')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
    </div>

    {{-- Lignes de dépenses --}}
    <h6 class="fw-semibold mb-2">Lignes de dépenses</h6>

    @if (count($lignes) === 0)
        <p class="text-muted small mb-2">Aucune ligne ajoutée. Cliquez sur le bouton ci-dessous pour ajouter une dépense.</p>
    @else
        <div class="table-responsive mb-2">
            <table class="table table-sm align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>#</th>
                        <th>Libellé</th>
                        <th>Sous-catégorie</th>
                        <th class="text-end">Montant</th>
                        <th>Justificatif</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lignes as $i => $ligne)
                        <tr>
                            <td class="text-muted small">{{ $i + 1 }}</td>
                            <td>{{ $ligne['libelle'] ?: '—' }}</td>
                            <td>
                                @php
                                    $scNom = $sousCategories->find($ligne['sous_categorie_id'])?->nom ?? '—';
                                @endphp
                                {{ $scNom }}
                            </td>
                            <td class="text-end" data-sort="{{ $ligne['montant'] }}">
                                {{ $ligne['montant'] ? number_format((float) str_replace(',', '.', (string) $ligne['montant']), 2, ',', ' ') . ' €' : '—' }}
                            </td>
                            <td>
                                @if (!empty($ligne['piece_jointe_path']))
                                    <span class="badge bg-success-subtle text-success">
                                        <i class="bi bi-paperclip"></i> Existant
                                    </span>
                                @elseif ($ligne['justif'] instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                    <span class="badge bg-info-subtle text-info">
                                        <i class="bi bi-paperclip"></i> {{ $ligne['justif']->getClientOriginalName() }}
                                    </span>
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </td>
                            <td>
                                <button type="button"
                                        wire:click="removeLigne({{ $i }})"
                                        class="btn btn-outline-danger btn-sm py-0 px-1"
                                        title="Supprimer cette ligne">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <button type="button"
            wire:click="openLigneWizard"
            class="btn btn-outline-secondary btn-sm mb-3">
        <i class="bi bi-plus-lg me-1"></i>Ajouter une ligne de dépense
    </button>

    {{-- Total --}}
    <div class="card mb-3 border-primary">
        <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Total</span>
                <span class="fs-5 fw-bold text-primary">{{ number_format($this->total, 2, ',', ' ') }} €</span>
            </div>
        </div>
    </div>

    {{-- Actions --}}
    <div class="d-flex justify-content-between align-items-center">
        <a href="{{ route('portail.ndf.index', ['association' => $association->slug]) }}"
           class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Annuler
        </a>
        <div class="d-flex gap-2">
            <button type="button" wire:click="saveDraft" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-save me-1"></i>Enregistrer brouillon
            </button>
            <button type="button" wire:click="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-send me-1"></i>Soumettre
            </button>
        </div>
    </div>

    {{-- ===== Wizard modale Bootstrap ===== --}}
    <div class="modal fade" id="ligneWizardModal" tabindex="-1" wire:ignore.self>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-receipt me-1"></i>
                        Ajouter une ligne de dépense
                        @if ($wizardStep > 0)
                            — étape {{ $wizardStep }}/3
                        @endif
                    </h5>
                    <button type="button" class="btn-close" wire:click="cancelLigneWizard" aria-label="Fermer"></button>
                </div>
                <div class="modal-body">
                    @if ($wizardStep === 1)
                        {{-- Étape 1 : Justificatif --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Justificatif <span class="text-danger">*</span>
                            </label>
                            <p class="text-muted small">Importez le reçu, la facture ou tout document justifiant la dépense (PDF, JPG, PNG ou HEIC, max 5 Mo).</p>
                            <input type="file"
                                   wire:model="draftLigne.justif"
                                   accept=".pdf,.jpg,.jpeg,.png,.heic"
                                   class="form-control @error('draftLigne.justif') is-invalid @enderror">
                            @error('draftLigne.justif')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            @if ($draftLigne['justif'] instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                <div class="mt-2 text-success small">
                                    <i class="bi bi-check-circle me-1"></i>
                                    Fichier sélectionné : {{ $draftLigne['justif']->getClientOriginalName() }}
                                </div>
                            @endif
                        </div>

                    @elseif ($wizardStep === 2)
                        {{-- Étape 2 : Libellé + montant --}}
                        <div class="mb-3">
                            <label class="form-label">Libellé <span class="text-muted small">(optionnel)</span></label>
                            <input type="text"
                                   wire:model.live="draftLigne.libelle"
                                   class="form-control"
                                   placeholder="Description de la dépense">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Montant (€) <span class="text-danger">*</span>
                            </label>
                            <input type="number"
                                   step="0.01"
                                   min="0.01"
                                   wire:model.live="draftLigne.montant"
                                   class="form-control @error('draftLigne.montant') is-invalid @enderror"
                                   placeholder="0,00">
                            @error('draftLigne.montant')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                    @elseif ($wizardStep === 3)
                        {{-- Étape 3 : Catégorisation --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Sous-catégorie <span class="text-danger">*</span>
                            </label>
                            <select wire:model.live="draftLigne.sous_categorie_id"
                                    class="form-select @error('draftLigne.sous_categorie_id') is-invalid @enderror">
                                <option value="">— choisir —</option>
                                @foreach ($sousCategories as $sc)
                                    <option value="{{ $sc->id }}">{{ $sc->nom }}</option>
                                @endforeach
                            </select>
                            @error('draftLigne.sous_categorie_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Opération <span class="text-muted small">(optionnel)</span></label>
                            <select wire:model.live="draftLigne.operation_id"
                                    class="form-select">
                                <option value="">— aucune —</option>
                                @foreach ($operations as $op)
                                    <option value="{{ $op->id }}">{{ $op->nom }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    @if ($wizardStep === 1)
                        <button type="button"
                                class="btn btn-secondary"
                                wire:click="cancelLigneWizard">
                            Annuler
                        </button>
                        <button type="button"
                                class="btn btn-primary"
                                wire:click="wizardNext"
                                @if (!($draftLigne['justif'] instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)) disabled @endif>
                            Suivant <i class="bi bi-arrow-right ms-1"></i>
                        </button>

                    @elseif ($wizardStep === 2)
                        <button type="button"
                                class="btn btn-outline-secondary"
                                wire:click="wizardPrev">
                            <i class="bi bi-arrow-left me-1"></i>Précédent
                        </button>
                        <button type="button"
                                class="btn btn-primary"
                                wire:click="wizardNext">
                            Suivant <i class="bi bi-arrow-right ms-1"></i>
                        </button>

                    @elseif ($wizardStep === 3)
                        <button type="button"
                                class="btn btn-outline-secondary"
                                wire:click="wizardPrev">
                            <i class="bi bi-arrow-left me-1"></i>Précédent
                        </button>
                        <button type="button"
                                class="btn btn-success"
                                wire:click="wizardConfirm">
                            <i class="bi bi-plus-lg me-1"></i>Ajouter la ligne
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('livewire:init', () => {
            const modalEl = document.getElementById('ligneWizardModal');
            if (!modalEl) return;
            const modal = new bootstrap.Modal(modalEl);
            Livewire.on('ligne-wizard-opened', () => modal.show());
            Livewire.on('ligne-wizard-closed', () => modal.hide());
        });
    </script>
</div>
