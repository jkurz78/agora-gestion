<div class="card mt-4">
    <div class="card-header py-2">
        <span class="fw-semibold"><i class="bi bi-link-45deg me-2"></i>Mapping tiers HelloAsso ↔ formules</span>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            Lie un tier HelloAsso (couple form_slug + tier_id) à une formule d'adhésion AgoraGestion.
            Le sync utilisera ce mapping pour appliquer la bonne formule lors de l'import des cotisations.
        </p>

        @if(session('success'))
            <div class="alert alert-success py-2 alert-dismissible fade show">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        {{-- Bloc import semi-automatique --}}
        <div class="border rounded p-3 mb-4 bg-light">
            <h6 class="mb-2">Importer les tiers d'un formulaire HelloAsso</h6>
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="import-type">Type</label>
                    <select id="import-type" class="form-select form-select-sm" wire:model="importFormType">
                        <option value="Membership">Membership</option>
                        <option value="Event">Event</option>
                        <option value="Donation">Donation</option>
                        <option value="PaymentForm">PaymentForm</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1" for="import-slug">Slug du formulaire</label>
                    <input id="import-slug"
                           type="text"
                           class="form-control form-control-sm @error('importFormSlug') is-invalid @enderror"
                           wire:model="importFormSlug"
                           placeholder="cotisation-2025">
                    @error('importFormSlug')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-outline-primary btn-sm w-100" wire:click="importerTiers">
                        <i class="bi bi-cloud-download me-1"></i>Importer
                    </button>
                </div>
            </div>

            @if($importError)
                <div class="alert alert-warning mt-2 mb-0 py-2 small">{{ $importError }}</div>
            @endif

            @if(! empty($importedTiers))
                <div class="mt-3">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Tier ID</th>
                                <th>Label</th>
                                <th>Prix</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($importedTiers as $tier)
                                <tr>
                                    <td>{{ $tier['id'] }}</td>
                                    <td>{{ $tier['label'] }}</td>
                                    <td>{{ $tier['price'] !== null ? number_format($tier['price'] / 100, 2, ',', ' ').' €' : '—' }}</td>
                                    <td class="text-end">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary"
                                                wire:click="preremplir({{ $tier['id'] }}, '{{ addslashes($tier['label']) }}')">
                                            Pré-remplir
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Formulaire de création manuelle --}}
        <div class="border rounded p-3 mb-4">
            <h6 class="mb-2">Nouveau mapping</h6>
            @if($errorMessage)
                <div class="alert alert-danger py-2 small">{{ $errorMessage }}</div>
            @endif
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="new-form-slug">Form slug</label>
                    <input id="new-form-slug"
                           type="text"
                           class="form-control form-control-sm @error('newFormSlug') is-invalid @enderror"
                           wire:model="newFormSlug">
                    @error('newFormSlug')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="new-tier-id">Tier ID</label>
                    <input id="new-tier-id"
                           type="number"
                           class="form-control form-control-sm @error('newTierId') is-invalid @enderror"
                           wire:model="newTierId">
                    @error('newTierId')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="new-tier-label">Tier label</label>
                    <input id="new-tier-label"
                           type="text"
                           class="form-control form-control-sm @error('newTierLabel') is-invalid @enderror"
                           wire:model="newTierLabel">
                    @error('newTierLabel')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="new-formule">Formule cible</label>
                    <select id="new-formule"
                            class="form-select form-select-sm @error('newFormuleId') is-invalid @enderror"
                            wire:model="newFormuleId">
                        <option value="">— Choisir —</option>
                        @foreach($formules as $formule)
                            <option value="{{ $formule->id }}">{{ $formule->nom }}</option>
                        @endforeach
                    </select>
                    @error('newFormuleId')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="button" class="btn btn-primary btn-sm w-100" wire:click="create">Ajouter</button>
                </div>
            </div>
        </div>

        {{-- Liste des mappings existants --}}
        <div class="table-responsive">
            <table class="table table-sm table-striped">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Form slug</th>
                        <th>Tier ID</th>
                        <th>Tier label</th>
                        <th>Formule cible</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($mappings as $mapping)
                        <tr>
                            <td class="small">{{ $mapping->helloasso_form_slug }}</td>
                            <td class="small">{{ $mapping->helloasso_tier_id }}</td>
                            <td class="small">{{ $mapping->helloasso_tier_label }}</td>
                            <td class="small">{{ $mapping->target?->nom ?? '—' }}</td>
                            <td class="text-end">
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        wire:click="delete({{ $mapping->id }})"
                                        wire:confirm="Supprimer ce mapping ?">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted small py-3">
                                Aucun mapping. Importez les tiers d'un formulaire ou créez-en un manuellement.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
