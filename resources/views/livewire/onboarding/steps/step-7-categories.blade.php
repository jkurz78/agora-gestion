<h3>7. Plan comptable</h3>
<p class="text-muted">Choisissez votre point de départ. Vous pourrez toujours ajouter, modifier ou supprimer des catégories ensuite.</p>

<form wire:submit="saveStep7">
    <div class="form-check mb-3">
        <input class="form-check-input" type="radio" wire:model="planComptableChoix" id="planDefault" value="default">
        <label class="form-check-label" for="planDefault">
            <strong>Importer le plan comptable associatif par défaut</strong>
            <br><small class="text-muted">~7 catégories, ~13 sous-catégories correspondant aux codes CERFA usuels (dons, cotisations, subventions, services extérieurs, charges de personnel…). Recommandé.</small>
        </label>
    </div>
    <div class="form-check mb-3">
        <input class="form-check-input" type="radio" wire:model="planComptableChoix" id="planEmpty" value="empty">
        <label class="form-check-label" for="planEmpty">
            <strong>Commencer avec un plan vide</strong>
            <br><small class="text-muted">Vous créerez vos propres catégories au fur et à mesure.</small>
        </label>
    </div>

    @error('planComptableChoix') <div class="alert alert-danger">{{ $message }}</div> @enderror

    <div class="d-flex gap-2 justify-content-between mt-4">
        <button type="button" wire:click="goToStep(6)" class="btn btn-link">← Retour</button>
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Valider et continuer</button>
    </div>
</form>
