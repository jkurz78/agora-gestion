<x-app-layout>
    <h1 class="mb-4">Sous-catégories</h1>

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
                <th class="text-center">Dons</th>
                <th class="text-center">Cotisations</th>
                <th style="width: 120px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($sousCategories as $sc)
                <tr data-type="{{ $sc->categorie->type->value }}" data-categorie="{{ $sc->categorie_id }}">
                    @if (request('edit') == $sc->id)
                        {{-- Inline edit row --}}
                        <form action="{{ route('parametres.sous-categories.update', $sc) }}" method="POST"
                              id="edit-form-{{ $sc->id }}">
                            @csrf
                            @method('PUT')
                        </form>
                        <td>
                            <select name="categorie_id" form="edit-form-{{ $sc->id }}"
                                    class="form-select form-select-sm">
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}" @selected($cat->id === $sc->categorie_id)>
                                        {{ $cat->nom }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <input type="text" name="nom" form="edit-form-{{ $sc->id }}"
                                   value="{{ old('nom', $sc->nom) }}" class="form-control form-control-sm" required maxlength="100">
                        </td>
                        <td>
                            <input type="text" name="code_cerfa" form="edit-form-{{ $sc->id }}"
                                   value="{{ old('code_cerfa', $sc->code_cerfa) }}" class="form-control form-control-sm" maxlength="10">
                        </td>
                        <td class="text-center">
                            <input type="hidden" name="pour_dons" form="edit-form-{{ $sc->id }}" value="0">
                            <input type="checkbox" name="pour_dons" form="edit-form-{{ $sc->id }}" value="1"
                                   class="form-check-input" @checked(old('pour_dons', $sc->pour_dons))>
                        </td>
                        <td class="text-center">
                            <input type="hidden" name="pour_cotisations" form="edit-form-{{ $sc->id }}" value="0">
                            <input type="checkbox" name="pour_cotisations" form="edit-form-{{ $sc->id }}" value="1"
                                   class="form-check-input" @checked(old('pour_cotisations', $sc->pour_cotisations))>
                        </td>
                        <td>
                            <button type="submit" form="edit-form-{{ $sc->id }}" class="btn btn-sm btn-success"
                                    style="padding:.15rem .4rem;font-size:.75rem">
                                <i class="bi bi-check-lg"></i>
                            </button>
                            <a href="{{ route('parametres.sous-categories.index') }}" class="btn btn-sm btn-outline-secondary"
                               style="padding:.15rem .4rem;font-size:.75rem">
                                <i class="bi bi-x-lg"></i>
                            </a>
                        </td>
                    @else
                        <td>{{ $sc->categorie->nom }}</td>
                        <td>{{ $sc->nom }}</td>
                        <td>{{ $sc->code_cerfa ?? '—' }}</td>
                        <td class="text-center">
                            <form action="{{ route('parametres.sous-categories.toggle-flag', $sc) }}" method="POST" class="d-inline">
                                @csrf
                                <input type="hidden" name="flag" value="pour_dons">
                                <button type="submit"
                                        class="btn btn-sm {{ $sc->pour_dons ? 'btn-success' : 'btn-outline-secondary' }}"
                                        style="padding:.15rem .4rem;font-size:.7rem"
                                        title="{{ $sc->pour_dons ? 'Désactiver pour les dons' : 'Activer pour les dons' }}">
                                    {{ $sc->pour_dons ? '✓' : '–' }}
                                </button>
                            </form>
                        </td>
                        <td class="text-center">
                            <form action="{{ route('parametres.sous-categories.toggle-flag', $sc) }}" method="POST" class="d-inline">
                                @csrf
                                <input type="hidden" name="flag" value="pour_cotisations">
                                <button type="submit"
                                        class="btn btn-sm {{ $sc->pour_cotisations ? 'btn-success' : 'btn-outline-secondary' }}"
                                        style="padding:.15rem .4rem;font-size:.7rem"
                                        title="{{ $sc->pour_cotisations ? 'Désactiver pour les cotisations' : 'Activer pour les cotisations' }}">
                                    {{ $sc->pour_cotisations ? '✓' : '–' }}
                                </button>
                            </form>
                        </td>
                        <td>
                            <a href="{{ route('parametres.sous-categories.index') }}?edit={{ $sc->id }}"
                               class="btn btn-sm btn-outline-primary"
                               style="padding:.15rem .35rem;font-size:.75rem" title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="{{ route('parametres.sous-categories.destroy', $sc) }}" method="POST" class="d-inline"
                                  onsubmit="return confirm('Supprimer cette sous-catégorie ?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        style="padding:.15rem .35rem;font-size:.75rem">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-muted">Aucune sous-catégorie enregistrée.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <script>
    function filterSousCategories() {
        var typeVal = document.querySelector('input[name="scTypeFilter"]:checked').value;
        var catVal = document.getElementById('scCatFilter').value;
        document.querySelectorAll('tr[data-type]').forEach(function(row) {
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
</x-app-layout>
