<x-app-layout>
    <h1 class="mb-4">Modifier l'opération : {{ $operation->nom }}</h1>

    <div class="card">
        <div class="card-body">
            <form action="{{ route('operations.update', $operation) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="mb-3">
                    <label for="nom" class="form-label">Nom <span class="text-danger">*</span></label>
                    <input type="text" name="nom" id="nom" class="form-control @error('nom') is-invalid @enderror"
                           value="{{ old('nom', $operation->nom) }}" required maxlength="150">
                    @error('nom')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">Description</label>
                    <textarea name="description" id="description" class="form-control @error('description') is-invalid @enderror"
                              rows="3">{{ old('description', $operation->description) }}</textarea>
                    @error('description')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label for="date_debut" class="form-label">Date début <span class="text-danger">*</span></label>
                        <x-date-input name="date_debut" :value="old('date_debut', $operation->date_debut?->format('Y-m-d') ?? '')" />
                        @error('date_debut')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="date_fin" class="form-label">Date fin <span class="text-danger">*</span></label>
                        <x-date-input name="date_fin" :value="old('date_fin', $operation->date_fin?->format('Y-m-d') ?? '')" />
                        @error('date_fin')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="nombre_seances" class="form-label">Nombre de séances</label>
                        <input type="number" name="nombre_seances" id="nombre_seances" min="1"
                               class="form-control @error('nombre_seances') is-invalid @enderror"
                               value="{{ old('nombre_seances', $operation->nombre_seances) }}">
                        @error('nombre_seances')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="statut" class="form-label">Statut <span class="text-danger">*</span></label>
                    <select name="statut" id="statut" class="form-select @error('statut') is-invalid @enderror" required>
                        @foreach (\App\Enums\StatutOperation::cases() as $statut)
                            <option value="{{ $statut->value }}" {{ old('statut', $operation->statut->value) === $statut->value ? 'selected' : '' }}>
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
                    <a href="{{ route('operations.show', $operation) }}" class="btn btn-secondary">Annuler</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
