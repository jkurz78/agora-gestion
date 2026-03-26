@extends('formulaire.layout')

@section('title', 'Formulaire participant')

@section('content')
<div class="card shadow-sm mb-4">
    <div class="card-body p-4">
        <h4 class="card-title mb-1">Bonjour {{ $tiers->prenom }} {{ $tiers->nom }}</h4>
        <p class="text-muted mb-0">
            Votre inscription à <strong>{{ $operation->nom }}</strong>@if ($operation->date_debut || $operation->date_fin),
                @if ($operation->date_debut && $operation->date_fin)
                    du {{ $operation->date_debut->format('d/m/Y') }} au {{ $operation->date_fin->format('d/m/Y') }}
                @elseif ($operation->date_debut)
                    à partir du {{ $operation->date_debut->format('d/m/Y') }}
                @elseif ($operation->date_fin)
                    jusqu'au {{ $operation->date_fin->format('d/m/Y') }}
                @endif
            @endif
            @if ($operation->nombre_seances)
                &mdash; {{ $operation->nombre_seances }} séances
            @endif.
        </p>

        <hr>

        <form id="formulaireParticipant" action="{{ route('formulaire.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            {{-- Section Coordonnées --}}
            <h5 class="mb-3"><i class="bi bi-person-lines-fill me-1"></i> Coordonnées</h5>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="telephone" class="form-label">Téléphone</label>
                    <input type="text" name="telephone" id="telephone" class="form-control @error('telephone') is-invalid @enderror"
                           value="{{ old('telephone', $tiers->telephone) }}" maxlength="30">
                    @error('telephone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" name="email" id="email" class="form-control @error('email') is-invalid @enderror"
                           value="{{ old('email', $tiers->email) }}" maxlength="255">
                    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="mb-3">
                <label for="adresse_ligne1" class="form-label">Adresse</label>
                <input type="text" name="adresse_ligne1" id="adresse_ligne1" class="form-control @error('adresse_ligne1') is-invalid @enderror"
                       value="{{ old('adresse_ligne1', $tiers->adresse_ligne1) }}" maxlength="500">
                @error('adresse_ligne1') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="row mb-3">
                <div class="col-md-4">
                    <label for="code_postal" class="form-label">Code postal</label>
                    <input type="text" name="code_postal" id="code_postal" class="form-control @error('code_postal') is-invalid @enderror"
                           value="{{ old('code_postal', $tiers->code_postal) }}" maxlength="10">
                    @error('code_postal') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-8">
                    <label for="ville" class="form-label">Ville</label>
                    <input type="text" name="ville" id="ville" class="form-control @error('ville') is-invalid @enderror"
                           value="{{ old('ville', $tiers->ville) }}" maxlength="100">
                    @error('ville') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>

            <hr>

            {{-- Section Données de santé --}}
            <h5 class="mb-3"><i class="bi bi-heart-pulse me-1"></i> Données de santé</h5>

            <div class="alert alert-secondary small py-2">
                <i class="bi bi-shield-lock me-1"></i>
                Ces informations sont confidentielles et chiffrées.
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="date_naissance" class="form-label">Date de naissance</label>
                    <input type="date" name="date_naissance" id="date_naissance" class="form-control @error('date_naissance') is-invalid @enderror"
                           value="{{ old('date_naissance') }}">
                    @error('date_naissance') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                    <label for="sexe" class="form-label">Sexe</label>
                    <select name="sexe" id="sexe" class="form-select @error('sexe') is-invalid @enderror">
                        <option value="">--</option>
                        <option value="M" @selected(old('sexe') === 'M')>Masculin</option>
                        <option value="F" @selected(old('sexe') === 'F')>Féminin</option>
                    </select>
                    @error('sexe') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="taille" class="form-label">Taille (cm)</label>
                    <input type="number" name="taille" id="taille" class="form-control @error('taille') is-invalid @enderror"
                           value="{{ old('taille') }}" min="50" max="250" step="1">
                    @error('taille') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                    <label for="poids" class="form-label">Poids (kg)</label>
                    <input type="number" name="poids" id="poids" class="form-control @error('poids') is-invalid @enderror"
                           value="{{ old('poids') }}" min="20" max="300" step="0.1">
                    @error('poids') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="mb-3">
                <label for="notes" class="form-label">Informations complémentaires</label>
                <textarea name="notes" id="notes" class="form-control @error('notes') is-invalid @enderror"
                          rows="3" maxlength="1000" placeholder="Allergies, traitements en cours, etc.">{{ old('notes') }}</textarea>
                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <hr>

            {{-- Section Documents --}}
            <h5 class="mb-3"><i class="bi bi-paperclip me-1"></i> Documents</h5>
            <p class="text-muted small">
                Vous pouvez joindre jusqu'à 3 documents (certificat médical, attestation, etc.)
                &mdash; formats PDF, JPG ou PNG, 5 Mo maximum par fichier.
            </p>

            @for ($i = 0; $i < 3; $i++)
                <div class="mb-2">
                    <input type="file" name="documents[]" class="form-control form-control-sm"
                           accept=".pdf,.jpg,.jpeg,.png">
                </div>
            @endfor
            @error('documents.*') <div class="text-danger small mt-1">{{ $message }}</div> @enderror

            <hr>

            {{-- Submit --}}
            <div class="d-grid">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#confirmModal" onclick="buildRecap()">
                    <i class="bi bi-send me-1"></i> Envoyer
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Confirmation Modal --}}
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmModalLabel">Vérifiez vos informations</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <div id="recapContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-pencil me-1"></i> Modifier
                </button>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('formulaireParticipant').submit()">
                    <i class="bi bi-check-lg me-1"></i> Confirmer
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function buildRecap() {
    const fields = [
        { id: 'telephone', label: 'Téléphone' },
        { id: 'email', label: 'Email' },
        { id: 'adresse_ligne1', label: 'Adresse' },
        { id: 'code_postal', label: 'Code postal' },
        { id: 'ville', label: 'Ville' },
        { id: 'date_naissance', label: 'Date de naissance' },
        { id: 'sexe', label: 'Sexe' },
        { id: 'taille', label: 'Taille (cm)' },
        { id: 'poids', label: 'Poids (kg)' },
        { id: 'notes', label: 'Informations' },
    ];

    let html = '<table class="table table-sm"><tbody>';
    for (const f of fields) {
        const el = document.getElementById(f.id);
        let val = '';
        if (el.tagName === 'SELECT') {
            val = el.options[el.selectedIndex]?.text || '';
            if (val === '--') val = '';
        } else {
            val = el.value;
        }
        if (val) {
            html += '<tr><th class="text-muted small" style="width:40%">' + f.label + '</th>';
            html += '<td>' + val.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</td></tr>';
        }
    }

    // Documents
    const fileInputs = document.querySelectorAll('input[type="file"][name="documents[]"]');
    const fileNames = [];
    fileInputs.forEach(input => {
        if (input.files.length > 0) {
            fileNames.push(input.files[0].name);
        }
    });
    if (fileNames.length > 0) {
        html += '<tr><th class="text-muted small">Documents</th><td>' + fileNames.join(', ') + '</td></tr>';
    }

    html += '</tbody></table>';

    if (html === '<table class="table table-sm"><tbody></tbody></table>') {
        html = '<p class="text-muted">Aucune information saisie.</p>';
    }

    document.getElementById('recapContent').innerHTML = html;
}
</script>
@endsection
