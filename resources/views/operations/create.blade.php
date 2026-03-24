<x-app-layout>
    <h1 class="mb-4">Ajouter une opération</h1>

    <div class="card">
        <div class="card-body">
            <form action="{{ route('compta.operations.store') }}" method="POST">
                @csrf

                <div class="mb-3">
                    <label for="nom" class="form-label">Nom <span class="text-danger">*</span></label>
                    <input type="text" name="nom" id="nom" class="form-control @error('nom') is-invalid @enderror"
                           value="{{ old('nom') }}" required maxlength="150">
                    @error('nom')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">Description</label>
                    <textarea name="description" id="description" class="form-control @error('description') is-invalid @enderror"
                              rows="3">{{ old('description') }}</textarea>
                    @error('description')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label for="date_debut" class="form-label">Date début <span class="text-danger">*</span></label>
                        <x-date-input name="date_debut" :value="old('date_debut', '')" />
                        @error('date_debut')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="date_fin" class="form-label">Date fin <span class="text-danger">*</span></label>
                        <x-date-input name="date_fin" :value="old('date_fin', '')" />
                        @error('date_fin')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="nombre_seances" class="form-label">Nombre de séances</label>
                        <input type="number" name="nombre_seances" id="nombre_seances" min="1"
                               class="form-control @error('nombre_seances') is-invalid @enderror"
                               value="{{ old('nombre_seances') }}">
                        @error('nombre_seances')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="sous_categorie_id" class="form-label">Sous-catégorie (inscriptions)</label>
                    <select name="sous_categorie_id" id="sous_categorie_id" class="form-select @error('sous_categorie_id') is-invalid @enderror">
                        <option value="">— Aucune (utiliser la valeur par défaut) —</option>
                        @foreach ($sousCategories as $sc)
                            <option value="{{ $sc->id }}" {{ old('sous_categorie_id') == $sc->id ? 'selected' : '' }}>
                                {{ $sc->nom }} ({{ $sc->code_cerfa }})
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">Si vide, la sous-catégorie par défaut configurée dans les paramètres HelloAsso sera utilisée.</div>
                    @error('sous_categorie_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="statut" class="form-label">Statut <span class="text-danger">*</span></label>
                    <select name="statut" id="statut" class="form-select @error('statut') is-invalid @enderror" required>
                        @foreach (\App\Enums\StatutOperation::cases() as $statut)
                            <option value="{{ $statut->value }}" {{ old('statut', 'en_cours') === $statut->value ? 'selected' : '' }}>
                                {{ $statut->label() }}
                            </option>
                        @endforeach
                    </select>
                    @error('statut')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success">Enregistrer</button>
                    <a href="{{ route('compta.operations.index') }}" class="btn btn-secondary">Annuler</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
