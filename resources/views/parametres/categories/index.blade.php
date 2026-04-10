<x-app-layout>
    <x-slot:title>Catégories</x-slot:title>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="mb-3 d-flex gap-2 align-items-center flex-wrap">
        <div class="btn-group btn-group-sm" role="group" aria-label="Filtre catégories">
            <input type="radio" class="btn-check" name="catFilter" id="catAll" value="all" checked autocomplete="off">
            <label class="btn btn-outline-secondary" for="catAll">Tout</label>
            <input type="radio" class="btn-check" name="catFilter" id="catRecette" value="recette" autocomplete="off">
            <label class="btn btn-outline-secondary" for="catRecette">Recettes</label>
            <input type="radio" class="btn-check" name="catFilter" id="catDepense" value="depense" autocomplete="off">
            <label class="btn btn-outline-secondary" for="catDepense">Dépenses</label>
        </div>
        <button class="btn btn-primary btn-sm ms-auto" type="button" data-bs-toggle="collapse"
                data-bs-target="#addCategorieForm">
            <i class="bi bi-plus-lg"></i> Ajouter une catégorie
        </button>
    </div>

    <div class="collapse mb-3" id="addCategorieForm">
        <div class="card card-body">
            <form action="{{ route($espace->value . '.parametres.categories.store') }}" method="POST" class="row g-2 align-items-end">
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

    <table class="table table-sm table-striped table-hover">
        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
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
                        <form action="{{ route($espace->value . '.parametres.categories.update', $categorie) }}" method="POST" class="d-inline">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="nom" value="{{ $categorie->nom }}">
                            <input type="hidden" name="type" value="{{ $categorie->type->value }}">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    onclick="editCategorie(this, {{ $categorie->id }}, '{{ addslashes($categorie->nom) }}', '{{ $categorie->type->value }}')">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </form>
                        <form action="{{ route($espace->value . '.parametres.categories.destroy', $categorie) }}" method="POST" class="d-inline"
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
            document.querySelectorAll('tr[data-type]').forEach(function(row) {
                row.style.display = (val === 'all' || row.dataset.type === val) ? '' : 'none';
            });
        });
    });

    function editCategorie(btn, id, nom, type) {
        const newNom = prompt('Nom de la catégorie :', nom);
        if (newNom === null) return;
        const form = btn.closest('form');
        form.querySelector('input[name="nom"]').value = newNom;
        form.submit();
    }
    </script>
</x-app-layout>
