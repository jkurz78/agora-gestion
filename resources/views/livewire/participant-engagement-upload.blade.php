<div>
    @if($this->canEdit)
        <div class="p-3 border rounded bg-light">
            <label class="form-label small fw-semibold mb-2">
                <i class="bi bi-file-earmark-pdf me-1"></i>Joindre un document (scan formulaire, justificatif…)
            </label>
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Libellé</label>
                    <input type="text" wire:model="label" class="form-control form-control-sm" placeholder="Ex: Formulaire papier">
                </div>
                <div class="col-md-5">
                    <label class="form-label small">Fichier</label>
                    <input type="file" wire:model="scanFormulaire"
                           accept=".pdf,.jpg,.jpeg,.png"
                           class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    @if($scanFormulaire)
                        <button wire:click="enregistrer" class="btn btn-sm btn-primary w-100" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="enregistrer"><i class="bi bi-upload me-1"></i>Envoyer</span>
                            <span wire:loading wire:target="enregistrer"><i class="bi bi-hourglass-split"></i></span>
                        </button>
                    @endif
                </div>
            </div>
            <div wire:loading wire:target="scanFormulaire" class="small text-muted mt-1">
                Chargement…
            </div>
            @error('scanFormulaire')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
            @error('label')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
        </div>
    @endif
</div>
