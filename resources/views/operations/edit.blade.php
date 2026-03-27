<x-app-layout>
    <h1 class="mb-4">Modifier l'opération : {{ $operation->nom }}</h1>

    <div class="card">
        <div class="card-body">
            <form action="{{ route('compta.operations.update', $operation) }}" method="POST">
                @csrf
                @method('PUT')
                @if(request('_redirect_back'))
                    <input type="hidden" name="_redirect_back" value="{{ request('_redirect_back') }}">
                @endif

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
                    <label for="type_operation_id" class="form-label">Type d'opération <span class="text-danger">*</span></label>
                    @if ($hasParticipants)
                        <div class="alert alert-warning py-2 mb-2">
                            <i class="bi bi-lock-fill me-1"></i>Le type ne peut plus être modifié car des participants sont inscrits.
                        </div>
                    @endif
                    <div class="input-group">
                        <select name="type_operation_id" id="type_operation_id"
                                class="form-select @error('type_operation_id') is-invalid @enderror"
                                required {{ $hasParticipants ? 'disabled' : '' }}>
                            <option value="">— Sélectionner —</option>
                            @foreach ($typeOperations as $type)
                                <option value="{{ $type->id }}" data-nombre-seances="{{ $type->nombre_seances }}"
                                    {{ old('type_operation_id', $operation->type_operation_id) == $type->id ? 'selected' : '' }}>
                                    {{ $type->code }} — {{ $type->nom }}
                                </option>
                            @endforeach
                        </select>
                        @unless ($hasParticipants)
                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#typeOperationModal"
                                    onclick="Livewire.dispatch('openTypeOperationModal')">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        @endunless
                    </div>
                    @if ($hasParticipants)
                        <input type="hidden" name="type_operation_id" value="{{ $operation->type_operation_id }}">
                    @endif
                    @error('type_operation_id')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
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
                    <a href="{{ request('_redirect_back', route('compta.operations.show', $operation)) }}" class="btn btn-secondary">Annuler</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Livewire pour créer un type d'opération --}}
    @livewire('type-operation-manager')

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const typeSelect = document.getElementById('type_operation_id');
            const seancesInput = document.getElementById('nombre_seances');

            // Pre-fill nombre_seances when type changes
            typeSelect.addEventListener('change', function () {
                const selected = typeSelect.options[typeSelect.selectedIndex];
                const seances = selected.getAttribute('data-nombre-seances');
                if (seances && !seancesInput.value) {
                    seancesInput.value = seances;
                }
            });

            // Listen for typeOperationCreated from Livewire
            Livewire.on('typeOperationCreated', (params) => {
                const id = Array.isArray(params) ? params[0]?.id : params.id;
                // Reload the page to get the updated list with the new type selected
                const url = new URL(window.location.href);
                url.searchParams.set('_new_type', id);
                window.location.href = url.toString();
            });
        });

        // Auto-select newly created type after page reload
        document.addEventListener('DOMContentLoaded', function () {
            const url = new URL(window.location.href);
            const newType = url.searchParams.get('_new_type');
            if (newType) {
                const typeSelect = document.getElementById('type_operation_id');
                if (typeSelect) {
                    typeSelect.value = newType;
                    typeSelect.dispatchEvent(new Event('change'));
                }
                // Clean up the URL
                url.searchParams.delete('_new_type');
                window.history.replaceState({}, '', url.toString());
            }
        });
    </script>
    @endpush
</x-app-layout>
