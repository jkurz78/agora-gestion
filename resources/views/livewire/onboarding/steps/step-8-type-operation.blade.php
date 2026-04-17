<h3>8. Premier type d'opération <small class="text-muted">(optionnel)</small></h3>
<p class="text-muted">Un « type d'opération » regroupe des séances ou prestations similaires (ex. « Cours de yoga », « Adhésion annuelle »). Vous pouvez en créer un maintenant ou plus tard.</p>

@if ($this->sousCategories->isEmpty())
    <div class="alert alert-info">Aucune sous-catégorie disponible. Retournez à l'étape précédente pour importer le plan par défaut, ou passez cette étape.</div>
    <div class="d-flex gap-2 justify-content-between mt-4">
        <button type="button" wire:click="goToStep(7)" class="btn btn-link">← Retour</button>
        <button type="button" wire:click="skipStep8" class="btn btn-outline-secondary">Passer cette étape</button>
    </div>
@else
    <form wire:submit="saveStep8">
        <div class="mb-3">
            <label class="form-label">Nom</label>
            <input type="text" wire:model="typeOpNom" class="form-control @error('typeOpNom') is-invalid @enderror" placeholder="Adhésion annuelle">
            @error('typeOpNom') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="mb-3">
            <label class="form-label">Description (optionnelle)</label>
            <input type="text" wire:model="typeOpDescription" class="form-control @error('typeOpDescription') is-invalid @enderror">
            @error('typeOpDescription') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="mb-3">
            <label class="form-label">Sous-catégorie associée</label>
            <select wire:model="typeOpSousCategorieId" class="form-select @error('typeOpSousCategorieId') is-invalid @enderror">
                <option value="">— choisir —</option>
                @foreach ($this->sousCategories as $sc)
                    <option value="{{ $sc->id }}">{{ $sc->nom }}</option>
                @endforeach
            </select>
            @error('typeOpSousCategorieId') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="d-flex gap-2 justify-content-between mt-4">
            <button type="button" wire:click="goToStep(7)" class="btn btn-link">← Retour</button>
            <div class="d-flex gap-2">
                <button type="button" wire:click="skipStep8" class="btn btn-outline-secondary">Passer cette étape</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Valider et continuer</button>
            </div>
        </div>
    </form>
@endif
