{{-- resources/views/livewire/parametres/liens-publics-form.blade.php --}}
<div>
    <x-flash-message />

    <p class="text-muted small mb-3">
        Ces URL sont utilisées pour les documents produits par AgoraGestion et sont affichées sur le portail dans les espaces Dons et Adhésions.
    </p>

    <form wire:submit="save">
        <div class="mb-3">
            <label for="url_site_web" class="form-label small">Site public de l'association</label>
            <input wire:model="url_site_web" type="url" id="url_site_web" class="form-control form-control-sm @error('url_site_web') is-invalid @enderror" placeholder="https://monasso.fr">
            @error('url_site_web') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <label for="url_renouvellement_adhesion" class="form-label small">Page d'adhésion <span class="text-muted small">(optionnel)</span></label>
            <input wire:model="url_renouvellement_adhesion" type="url" id="url_renouvellement_adhesion" class="form-control form-control-sm @error('url_renouvellement_adhesion') is-invalid @enderror" placeholder="https://helloasso.com/monasso/adhesion-2026">
            <div class="form-text">Une seule adresse, valable tant que la campagne est ouverte. Vous pouvez la pointer vers la saison suivante dès que ses adhésions ouvrent.</div>
            @error('url_renouvellement_adhesion') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <label for="url_nouveau_don" class="form-label small">Page de don <span class="text-muted small">(optionnel)</span></label>
            <input wire:model="url_nouveau_don" type="url" id="url_nouveau_don" class="form-control form-control-sm @error('url_nouveau_don') is-invalid @enderror" placeholder="https://helloasso.com/monasso/don">
            @error('url_nouveau_don') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled">
            <span wire:loading.remove><i class="bi bi-floppy"></i> Enregistrer</span>
            <span wire:loading>Enregistrement…</span>
        </button>
    </form>
</div>
