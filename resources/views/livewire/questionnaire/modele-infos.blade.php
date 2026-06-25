<div>
    @include('questionnaire.partials.modele-nav', ['template' => $template, 'active' => 'infos'])

    @if (session('infos_ok'))
        <div class="alert alert-success py-2 mb-3">Informations enregistrées.</div>
    @endif

    <div class="card">
        <div class="card-header fw-semibold">Informations du modèle</div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Titre interne</label>
                <input type="text" class="form-control" wire:model="titreInterne">
                @error('titreInterne') <div class="text-danger small">{{ $message }}</div> @enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Titre affiché au répondant</label>
                <input type="text" class="form-control" wire:model="titreAffiche">
                @error('titreAffiche') <div class="text-danger small">{{ $message }}</div> @enderror
            </div>
            <hr>
            <div class="mb-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" wire:model="anonymise" id="chk-anonymise">
                    <label class="form-check-label" for="chk-anonymise">Questionnaire anonymisé</label>
                </div>
                <div class="text-muted small ms-4">Si décoché, l'identité du répondant est visible dans les résultats et l'écran de consentement au contact est masqué.</div>
            </div>
            <div class="mb-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" wire:model="autoriserRetour" id="chk-retour">
                    <label class="form-check-label" for="chk-retour">Autoriser le retour</label>
                </div>
                <div class="text-muted small ms-4">Affiche un bouton Précédent pendant le parcours.</div>
            </div>
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" wire:model="afficherProgression" id="chk-progression">
                    <label class="form-check-label" for="chk-progression">Afficher la progression</label>
                </div>
                <div class="text-muted small ms-4">Affiche le compteur Question x/n.</div>
            </div>
            <button type="button" class="btn btn-primary" wire:click="enregistrer">Enregistrer</button>
        </div>
    </div>
</div>
