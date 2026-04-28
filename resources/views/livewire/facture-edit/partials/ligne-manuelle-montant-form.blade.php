{{-- Formulaire d'ajout de ligne manuelle (MontantManuel) --}}
<div class="p-3 border-top bg-light">
    <h6 class="small fw-semibold text-muted mb-2">
        <i class="bi bi-cash"></i> Nouvelle ligne manuelle
    </h6>
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label form-label-sm">Libellé <span class="text-danger">*</span></label>
            <input type="text"
                   class="form-control form-control-sm @error('nouvelleLigneMontantLibelle') is-invalid @enderror"
                   placeholder="Ex : Mission audit..."
                   wire:model="nouvelleLigneMontantLibelle">
            @error('nouvelleLigneMontantLibelle')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <div class="col-md-2">
            <label class="form-label form-label-sm">Prix unitaire <span class="text-danger">*</span></label>
            <input type="number"
                   class="form-control form-control-sm text-end @error('nouvelleLigneMontantPrixUnitaire') is-invalid @enderror"
                   placeholder="0.00"
                   step="0.01"
                   min="0.01"
                   wire:model="nouvelleLigneMontantPrixUnitaire">
            @error('nouvelleLigneMontantPrixUnitaire')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <div class="col-md-2">
            <label class="form-label form-label-sm">Quantité <span class="text-danger">*</span></label>
            <input type="number"
                   class="form-control form-control-sm text-end @error('nouvelleLigneMontantQuantite') is-invalid @enderror"
                   placeholder="1"
                   step="0.001"
                   min="0.001"
                   wire:model="nouvelleLigneMontantQuantite">
            @error('nouvelleLigneMontantQuantite')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <div class="col-md-3">
            <label class="form-label form-label-sm">Sous-catégorie</label>
            <select class="form-select form-select-sm"
                    wire:model="nouvelleLigneMontantSousCategorieId">
                <option value="">— Aucune —</option>
                @foreach ($sousCategoriesRecettes as $sc)
                    <option value="{{ $sc->id }}">{{ $sc->nom }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="row g-2 mt-1">
        <div class="col-md-5">
            <label class="form-label form-label-sm">Opération</label>
            <select class="form-select form-select-sm"
                    wire:model.live="nouvelleLigneMontantOperationId">
                <option value="">— Aucune —</option>
                @foreach ($operations as $op)
                    <option value="{{ $op->id }}">{{ $op->nom }}</option>
                @endforeach
            </select>
        </div>
        @php $opForm = $nouvelleLigneMontantOperationId !== null && $nouvelleLigneMontantOperationId !== '' ? $operations->firstWhere('id', (int) $nouvelleLigneMontantOperationId) : null; @endphp
        @if ($opForm !== null && (int) $opForm->nombre_seances > 0)
            <div class="col-md-2">
                <label class="form-label form-label-sm">Séance</label>
                <select class="form-select form-select-sm"
                        wire:model="nouvelleLigneMontantSeance">
                    <option value="">— Aucune —</option>
                    @for ($i = 1; $i <= (int) $opForm->nombre_seances; $i++)
                        <option value="{{ $i }}">{{ $i }}</option>
                    @endfor
                </select>
            </div>
        @endif
    </div>
    <div class="d-flex gap-2 mt-2">
        <button wire:click="ajouterLigneManuelle"
                class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg"></i> Ajouter
        </button>
        <button wire:click="annulerFormLigneManuelle"
                class="btn btn-sm btn-outline-secondary">
            Annuler
        </button>
    </div>
</div>
