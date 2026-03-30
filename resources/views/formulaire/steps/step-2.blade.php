<div x-show="step === 2" x-cloak data-step="2">
    <div class="alert alert-warning d-flex align-items-center mb-3">
        <i class="bi bi-shield-lock me-2"></i>
        <span>Ces informations sont <strong>confidentielles et chiffrées</strong>.</span>
    </div>

    {{-- Données de santé --}}
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-heart-pulse me-1"></i> Données de santé</h6>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3">
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

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Date de naissance <span class="text-danger">*</span></label>
                    <input type="date" name="date_naissance" class="form-control" data-required
                           :class="hasError('date_naissance') && 'is-invalid'"
                           value="{{ old('date_naissance', $donneesMedicales?->date_naissance) }}">
                    <div class="invalid-feedback" x-text="errors.date_naissance"></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sexe <span class="text-danger">*</span></label>
                    <select name="sexe" class="form-select" data-required :class="hasError('sexe') && 'is-invalid'">
                        <option value="">—</option>
                        <option value="M" @selected(old('sexe', $donneesMedicales?->sexe) === 'M')>Masculin</option>
                        <option value="F" @selected(old('sexe', $donneesMedicales?->sexe) === 'F')>Féminin</option>
                    </select>
                    <div class="invalid-feedback" x-text="errors.sexe"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Taille (cm) <span class="text-danger">*</span></label>
                    <input type="number" name="taille" class="form-control" min="50" max="250" data-required
                           :class="hasError('taille') && 'is-invalid'"
                           value="{{ old('taille', $donneesMedicales?->taille) }}">
                    <div class="invalid-feedback" x-text="errors.taille"></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Poids (kg) <span class="text-danger">*</span></label>
                    <input type="number" name="poids" class="form-control" min="20" max="300" data-required
                           :class="hasError('poids') && 'is-invalid'"
                           value="{{ old('poids', $donneesMedicales?->poids) }}">
                    <div class="invalid-feedback" x-text="errors.poids"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Médecin traitant --}}
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-hospital me-1"></i> Médecin traitant</h6>
        </div>
        <div class="card-body">
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
                           value="{{ old('medecin_telephone', $donneesMedicales?->medecin_telephone) }}" maxlength="30"
                           pattern="[\d\s\+\-\.\(\)]{6,30}" title="Numéro de téléphone valide">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Email</label>
                    <input type="email" name="medecin_email" class="form-control" :class="hasError('medecin_email') && 'is-invalid'"
                           value="{{ old('medecin_email', $donneesMedicales?->medecin_email) }}" maxlength="255">
                    <div class="invalid-feedback" x-text="errors.medecin_email"></div>
                </div>
            </div>
            <div class="mt-3">
                <label class="form-label">Adresse</label>
                <input type="text" name="medecin_adresse" class="form-control"
                       value="{{ old('medecin_adresse', $donneesMedicales?->medecin_adresse) }}" maxlength="500">
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <label class="form-label">Code postal</label>
                    <input type="text" name="medecin_code_postal" class="form-control"
                           value="{{ old('medecin_code_postal', $donneesMedicales?->medecin_code_postal) }}" maxlength="10"
                           pattern="\d{4,10}" title="Code postal (chiffres uniquement)">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Ville</label>
                    <input type="text" name="medecin_ville" class="form-control"
                           value="{{ old('medecin_ville', $donneesMedicales?->medecin_ville) }}" maxlength="100">
                </div>
            </div>
        </div>
    </div>

    {{-- Thérapeute référent --}}
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-person-badge me-1"></i> Thérapeute référent</h6>
        </div>
        <div class="card-body">
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
                           value="{{ old('therapeute_telephone', $donneesMedicales?->therapeute_telephone) }}" maxlength="30"
                           pattern="[\d\s\+\-\.\(\)]{6,30}" title="Numéro de téléphone valide">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Email</label>
                    <input type="email" name="therapeute_email" class="form-control" :class="hasError('therapeute_email') && 'is-invalid'"
                           value="{{ old('therapeute_email', $donneesMedicales?->therapeute_email) }}" maxlength="255">
                    <div class="invalid-feedback" x-text="errors.therapeute_email"></div>
                </div>
            </div>
            <div class="mt-3">
                <label class="form-label">Adresse</label>
                <input type="text" name="therapeute_adresse" class="form-control"
                       value="{{ old('therapeute_adresse', $donneesMedicales?->therapeute_adresse) }}" maxlength="500">
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <label class="form-label">Code postal</label>
                    <input type="text" name="therapeute_code_postal" class="form-control"
                           value="{{ old('therapeute_code_postal', $donneesMedicales?->therapeute_code_postal) }}" maxlength="10"
                           pattern="\d{4,10}" title="Code postal (chiffres uniquement)">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Ville</label>
                    <input type="text" name="therapeute_ville" class="form-control"
                           value="{{ old('therapeute_ville', $donneesMedicales?->therapeute_ville) }}" maxlength="100">
                </div>
            </div>
        </div>
    </div>

    {{-- Notes médicales --}}
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-journal-medical me-1"></i> Notes médicales</h6>
        </div>
        <div class="card-body">
            <textarea name="notes" class="form-control" rows="5" maxlength="1000"
                      placeholder="Allergies, traitements en cours, particularités...">{{ old('notes', $donneesMedicales?->notes) }}</textarea>
        </div>
    </div>
</div>
