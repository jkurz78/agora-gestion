<div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
     style="background:rgba(0,0,0,.4);z-index:2000" wire:click.self="closeModal">
    <div class="bg-white rounded p-4 shadow" style="width:600px;max-width:95vw;max-height:90vh;overflow-y:auto">
        <h5 class="fw-bold mb-3">{{ $title }}</h5>

        <div class="mb-3">
            <label class="form-label small fw-semibold">Type d'operation <span class="text-danger">*</span></label>
            <select class="form-select form-select-sm @error('formTypeOperationId') is-invalid @enderror"
                    wire:model="formTypeOperationId">
                <option value="">-- Choisir --</option>
                @foreach($typeOperations as $type)
                    <option value="{{ $type->id }}">{{ $type->nom }}</option>
                @endforeach
            </select>
            @error('formTypeOperationId') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <label class="form-label small fw-semibold">Nom <span class="text-danger">*</span></label>
            <input type="text" class="form-control form-control-sm @error('formNom') is-invalid @enderror"
                   wire:model="formNom" maxlength="150">
            @error('formNom') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <label class="form-label small fw-semibold">Description</label>
            <textarea class="form-control form-control-sm @error('formDescription') is-invalid @enderror"
                      wire:model="formDescription" rows="3"></textarea>
            @error('formDescription') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="row mb-3">
            <div class="col-6">
                <label class="form-label small fw-semibold">Date debut <span class="text-danger">*</span></label>
                <input type="date" class="form-control form-control-sm @error('formDateDebut') is-invalid @enderror"
                       wire:model="formDateDebut">
                @error('formDateDebut') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-6">
                <label class="form-label small fw-semibold">Date fin <span class="text-danger">*</span></label>
                <input type="date" class="form-control form-control-sm @error('formDateFin') is-invalid @enderror"
                       wire:model="formDateFin">
                @error('formDateFin') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-semibold">Nombre de seances</label>
            <input type="number" class="form-control form-control-sm @error('formNombreSeances') is-invalid @enderror"
                   wire:model="formNombreSeances" min="1">
            @error('formNombreSeances') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        @if($showEditModal)
            <div class="mb-3">
                <a href="{{ route('operations.types-operation.index') }}" class="small text-muted">
                    <i class="bi bi-gear me-1"></i> Reglages avances du type d'operation
                </a>
            </div>
        @endif

        <div class="d-flex justify-content-end gap-2">
            <button class="btn btn-sm btn-outline-secondary" wire:click="closeModal">Annuler</button>
            <button class="btn btn-sm text-white" style="background-color:#A9014F"
                    wire:click="saveOperation">
                <span wire:loading wire:target="saveOperation" class="spinner-border spinner-border-sm me-1"></span>
                {{ $showEditModal ? 'Enregistrer' : 'Creer' }}
            </button>
        </div>
    </div>
</div>
