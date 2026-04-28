{{-- Formulaire d'ajout de ligne texte (commentaire / titre de section) --}}
<div class="p-3 border-top bg-light">
    <h6 class="small fw-semibold text-muted mb-2">
        <i class="bi bi-text-left"></i> Nouvelle ligne texte (commentaire)
    </h6>
    <div class="row g-2 align-items-end">
        <div class="col">
            <input type="text"
                   class="form-control form-control-sm fst-italic @error('nouvelleLigneTexteLibelle') is-invalid @enderror"
                   placeholder="Ex : Détail de la prestation selon annexe jointe..."
                   wire:model="nouvelleLigneTexteLibelle"
                   wire:keydown.enter="ajouterLigneTexteManuelle">
            @error('nouvelleLigneTexteLibelle')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <div class="col-auto d-flex gap-2">
            <button wire:click="ajouterLigneTexteManuelle"
                    class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-plus-lg"></i> Ajouter
            </button>
            <button wire:click="annulerFormLigneManuelle"
                    class="btn btn-sm btn-outline-secondary">
                Annuler
            </button>
        </div>
    </div>
</div>
