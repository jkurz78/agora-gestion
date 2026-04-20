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

    {{-- Lignes --}}
    <h6 class="fw-semibold mb-2">Lignes de dépenses</h6>

    @foreach ($lignes as $i => $ligne)
        <div class="card mb-2">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="fw-semibold small">Ligne {{ $i + 1 }}</span>
                    @if (count($lignes) > 1)
                        <button type="button"
                                wire:click="removeLigne({{ $i }})"
                                class="btn btn-outline-danger btn-sm py-0 px-1">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    @endif
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label form-label-sm">Libellé</label>
                        <input type="text"
                               wire:model.live="lignes.{{ $i }}.libelle"
                               class="form-control form-control-sm"
                               placeholder="Description de la dépense">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Montant (€) <span class="text-danger">*</span></label>
                        <input type="number"
                               step="0.01"
                               min="0.01"
                               wire:model.live="lignes.{{ $i }}.montant"
                               class="form-control form-control-sm"
                               placeholder="0,00">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Sous-catégorie</label>
                        <select wire:model.live="lignes.{{ $i }}.sous_categorie_id"
                                class="form-select form-select-sm">
                            <option value="">— choisir —</option>
                            @foreach ($sousCategories as $sc)
                                <option value="{{ $sc->id }}">{{ $sc->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">Opération (optionnel)</label>
                        <select wire:model.live="lignes.{{ $i }}.operation_id"
                                class="form-select form-select-sm">
                            <option value="">— aucune —</option>
                            @foreach ($operations as $op)
                                <option value="{{ $op->id }}">{{ $op->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label form-label-sm">
                            Justificatif
                            @if (!empty($ligne['piece_jointe_path']))
                                <span class="badge bg-success-subtle text-success ms-1">
                                    <i class="bi bi-paperclip"></i> Existant
                                </span>
                            @endif
                        </label>
                        <input type="file"
                               wire:model="lignes.{{ $i }}.justif"
                               accept=".pdf,.jpg,.jpeg,.png,.heic"
                               class="form-control form-control-sm">
                        @error("lignes.{$i}.justif")
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <button type="button" wire:click="addLigne" class="btn btn-outline-secondary btn-sm mb-3">
        <i class="bi bi-plus-lg me-1"></i>Ajouter une ligne
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
</div>
