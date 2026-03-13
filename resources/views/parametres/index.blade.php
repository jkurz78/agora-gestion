<x-app-layout>
    <h1 class="mb-4">Paramètres</h1>

    {{-- Tab navigation --}}
    <ul class="nav nav-tabs" id="parametresTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="categories-tab" data-bs-toggle="tab"
                    data-bs-target="#categories-pane" type="button" role="tab"
                    aria-controls="categories-pane" aria-selected="true">
                <i class="bi bi-tags"></i> Catégories
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="sous-categories-tab" data-bs-toggle="tab"
                    data-bs-target="#sous-categories-pane" type="button" role="tab"
                    aria-controls="sous-categories-pane" aria-selected="false">
                <i class="bi bi-tag"></i> Sous-catégories
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="comptes-tab" data-bs-toggle="tab"
                    data-bs-target="#comptes-pane" type="button" role="tab"
                    aria-controls="comptes-pane" aria-selected="false">
                <i class="bi bi-bank"></i> Comptes bancaires
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="utilisateurs-tab" data-bs-toggle="tab"
                    data-bs-target="#utilisateurs-pane" type="button" role="tab"
                    aria-controls="utilisateurs-pane" aria-selected="false">
                <i class="bi bi-people"></i> Utilisateurs
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="operations-tab" data-bs-toggle="tab"
                    data-bs-target="#operations-pane" type="button" role="tab"
                    aria-controls="operations-pane" aria-selected="false">
                <i class="bi bi-calendar-event"></i> Opérations
            </button>
        </li>
    </ul>

    <div class="tab-content pt-3" id="parametresTabContent">

        {{-- ========== Catégories ========== --}}
        <div class="tab-pane fade show active" id="categories-pane" role="tabpanel" aria-labelledby="categories-tab">
            <div class="mb-3 d-flex gap-2 align-items-center flex-wrap">
                <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse"
                        data-bs-target="#addCategorieForm">
                    <i class="bi bi-plus-lg"></i> Ajouter une catégorie
                </button>
                <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Filtre catégories">
                    <input type="radio" class="btn-check" name="catFilter" id="catAll" value="all" checked autocomplete="off">
                    <label class="btn btn-outline-secondary" for="catAll">Tout</label>
                    <input type="radio" class="btn-check" name="catFilter" id="catRecette" value="recette" autocomplete="off">
                    <label class="btn btn-outline-secondary" for="catRecette">Recettes</label>
                    <input type="radio" class="btn-check" name="catFilter" id="catDepense" value="depense" autocomplete="off">
                    <label class="btn btn-outline-secondary" for="catDepense">Dépenses</label>
                </div>
            </div>

            <div class="collapse mb-3" id="addCategorieForm">
                <div class="card card-body">
                    <form action="{{ route('parametres.categories.store') }}" method="POST" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-md-5">
                            <label for="cat_nom" class="form-label">Nom</label>
                            <input type="text" name="nom" id="cat_nom" class="form-control" required maxlength="100">
                        </div>
                        <div class="col-md-4">
                            <label for="cat_type" class="form-label">Type</label>
                            <select name="type" id="cat_type" class="form-select" required>
                                <option value="">-- Choisir --</option>
                                @foreach (\App\Enums\TypeCategorie::cases() as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-success w-100">Enregistrer</button>
                        </div>
                    </form>
                </div>
            </div>

            <table class="table table-striped table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Nom</th>
                        <th>Type</th>
                        <th>Sous-catégories</th>
                        <th style="width: 180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categories as $categorie)
                        <tr data-type="{{ $categorie->type->value }}">
                            <td>{{ $categorie->nom }}</td>
                            <td>
                                <span class="badge {{ $categorie->type === \App\Enums\TypeCategorie::Depense ? 'bg-danger' : 'bg-success' }}">
                                    {{ $categorie->type->label() }}
                                </span>
                            </td>
                            <td>{{ $categorie->sousCategories->count() }}</td>
                            <td>
                                <form action="{{ route('parametres.categories.update', $categorie) }}" method="POST" class="d-inline">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="nom" value="{{ $categorie->nom }}">
                                    <input type="hidden" name="type" value="{{ $categorie->type->value }}">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            onclick="editCategorie(this, {{ $categorie->id }}, '{{ addslashes($categorie->nom) }}', '{{ $categorie->type->value }}')">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </form>
                                <form action="{{ route('parametres.categories.destroy', $categorie) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('Supprimer cette catégorie ?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-muted">Aucune catégorie enregistrée.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <script>
            document.querySelectorAll('input[name="catFilter"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    const val = this.value;
                    document.querySelectorAll('#categories-pane tr[data-type]').forEach(function(row) {
                        row.style.display = (val === 'all' || row.dataset.type === val) ? '' : 'none';
                    });
                });
            });
            </script>
        </div>

        {{-- ========== Sous-catégories ========== --}}
        <div class="tab-pane fade" id="sous-categories-pane" role="tabpanel" aria-labelledby="sous-categories-tab">
            <div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
                <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse"
                        data-bs-target="#addSousCategorieForm">
                    <i class="bi bi-plus-lg"></i> Ajouter une sous-catégorie
                </button>
                <div class="btn-group btn-group-sm" role="group" aria-label="Filtre type">
                    <input type="radio" class="btn-check" name="scTypeFilter" id="scAll" value="all" checked autocomplete="off">
                    <label class="btn btn-outline-secondary" for="scAll">Tout</label>
                    <input type="radio" class="btn-check" name="scTypeFilter" id="scRecette" value="recette" autocomplete="off">
                    <label class="btn btn-outline-secondary" for="scRecette">Recettes</label>
                    <input type="radio" class="btn-check" name="scTypeFilter" id="scDepense" value="depense" autocomplete="off">
                    <label class="btn btn-outline-secondary" for="scDepense">Dépenses</label>
                </div>
                <select id="scCatFilter" class="form-select form-select-sm" style="width:auto;">
                    <option value="">— Toutes les catégories —</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->nom }}</option>
                    @endforeach
                </select>
            </div>

            <div class="collapse mb-3" id="addSousCategorieForm">
                <div class="card card-body">
                    <form action="{{ route('parametres.sous-categories.store') }}" method="POST" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-md-4">
                            <label for="sc_categorie" class="form-label">Catégorie</label>
                            <select name="categorie_id" id="sc_categorie" class="form-select" required>
                                <option value="">-- Choisir --</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->nom }} ({{ $cat->type->label() }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="sc_nom" class="form-label">Nom</label>
                            <input type="text" name="nom" id="sc_nom" class="form-control" required maxlength="100">
                        </div>
                        <div class="col-md-2">
                            <label for="sc_cerfa" class="form-label">Code CERFA</label>
                            <input type="text" name="code_cerfa" id="sc_cerfa" class="form-control" maxlength="10">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-success w-100">Enregistrer</button>
                        </div>
                    </form>
                </div>
            </div>

            <table class="table table-striped table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Catégorie</th>
                        <th>Nom</th>
                        <th>Code CERFA</th>
                        <th style="width: 180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $sousCategories = $categories->flatMap->sousCategories->sortBy('nom');
                    @endphp
                    @forelse ($sousCategories as $sc)
                        <tr data-type="{{ $sc->categorie->type->value }}" data-categorie="{{ $sc->categorie_id }}">
                            <td>{{ $sc->categorie->nom }}</td>
                            <td>{{ $sc->nom }}</td>
                            <td>{{ $sc->code_cerfa ?? '—' }}</td>
                            <td>
                                <form action="{{ route('parametres.sous-categories.destroy', $sc) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('Supprimer cette sous-catégorie ?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-muted">Aucune sous-catégorie enregistrée.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <script>
            function filterSousCategories() {
                var typeVal = document.querySelector('input[name="scTypeFilter"]:checked').value;
                var catVal = document.getElementById('scCatFilter').value;
                document.querySelectorAll('#sous-categories-pane tr[data-type]').forEach(function(row) {
                    var typeOk = typeVal === 'all' || row.dataset.type === typeVal;
                    var catOk = catVal === '' || row.dataset.categorie === catVal;
                    row.style.display = (typeOk && catOk) ? '' : 'none';
                });
            }
            document.querySelectorAll('input[name="scTypeFilter"]').forEach(function(r) {
                r.addEventListener('change', filterSousCategories);
            });
            var scCatFilter = document.getElementById('scCatFilter');
            if (scCatFilter) { scCatFilter.addEventListener('change', filterSousCategories); }
            </script>
        </div>

        {{-- ========== Comptes bancaires ========== --}}
        <div class="tab-pane fade" id="comptes-pane" role="tabpanel" aria-labelledby="comptes-tab">
            <div class="mb-3">
                <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse"
                        data-bs-target="#addCompteForm">
                    <i class="bi bi-plus-lg"></i> Ajouter un compte bancaire
                </button>
            </div>

            <div class="collapse mb-3" id="addCompteForm">
                <div class="card card-body">
                    <form action="{{ route('parametres.comptes-bancaires.store') }}" method="POST" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-md-3">
                            <label for="cb_nom" class="form-label">Nom</label>
                            <input type="text" name="nom" id="cb_nom" class="form-control" required maxlength="150">
                        </div>
                        <div class="col-md-3">
                            <label for="cb_iban" class="form-label">IBAN</label>
                            <input type="text" name="iban" id="cb_iban" class="form-control" maxlength="34">
                        </div>
                        <div class="col-md-2">
                            <label for="cb_solde" class="form-label">Solde initial</label>
                            <input type="number" name="solde_initial" id="cb_solde" class="form-control" required step="0.01">
                        </div>
                        <div class="col-md-2">
                            <label for="cb_date" class="form-label">Date solde</label>
                            <input type="date" name="date_solde_initial" id="cb_date" class="form-control" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-success w-100">Enregistrer</button>
                        </div>
                    </form>
                </div>
            </div>

            <table class="table table-striped table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Nom</th>
                        <th>IBAN</th>
                        <th>Solde initial</th>
                        <th>Date solde</th>
                        <th style="width: 180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($comptesBancaires as $compte)
                        <tr>
                            <td>{{ $compte->nom }}</td>
                            <td>{{ $compte->iban ?? '—' }}</td>
                            <td>{{ number_format((float) $compte->solde_initial, 2, ',', ' ') }} &euro;</td>
                            <td>{{ $compte->date_solde_initial->format('d/m/Y') }}</td>
                            <td>
                                <form action="{{ route('parametres.comptes-bancaires.destroy', $compte) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('Supprimer ce compte bancaire ?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-muted">Aucun compte bancaire enregistré.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- ========== Utilisateurs ========== --}}
        <div class="tab-pane fade" id="utilisateurs-pane" role="tabpanel" aria-labelledby="utilisateurs-tab">
            {{-- Formulaire d'ajout --}}
            <div class="mb-3">
                <button class="btn btn-primary btn-sm" type="button"
                        data-bs-toggle="collapse" data-bs-target="#addUserForm">
                    <i class="bi bi-plus-lg"></i> Ajouter un utilisateur
                </button>
            </div>
            <div class="collapse mb-3" id="addUserForm">
                <div class="card card-body">
                    <form action="{{ route('parametres.utilisateurs.store') }}" method="POST" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-md-3">
                            <label class="form-label">Nom</label>
                            <input type="text" name="nom" class="form-control @error('nom') is-invalid @enderror"
                                   value="{{ old('nom') }}" required maxlength="100">
                            @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                   value="{{ old('email') }}" required maxlength="150">
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Mot de passe</label>
                            <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                                   required>
                            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Confirmer</label>
                            <input type="password" name="password_confirmation" class="form-control" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-success w-100">Enregistrer</button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Table des utilisateurs --}}
            <table class="table table-sm table-striped table-hover">
                <thead class="table-dark">
                    <tr><th>Nom</th><th>Email</th><th style="width:100px;"></th></tr>
                </thead>
                <tbody>
                    @forelse ($utilisateurs as $utilisateur)
                        <tr>
                            <td>{{ $utilisateur->nom }}</td>
                            <td>{{ $utilisateur->email }}</td>
                            <td>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#editUser{{ $utilisateur->id }}"
                                            title="Modifier">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    @if ($utilisateur->id !== auth()->id())
                                        <form method="POST"
                                              action="{{ route('parametres.utilisateurs.destroy', $utilisateur) }}"
                                              onsubmit="return confirm('Supprimer cet utilisateur ?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        {{-- Formulaire d'édition en collapse --}}
                        <tr class="collapse" id="editUser{{ $utilisateur->id }}">
                            <td colspan="3" class="bg-light">
                                <form action="{{ route('parametres.utilisateurs.update', $utilisateur) }}"
                                      method="POST" class="row g-2 align-items-end p-2">
                                    @csrf @method('PUT')
                                    <div class="col-md-3">
                                        <label class="form-label">Nom</label>
                                        <input type="text" name="nom" class="form-control"
                                               value="{{ $utilisateur->nom }}" required maxlength="100">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control"
                                               value="{{ $utilisateur->email }}" required maxlength="150">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Nouveau mdp <span class="text-muted">(opt.)</span></label>
                                        <input type="password" name="password" class="form-control">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Confirmer</label>
                                        <input type="password" name="password_confirmation" class="form-control">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-success w-100">Mettre à jour</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-muted">Aucun utilisateur.</td></tr>
                    @endforelse
                </tbody>
            </table>

            @if ($errors->hasAny(['nom', 'email', 'password']))
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var el = document.getElementById('addUserForm');
                    if (el) { new bootstrap.Collapse(el, { toggle: false }).show(); }
                });
            </script>
            @endif
        </div>

        {{-- ========== Opérations ========== --}}
        <div class="tab-pane fade" id="operations-pane" role="tabpanel" aria-labelledby="operations-tab">
            <div class="mb-3 d-flex gap-2 align-items-center flex-wrap">
                <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse"
                        data-bs-target="#addOperationForm">
                    <i class="bi bi-plus-lg"></i> Ajouter une opération
                </button>
                <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Filtre opérations">
                    <input type="radio" class="btn-check" name="opFilter" id="opAll" value="all" checked autocomplete="off">
                    <label class="btn btn-outline-secondary" for="opAll">Tout</label>
                    <input type="radio" class="btn-check" name="opFilter" id="opEnCours" value="en_cours" autocomplete="off">
                    <label class="btn btn-outline-secondary" for="opEnCours">En cours</label>
                    <input type="radio" class="btn-check" name="opFilter" id="opCloture" value="cloturee" autocomplete="off">
                    <label class="btn btn-outline-secondary" for="opCloture">Clôturées</label>
                </div>
            </div>

            <div class="collapse mb-3" id="addOperationForm">
                <div class="card card-body">
                    <form action="{{ route('operations.store') }}" method="POST" class="row g-2 align-items-end">
                        @csrf
                        <input type="hidden" name="_redirect_back" value="{{ route('parametres.categories.index') }}">
                        <div class="col-md-3">
                            <label for="op_nom" class="form-label">Nom</label>
                            <input type="text" name="nom" id="op_nom" class="form-control" required maxlength="150">
                        </div>
                        <div class="col-md-3">
                            <label for="op_description" class="form-label">Description</label>
                            <input type="text" name="description" id="op_description" class="form-control" maxlength="255">
                        </div>
                        <div class="col-md-2">
                            <label for="op_date_debut" class="form-label">Date début</label>
                            <input type="date" name="date_debut" id="op_date_debut" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label for="op_date_fin" class="form-label">Date fin</label>
                            <input type="date" name="date_fin" id="op_date_fin" class="form-control">
                        </div>
                        <div class="col-md-1">
                            <label for="op_seances" class="form-label">Séances</label>
                            <input type="number" name="nombre_seances" id="op_seances" class="form-control" min="1">
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-success w-100">Enregistrer</button>
                        </div>
                    </form>
                </div>
            </div>

            <table class="table table-striped table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Nom</th>
                        <th>Description</th>
                        <th>Date début</th>
                        <th>Date fin</th>
                        <th>Séances</th>
                        <th>Statut</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($operations as $operation)
                        <tr data-statut="{{ $operation->statut->value }}">
                            <td>{{ $operation->nom }}</td>
                            <td>{{ $operation->description ?? '—' }}</td>
                            <td>{{ $operation->date_debut?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $operation->date_fin?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $operation->nombre_seances ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $operation->statut === \App\Enums\StatutOperation::EnCours ? 'bg-primary' : 'bg-secondary' }}">
                                    {{ $operation->statut->label() }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('operations.edit', $operation) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-muted">Aucune opération enregistrée.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <script>
            document.querySelectorAll('input[name="opFilter"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    const val = this.value;
                    document.querySelectorAll('#operations-pane tr[data-statut]').forEach(function(row) {
                        row.style.display = (val === 'all' || row.dataset.statut === val) ? '' : 'none';
                    });
                });
            });
            </script>
        </div>
    </div>

    <script>
        function editCategorie(btn, id, nom, type) {
            const newNom = prompt('Nom de la catégorie :', nom);
            if (newNom === null) return;
            const form = btn.closest('form');
            form.querySelector('input[name="nom"]').value = newNom;
            form.submit();
        }
    </script>

    @if(session('activeTab') === 'comptes')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            bootstrap.Tab.getOrCreateInstance(
                document.getElementById('comptes-tab')
            ).show();
        });
    </script>
    @endif
</x-app-layout>
