<h3>1. Identité de votre association</h3>
<p class="text-muted">Ces informations apparaîtront sur vos documents (factures, attestations…).</p>

<form wire:submit="saveStep1">
    {{-- ─── Coordonnées ─── --}}
    <h5 class="mt-4 mb-3"><i class="bi bi-geo-alt me-2"></i>Coordonnées</h5>

    <div class="mb-3">
        <label class="form-label">Adresse</label>
        <input type="text" wire:model="identiteAdresse" class="form-control @error('identiteAdresse') is-invalid @enderror">
        @error('identiteAdresse') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="row">
        <div class="col-md-4 mb-3">
            <label class="form-label">Code postal</label>
            <input type="text"
                   id="identiteCodePostal"
                   wire:model="identiteCodePostal"
                   class="form-control @error('identiteCodePostal') is-invalid @enderror"
                   inputmode="numeric"
                   maxlength="5">
            @error('identiteCodePostal') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-8 mb-3">
            <label class="form-label">Ville</label>
            <input type="text"
                   id="identiteVille"
                   wire:model="identiteVille"
                   class="form-control @error('identiteVille') is-invalid @enderror">
            @error('identiteVille') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <div id="identiteVilleSuggestions" class="mt-2 d-none">
                <small class="text-muted d-block mb-1">Plusieurs communes correspondent à ce code postal :</small>
                <div class="d-flex flex-wrap gap-1" data-role="suggestions"></div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Email de contact</label>
            <input type="email" wire:model="identiteEmail" class="form-control @error('identiteEmail') is-invalid @enderror">
            @error('identiteEmail') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Téléphone</label>
            <input type="text" wire:model="identiteTelephone" class="form-control">
        </div>
    </div>

    <hr class="my-4">

    {{-- ─── Identification légale ─── --}}
    <h5 class="mt-4 mb-3"><i class="bi bi-shield-check me-2"></i>Identification légale</h5>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Forme juridique</label>
            <input type="text"
                   wire:model="identiteFormeJuridique"
                   class="form-control"
                   placeholder="Association loi 1901">
            <div class="form-text">Ex. Association loi 1901, association reconnue d'utilité publique…</div>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">SIRET (14 chiffres)</label>
            <input type="text"
                   wire:model="identiteSiret"
                   class="form-control @error('identiteSiret') is-invalid @enderror"
                   placeholder="12345678901234">
            @error('identiteSiret') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <div class="form-text">Optionnel. Requis uniquement si votre association émet des factures à des entreprises soumises à TVA.</div>
        </div>
    </div>

    <hr class="my-4">

    {{-- ─── Identité visuelle ─── --}}
    <h5 class="mt-4 mb-3"><i class="bi bi-palette me-2"></i>Identité visuelle</h5>

    {{-- Logo --}}
    <div class="row mb-4">
        <div class="col-md-6">
            <label class="form-label">Logo</label>
            <input type="file"
                   wire:model="logoUpload"
                   class="form-control @error('logoUpload') is-invalid @enderror"
                   accept="image/*">
            @error('logoUpload') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <div wire:loading wire:target="logoUpload" class="text-muted small">Téléchargement…</div>
            <div class="form-text">
                Apparaîtra sur vos factures, attestations et emails. Format PNG ou JPG, fond transparent recommandé. 2 Mo maximum.
            </div>
        </div>
        <div class="col-md-6 d-flex align-items-center justify-content-center">
            <div class="branding-preview border rounded bg-light p-3 text-center" style="min-height: 150px; width: 100%;">
                @if ($logoUpload)
                    <img src="{{ $logoUpload->temporaryUrl() }}"
                         alt="Aperçu du nouveau logo"
                         style="max-width: 100%; max-height: 150px;">
                @elseif ($this->hasLogoStored)
                    <img src="{{ route('onboarding.branding', ['kind' => 'logo']) }}?v={{ now()->timestamp }}"
                         alt="Logo actuel de l'association"
                         style="max-width: 100%; max-height: 150px;">
                @else
                    <div class="text-muted small"><i class="bi bi-image" style="font-size: 2rem;"></i><br>Aucun logo</div>
                @endif
            </div>
        </div>
    </div>

    {{-- Cachet --}}
    <div class="row mb-4">
        <div class="col-md-6">
            <label class="form-label">Cachet / signature</label>
            <input type="file"
                   wire:model="cachetUpload"
                   class="form-control @error('cachetUpload') is-invalid @enderror"
                   accept="image/*">
            @error('cachetUpload') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <div wire:loading wire:target="cachetUpload" class="text-muted small">Téléchargement…</div>
            <div class="form-text">
                Image du cachet officiel de l'association (avec signature du président si souhaité). Apparaîtra en bas des attestations fiscales et des factures.
                <strong>Conseil :</strong> scannez votre cachet sur fond blanc, puis détourez-le pour obtenir un fond transparent.
            </div>
        </div>
        <div class="col-md-6 d-flex align-items-center justify-content-center">
            <div class="branding-preview border rounded bg-light p-3 text-center" style="min-height: 150px; width: 100%;">
                @if ($cachetUpload)
                    <img src="{{ $cachetUpload->temporaryUrl() }}"
                         alt="Aperçu du nouveau cachet"
                         style="max-width: 100%; max-height: 150px;">
                @elseif ($this->hasCachetStored)
                    <img src="{{ route('onboarding.branding', ['kind' => 'cachet']) }}?v={{ now()->timestamp }}"
                         alt="Cachet actuel de l'association"
                         style="max-width: 100%; max-height: 150px;">
                @else
                    <div class="text-muted small"><i class="bi bi-file-earmark-text" style="font-size: 2rem;"></i><br>Aucun cachet</div>
                @endif
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Valider et continuer</button>
</form>

@once
    @push('scripts')
        <script src="{{ asset('js/onboarding-cp-ville.js') }}" defer></script>
    @endpush
@endonce
