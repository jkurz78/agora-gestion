{{-- resources/views/livewire/parametres/association-form.blade.php --}}
<div>
    @if (session('success'))
        <div class="alert alert-success alert-dismissible mb-4">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card" style="max-width: 640px;">
        <div class="card-body">

            @if ($logoUrl)
                <div class="mb-3">
                    <label class="form-label text-muted small">Logo actuel</label><br>
                    <img src="{{ $logoUrl }}" alt="Logo association" style="max-height: 80px; border: 1px solid #dee2e6; border-radius: 4px; padding: 4px;">
                </div>
            @endif

            <div class="mb-3">
                <label class="form-label">Nom de l'association <span class="text-danger">*</span></label>
                <input type="text" class="form-control @error('nom') is-invalid @enderror"
                       wire:model="nom">
                @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Adresse</label>
                <input type="text" class="form-control" wire:model="adresse">
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Code postal</label>
                    <input type="text" class="form-control" wire:model="code_postal">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Ville</label>
                    <input type="text" class="form-control" wire:model="ville">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" class="form-control @error('email') is-invalid @enderror"
                       wire:model="email">
                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Téléphone</label>
                <input type="text" class="form-control" wire:model="telephone">
            </div>

            <div class="mb-4">
                <label class="form-label">Logo (PNG ou JPG, max 2 Mo)</label>
                <input type="file" class="form-control @error('logo') is-invalid @enderror"
                       wire:model="logo" accept=".png,.jpg,.jpeg">
                @error('logo') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">Cachet et signature du président</label>
                @if($cachetUrl)
                    <div class="mb-2">
                        <img src="{{ $cachetUrl }}" alt="Cachet" style="max-height:100px" class="border rounded p-1">
                    </div>
                @endif
                <input type="file" wire:model="cachet" class="form-control form-control-sm" accept="image/png,image/jpeg">
                @error('cachet') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
            </div>

            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                <span wire:loading.remove><i class="bi bi-floppy"></i> Enregistrer</span>
                <span wire:loading>Enregistrement…</span>
            </button>

        </div>
    </div>
</div>
