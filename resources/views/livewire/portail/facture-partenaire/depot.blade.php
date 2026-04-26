<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0"><i class="bi bi-upload me-1"></i> Déposer une facture</h2>
    </div>

    @if (session('portail.success'))
        <div class="alert alert-success">{{ session('portail.success') }}</div>
    @endif

    <div class="card">
        <div class="card-body">
            <form wire:submit.prevent="submit">
                <div class="mb-3">
                    <label for="date_facture" class="form-label">
                        Date de la facture <span class="text-danger">*</span>
                    </label>
                    <input type="date"
                           id="date_facture"
                           name="date_facture"
                           class="form-control @error('date_facture') is-invalid @enderror"
                           wire:model="date_facture"
                           max="{{ now()->format('Y-m-d') }}"
                           required>
                    @error('date_facture')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="numero_facture" class="form-label">
                        Numéro de facture <span class="text-danger">*</span>
                    </label>
                    <input type="text"
                           id="numero_facture"
                           name="numero_facture"
                           class="form-control @error('numero_facture') is-invalid @enderror"
                           wire:model="numero_facture"
                           maxlength="50"
                           required>
                    @error('numero_facture')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-4">
                    <label for="pdf" class="form-label">
                        Fichier PDF <span class="text-danger">*</span>
                    </label>
                    <input type="file"
                           id="pdf"
                           name="pdf"
                           class="form-control @error('pdf') is-invalid @enderror"
                           wire:model="pdf"
                           accept="application/pdf"
                           required>
                    <div class="form-text">Format PDF uniquement, 10 Mo maximum.</div>
                    @error('pdf')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    @if ($pdf)
                        <div class="mt-1 text-success small">
                            <i class="bi bi-check-circle me-1"></i>Fichier sélectionné : {{ $pdf->getClientOriginalName() }}
                        </div>
                    @endif
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload me-1"></i>Déposer
                    </button>
                    <a href="{{ route('portail.factures.index', ['association' => $association->slug]) }}"
                       class="btn btn-outline-secondary">
                        Annuler
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="mt-3 text-end">
        <a href="{{ route('portail.home', ['association' => $association->slug]) }}"
           class="btn btn-link btn-sm text-muted">
            <i class="bi bi-arrow-left me-1"></i>Retour à l'accueil
        </a>
    </div>
</div>
