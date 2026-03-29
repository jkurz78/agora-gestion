<div x-show="step === 1" data-step="1" x-cloak>
    {{-- Greeting card --}}
    <div class="card mb-4 border-primary">
        <div class="card-body text-center">
            <h5>Bonjour {{ $tiers->prenom }} {{ $tiers->nom }}</h5>
            <p class="mb-1">{{ $operation->nom }}</p>
            @if($operation->date_debut)
                <small class="text-muted">
                    Du {{ $operation->date_debut->format('d/m/Y') }}
                    @if($operation->date_fin) au {{ $operation->date_fin->format('d/m/Y') }} @endif
                    @if($seancesCount) — {{ $seancesCount }} séance(s) @endif
                </small>
            @endif
        </div>
    </div>

    <h5 class="mb-3"><i class="bi bi-person"></i> Coordonnées</h5>

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Téléphone <span class="text-danger">*</span></label>
            <input type="tel" name="telephone" class="form-control" :class="hasError('telephone') && 'is-invalid'"
                   value="{{ old('telephone', $tiers->telephone) }}" maxlength="30" data-required
                   pattern="[\d\s\+\-\.\(\)]{6,30}" title="Numéro de téléphone valide">
            <div class="invalid-feedback" x-text="errors.telephone"></div>
        </div>
        <div class="col-md-6">
            <label class="form-label">Email <span class="text-danger">*</span></label>
            <input type="email" name="email" class="form-control" :class="hasError('email') && 'is-invalid'"
                   value="{{ old('email', $tiers->email) }}" maxlength="255" data-required>
            <div class="invalid-feedback" x-text="errors.email"></div>
        </div>
    </div>

    <div class="mt-3">
        <label class="form-label">Adresse</label>
        <input type="text" name="adresse_ligne1" class="form-control"
               value="{{ old('adresse_ligne1', $tiers->adresse_ligne1) }}" maxlength="500">
    </div>

    <div class="row g-3 mt-1">
        <div class="col-md-4">
            <label class="form-label">Code postal</label>
            <input type="text" name="code_postal" class="form-control" :class="hasError('code_postal') && 'is-invalid'"
                   value="{{ old('code_postal', $tiers->code_postal) }}" maxlength="10"
                   pattern="\d{4,10}" title="Code postal (chiffres uniquement)">
            <div class="invalid-feedback" x-text="errors.code_postal"></div>
        </div>
        <div class="col-md-8">
            <label class="form-label">Ville</label>
            <input type="text" name="ville" class="form-control"
                   value="{{ old('ville', $tiers->ville) }}" maxlength="100">
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-md-6">
            <label class="form-label">Nom de jeune fille</label>
            <input type="text" name="nom_jeune_fille" class="form-control"
                   value="{{ old('nom_jeune_fille', $participant->nom_jeune_fille) }}" maxlength="255">
        </div>
        <div class="col-md-6">
            <label class="form-label">Nationalité</label>
            <input type="text" name="nationalite" class="form-control"
                   value="{{ old('nationalite', $participant->nationalite) }}" maxlength="100">
        </div>
    </div>

    {{-- Adressé par --}}
    <h6 class="mt-4 mb-3"><i class="bi bi-person-plus"></i> Je vous suis adressé(e) par</h6>

    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Nom</label>
            <input type="text" name="adresse_par_nom" class="form-control"
                   value="{{ old('adresse_par_nom', $participant->adresse_par_nom) }}" maxlength="255">
        </div>
        <div class="col-md-4">
            <label class="form-label">Prénom</label>
            <input type="text" name="adresse_par_prenom" class="form-control"
                   value="{{ old('adresse_par_prenom', $participant->adresse_par_prenom) }}" maxlength="255">
        </div>
        <div class="col-md-4">
            <label class="form-label">Établissement</label>
            <input type="text" name="adresse_par_etablissement" class="form-control"
                   value="{{ old('adresse_par_etablissement', $participant->adresse_par_etablissement) }}" maxlength="255">
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-md-4">
            <label class="form-label">Téléphone</label>
            <input type="tel" name="adresse_par_telephone" class="form-control"
                   value="{{ old('adresse_par_telephone', $participant->adresse_par_telephone) }}" maxlength="30"
                   pattern="[\d\s\+\-\.\(\)]{6,30}" title="Numéro de téléphone valide">
        </div>
        <div class="col-md-8">
            <label class="form-label">Email</label>
            <input type="email" name="adresse_par_email" class="form-control" :class="hasError('adresse_par_email') && 'is-invalid'"
                   value="{{ old('adresse_par_email', $participant->adresse_par_email) }}" maxlength="255">
            <div class="invalid-feedback" x-text="errors.adresse_par_email"></div>
        </div>
    </div>

    <div class="mt-3">
        <label class="form-label">Adresse</label>
        <input type="text" name="adresse_par_adresse" class="form-control"
               value="{{ old('adresse_par_adresse', $participant->adresse_par_adresse) }}" maxlength="500">
    </div>

    <div class="row g-3 mt-1">
        <div class="col-md-4">
            <label class="form-label">Code postal</label>
            <input type="text" name="adresse_par_code_postal" class="form-control"
                   value="{{ old('adresse_par_code_postal', $participant->adresse_par_code_postal) }}" maxlength="10"
                   pattern="\d{4,10}" title="Code postal (chiffres uniquement)">
        </div>
        <div class="col-md-8">
            <label class="form-label">Ville</label>
            <input type="text" name="adresse_par_ville" class="form-control"
                   value="{{ old('adresse_par_ville', $participant->adresse_par_ville) }}" maxlength="100">
        </div>
    </div>
</div>
