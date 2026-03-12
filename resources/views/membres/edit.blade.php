<x-app-layout>
    <h1 class="mb-4">Modifier le membre : {{ $membre->prenom }} {{ $membre->nom }}</h1>

    <div class="card">
        <div class="card-body">
            <form action="{{ route('membres.update', $membre) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="nom" class="form-label">Nom <span class="text-danger">*</span></label>
                        <input type="text" name="nom" id="nom" class="form-control @error('nom') is-invalid @enderror"
                               value="{{ old('nom', $membre->nom) }}" required maxlength="100">
                        @error('nom')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="prenom" class="form-label">Prénom <span class="text-danger">*</span></label>
                        <input type="text" name="prenom" id="prenom" class="form-control @error('prenom') is-invalid @enderror"
                               value="{{ old('prenom', $membre->prenom) }}" required maxlength="100">
                        @error('prenom')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" name="email" id="email" class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email', $membre->email) }}" maxlength="150">
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="telephone" class="form-label">Téléphone</label>
                        <input type="text" name="telephone" id="telephone" class="form-control @error('telephone') is-invalid @enderror"
                               value="{{ old('telephone', $membre->telephone) }}" maxlength="20">
                        @error('telephone')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="adresse" class="form-label">Adresse</label>
                    <textarea name="adresse" id="adresse" class="form-control @error('adresse') is-invalid @enderror"
                              rows="2">{{ old('adresse', $membre->adresse) }}</textarea>
                    @error('adresse')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="date_adhesion" class="form-label">Date d'adhésion</label>
                        <input type="date" name="date_adhesion" id="date_adhesion"
                               class="form-control @error('date_adhesion') is-invalid @enderror"
                               value="{{ old('date_adhesion', $membre->date_adhesion?->format('Y-m-d')) }}">
                        @error('date_adhesion')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="statut" class="form-label">Statut <span class="text-danger">*</span></label>
                        <select name="statut" id="statut" class="form-select @error('statut') is-invalid @enderror" required>
                            @foreach (\App\Enums\StatutMembre::cases() as $statut)
                                <option value="{{ $statut->value }}" {{ old('statut', $membre->statut->value) === $statut->value ? 'selected' : '' }}>
                                    {{ $statut->label() }}
                                </option>
                            @endforeach
                        </select>
                        @error('statut')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="notes" class="form-label">Notes</label>
                    <textarea name="notes" id="notes" class="form-control @error('notes') is-invalid @enderror"
                              rows="2">{{ old('notes', $membre->notes) }}</textarea>
                    @error('notes')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success">Enregistrer</button>
                    <a href="{{ route('membres.show', $membre) }}" class="btn btn-secondary">Annuler</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
