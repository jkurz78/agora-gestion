<x-app-layout>
    <h1 class="mb-4">Ajouter une opération</h1>

    <div class="card">
        <div class="card-body">
            <form action="{{ route('compta.operations.store') }}" method="POST">
                @csrf
                @if(request('_redirect_back'))
                    <input type="hidden" name="_redirect_back" value="{{ request('_redirect_back') }}">
                @endif

                {{-- Type d'opération (en premier) --}}
                <div class="mb-3">
                    <label for="type_operation_id" class="form-label">Type d'opération <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <select name="type_operation_id" id="type_operation_id" class="form-select @error('type_operation_id') is-invalid @enderror" required>
                            <option value="" data-code="">— Sélectionner —</option>
                            @foreach ($typeOperations as $type)
                                <option value="{{ $type->id }}" data-nombre-seances="{{ $type->nombre_seances }}" data-code="{{ $type->code }}"
                                    {{ old('type_operation_id') == $type->id ? 'selected' : '' }}>
                                    {{ $type->code }} — {{ $type->nom }}
                                </option>
                            @endforeach
                        </select>
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#typeOperationModal"
                                onclick="Livewire.dispatch('openTypeOperationModal')">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    </div>
                    @error('type_operation_id')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Code type (read-only) + Code opération --}}
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label text-muted">Code type</label>
                        <input type="text" id="type_code_display" class="form-control bg-light" readonly
                               value="{{ old('type_operation_id') ? $typeOperations->firstWhere('id', old('type_operation_id'))?->code : '' }}">
                    </div>
                    <div class="col-md-9">
                        <label for="code" class="form-label">Code opération <span class="text-danger">*</span></label>
                        <input type="text" name="code" id="code" class="form-control @error('code') is-invalid @enderror"
                               value="{{ old('code') }}" required maxlength="50">
                        <div class="form-text">Code court affiché dans les listes de sélection</div>
                        @error('code')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                {{-- Nom (libellé long) --}}
                <div class="mb-3">
                    <label for="nom" class="form-label">Nom <span class="text-danger">*</span></label>
                    <input type="text" name="nom" id="nom" class="form-control @error('nom') is-invalid @enderror"
                           value="{{ old('nom') }}" required maxlength="150">
                    <div class="form-text">Libellé complet utilisé dans les rapports et les emails</div>
                    @error('nom')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Description --}}
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

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success">Enregistrer</button>
                    <a href="{{ request('_redirect_back', route('compta.operations.index')) }}" class="btn btn-secondary">Annuler</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Livewire pour créer un type d'opération --}}
    @livewire('type-operation-manager', ['modalOnly' => true])

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const typeSelect = document.getElementById('type_operation_id');
            const seancesInput = document.getElementById('nombre_seances');

            // Pre-fill nombre_seances when type changes
            typeSelect.addEventListener('change', function () {
                const selected = typeSelect.options[typeSelect.selectedIndex];
                const seances = selected.getAttribute('data-nombre-seances');
                const typeCode = selected.getAttribute('data-code');
                if (seances) {
                    seancesInput.value = seances;
                }
                document.getElementById('type_code_display').value = typeCode || '';
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
