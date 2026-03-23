<div>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-gear me-1"></i> Configuration de la synchronisation</h5>
        </div>
        <div class="card-body">
            @if($erreur)
                <div class="alert alert-danger">{{ $erreur }}</div>
            @endif
            @if($message)
                <div class="alert alert-success">{{ $message }}</div>
            @endif

            {{-- Comptes --}}
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Compte HelloAsso (réception)</label>
                    <select wire:model="compteHelloassoId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($comptes as $c)
                            <option value="{{ $c->id }}">{{ $c->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Compte de versement (destination)</label>
                    <select wire:model="compteVersementId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($comptes as $c)
                            <option value="{{ $c->id }}">{{ $c->nom }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Mapping sous-catégories --}}
            <h6 class="mt-3">Mapping des sous-catégories</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small">Dons (Donation)</label>
                    <select wire:model="sousCategorieDonId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($sousCategoriesDon as $sc)
                            <option value="{{ $sc->id }}">{{ $sc->nom }} ({{ $sc->code_cerfa }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Cotisations (Membership)</label>
                    <select wire:model="sousCategorieCotisationId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($sousCategoriesCotisation as $sc)
                            <option value="{{ $sc->id }}">{{ $sc->nom }} ({{ $sc->code_cerfa }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Inscriptions (Registration)</label>
                    <select wire:model="sousCategorieInscriptionId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($sousCategoriesInscription as $sc)
                            <option value="{{ $sc->id }}">{{ $sc->nom }} ({{ $sc->code_cerfa }})</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <button wire:click="sauvegarder" class="btn btn-sm btn-primary">
                <i class="bi bi-check-lg me-1"></i> Enregistrer la configuration
            </button>
        </div>
    </div>

    {{-- Mapping formulaires → opérations --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-diagram-3 me-1"></i> Mapping des formulaires → opérations</h5>
        </div>
        <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-3">
                <button wire:click="chargerFormulaires" class="btn btn-sm btn-primary" wire:loading.attr="disabled">
                    <span wire:loading wire:target="chargerFormulaires" class="spinner-border spinner-border-sm me-1"></span>
                    <i class="bi bi-cloud-download me-1" wire:loading.remove wire:target="chargerFormulaires"></i>
                    Charger les formulaires depuis HelloAsso
                </button>
            </div>

            @if($formMappings->isNotEmpty())
                <table class="table table-sm">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Formulaire</th>
                            <th>Type</th>
                            <th>Période</th>
                            <th>Statut</th>
                            <th>Opération SVS</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($formMappings as $fm)
                            <tr wire:key="fm-{{ $fm->id }}">
                                <td class="small">{{ $fm->form_title ?? $fm->form_slug }}<br><code class="text-muted">{{ $fm->form_slug }}</code></td>
                                <td class="small"><span class="badge text-bg-secondary">{{ $fm->form_type }}</span></td>
                                <td class="small text-nowrap">
                                    @if($fm->start_date || $fm->end_date)
                                        {{ $fm->start_date?->format('d/m/Y') ?? '—' }}
                                        → {{ $fm->end_date?->format('d/m/Y') ?? '…' }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="small">
                                    @if($fm->state)
                                        @php
                                            $badgeClass = match($fm->state) {
                                                'Public' => 'text-bg-success',
                                                'Draft' => 'text-bg-warning',
                                                'Private' => 'text-bg-info',
                                                'Disabled' => 'text-bg-danger',
                                                default => 'text-bg-secondary',
                                            };
                                        @endphp
                                        <span class="badge {{ $badgeClass }}">{{ $fm->state }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <select wire:model="formOperations.{{ $fm->id }}" class="form-select form-select-sm">
                                        <option value="">Ne pas suivre ce formulaire comme une opération</option>
                                        @foreach($operations as $op)
                                            <option value="{{ $op->id }}">{{ $op->nom }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <button wire:click="sauvegarderFormulaires" class="btn btn-sm btn-primary">
                    <i class="bi bi-check-lg me-1"></i> Enregistrer le mapping
                </button>
            @else
                <p class="text-muted small">Aucun formulaire chargé. Cliquez sur le bouton ci-dessus.</p>
            @endif
        </div>
    </div>
</div>
