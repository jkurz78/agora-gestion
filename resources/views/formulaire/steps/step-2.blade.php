<div x-show="step === 2" x-cloak data-step="2">
    <div class="alert alert-warning d-flex align-items-center mb-3">
        <i class="bi bi-shield-lock me-2"></i>
        <span>Ces informations sont <strong>confidentielles et chiffrées</strong>.</span>
    </div>

    <h5 class="mb-3"><i class="bi bi-heart-pulse"></i> Données de santé</h5>

    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Date de naissance</label>
            <input type="date" name="date_naissance" class="form-control"
                   value="{{ old('date_naissance', $donneesMedicales?->date_naissance) }}">
        </div>
        <div class="col-md-3">
            <label class="form-label">Sexe</label>
            <select name="sexe" class="form-select">
                <option value="">—</option>
                <option value="M" @selected(old('sexe', $donneesMedicales?->sexe) === 'M')>Masculin</option>
                <option value="F" @selected(old('sexe', $donneesMedicales?->sexe) === 'F')>Féminin</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Taille (cm)</label>
            <input type="number" name="taille" class="form-control" min="50" max="250"
                   value="{{ old('taille', $donneesMedicales?->taille) }}">
        </div>
        <div class="col-md-3">
            <label class="form-label">Poids (kg)</label>
            <input type="number" name="poids" class="form-control" min="20" max="300"
                   value="{{ old('poids', $donneesMedicales?->poids) }}">
        </div>
    </div>

    {{-- Médecin traitant --}}
    <h6 class="mt-4 mb-3"><i class="bi bi-hospital"></i> Médecin traitant</h6>

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Nom</label>
            <input type="text" name="medecin_nom" class="form-control"
                   value="{{ old('medecin_nom', $donneesMedicales?->medecin_nom) }}" maxlength="255">
        </div>
        <div class="col-md-6">
            <label class="form-label">Prénom</label>
            <input type="text" name="medecin_prenom" class="form-control"
                   value="{{ old('medecin_prenom', $donneesMedicales?->medecin_prenom) }}" maxlength="255">
        </div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-md-4">
            <label class="form-label">Téléphone</label>
            <input type="tel" name="medecin_telephone" class="form-control"
                   value="{{ old('medecin_telephone', $donneesMedicales?->medecin_telephone) }}" maxlength="30">
        </div>
        <div class="col-md-8">
            <label class="form-label">Email</label>
            <input type="email" name="medecin_email" class="form-control"
                   value="{{ old('medecin_email', $donneesMedicales?->medecin_email) }}" maxlength="255">
        </div>
    </div>
    <div class="mt-3">
        <label class="form-label">Adresse</label>
        <input type="text" name="medecin_adresse" class="form-control"
               value="{{ old('medecin_adresse', $donneesMedicales?->medecin_adresse) }}" maxlength="500">
    </div>

    {{-- Thérapeute référent --}}
    <h6 class="mt-4 mb-3"><i class="bi bi-person-badge"></i> Thérapeute référent</h6>

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Nom</label>
            <input type="text" name="therapeute_nom" class="form-control"
                   value="{{ old('therapeute_nom', $donneesMedicales?->therapeute_nom) }}" maxlength="255">
        </div>
        <div class="col-md-6">
            <label class="form-label">Prénom</label>
            <input type="text" name="therapeute_prenom" class="form-control"
                   value="{{ old('therapeute_prenom', $donneesMedicales?->therapeute_prenom) }}" maxlength="255">
        </div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-md-4">
            <label class="form-label">Téléphone</label>
            <input type="tel" name="therapeute_telephone" class="form-control"
                   value="{{ old('therapeute_telephone', $donneesMedicales?->therapeute_telephone) }}" maxlength="30">
        </div>
        <div class="col-md-8">
            <label class="form-label">Email</label>
            <input type="email" name="therapeute_email" class="form-control"
                   value="{{ old('therapeute_email', $donneesMedicales?->therapeute_email) }}" maxlength="255">
        </div>
    </div>
    <div class="mt-3">
        <label class="form-label">Adresse</label>
        <input type="text" name="therapeute_adresse" class="form-control"
               value="{{ old('therapeute_adresse', $donneesMedicales?->therapeute_adresse) }}" maxlength="500">
    </div>

    {{-- Notes médicales --}}
    <div class="mt-4">
        <label class="form-label">Notes médicales</label>
        <textarea name="notes" class="form-control" rows="3" maxlength="1000"
                  placeholder="Allergies, traitements en cours, particularités...">{{ old('notes', $donneesMedicales?->notes) }}</textarea>
    </div>
</div>
