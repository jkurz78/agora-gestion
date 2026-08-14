<div>
    {{-- Flash message --}}
    @if($flashMessage)
        <div class="alert alert-{{ $flashType }} alert-dismissible fade show">
            {{ $flashMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" wire:click="$set('flashMessage', '')"></button>
        </div>
    @endif

    {{-- Toolbar --}}
    <div class="mb-3 d-flex flex-wrap gap-2 align-items-center justify-content-end">
        <button class="btn btn-primary btn-sm" wire:click="openCreate">
            <i class="bi bi-plus-lg"></i> Ajouter un compte
        </button>
    </div>

    {{-- Table --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover" id="pcTable">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th style="width:120px">Numéro</th>
                    <th>Intitulé</th>
                    <th style="width:80px" class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($groupes as $code => $comptes)
                    @php $famille = $familles->get($code); @endphp
                    {{-- En-tête de famille : « 70 — Ventes et prestations », nom éditable inline --}}
                    <tr wire:key="fam-{{ $code }}" class="table-light">
                        <td colspan="3" class="fw-bold">
                            <span class="font-monospace">{{ $code }}</span> —
                            <span wire:ignore.self
                                  x-data="{ editing: false, value: @js($famille?->nom ?? $code), original: @js($famille?->nom ?? $code) }"
                                  @click="if (!editing) { editing = true; $nextTick(() => $refs.input.focus()) }"
                                  style="cursor:pointer" title="Cliquer pour renommer la famille">
                                <template x-if="!editing">
                                    {{-- Texte serveur = fallback avant hydratation Alpine (et testable) --}}
                                    <span x-text="value">{{ $famille?->nom ?? $code }}</span>
                                </template>
                                <template x-if="editing">
                                    <input x-ref="input" type="text" x-model="value"
                                           class="form-control form-control-sm d-inline-block" style="width:320px;max-width:60vw"
                                           maxlength="255"
                                           @keydown.enter="if (value.trim()) { $wire.updateFamilleNom(@js($code), value); editing = false; original = value } else { value = original; editing = false }"
                                           @keydown.escape="value = original; editing = false"
                                           @blur="if (value.trim() && value !== original) { $wire.updateFamilleNom(@js($code), value); original = value }; editing = false"
                                           @click.stop>
                                </template>
                            </span>
                        </td>
                    </tr>

                    @foreach ($comptes as $compte)
                        <tr wire:key="compte-{{ $compte->id }}">
                            {{-- Numéro (identité : éditable tant qu'aucune écriture — D3) --}}
                            @if ($compte->est_systeme || $compte->lignes_count > 0)
                                <td class="font-monospace">{{ $compte->numero_pcg }}</td>
                            @else
                                <td class="font-monospace" wire:ignore.self
                                    x-data="{ editing: false, value: @js($compte->numero_pcg), original: @js($compte->numero_pcg) }"
                                    @click="if (!editing) { editing = true; $nextTick(() => $refs.input.focus()) }"
                                    style="cursor:pointer"
                                    title="Renuméroter (possible tant que le compte ne porte aucune écriture)">
                                    <template x-if="!editing">
                                        <span x-text="value">{{ $compte->numero_pcg }}</span>
                                    </template>
                                    <template x-if="editing">
                                        <input x-ref="input" type="text" x-model="value"
                                               class="form-control form-control-sm font-monospace" style="max-width:120px"
                                               maxlength="6"
                                               @keydown.enter="if (value.trim()) { $wire.updateField({{ $compte->id }}, 'numero_pcg', value.trim().toUpperCase()).then(ok => { if (ok) { original = value } else { value = original } }); editing = false } else { value = original; editing = false }"
                                               @keydown.escape="value = original; editing = false"
                                               @blur="if (value.trim() && value !== original) { $wire.updateField({{ $compte->id }}, 'numero_pcg', value.trim().toUpperCase()).then(ok => { if (ok) { original = value } else { value = original } }) }; editing = false"
                                               @click.stop>
                                    </template>
                                </td>
                            @endif

                            {{-- Intitulé --}}
                            @if ($compte->est_systeme)
                                <td>
                                    {{ $compte->intitule }}
                                    <span class="badge bg-secondary ms-2">système</span>
                                </td>
                                <td></td>
                            @else
                                <td wire:ignore.self
                                    x-data="{ editing: false, value: @js($compte->intitule), original: @js($compte->intitule) }"
                                    @click="if (!editing) { editing = true; $nextTick(() => $refs.input.focus()) }"
                                    style="cursor:pointer">
                                    <template x-if="!editing">
                                        {{-- Texte serveur = fallback avant hydratation Alpine (et testable) --}}
                                        <span x-text="value">{{ $compte->intitule }}</span>
                                    </template>
                                    <template x-if="editing">
                                        <input x-ref="input" type="text" x-model="value"
                                               class="form-control form-control-sm"
                                               maxlength="255"
                                               @keydown.enter="if (value.trim()) { $wire.updateField({{ $compte->id }}, 'intitule', value).then(ok => { if (ok) { original = value } else { value = original } }); editing = false } else { value = original; editing = false }"
                                               @keydown.escape="value = original; editing = false"
                                               @blur="if (value.trim() && value !== original) { $wire.updateField({{ $compte->id }}, 'intitule', value).then(ok => { if (ok) { original = value } else { value = original } }) }; editing = false"
                                               @click.stop>
                                    </template>
                                </td>

                                {{-- Actions --}}
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-danger"
                                            wire:click="delete({{ $compte->id }})"
                                            wire:confirm="Supprimer ce compte ?"
                                            style="padding:.15rem .35rem;font-size:.75rem"
                                            title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                @empty
                    <tr>
                        <td colspan="3" class="text-muted">Aucun compte enregistré.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ═══════════════════════════════════════════════════════════
         MODAL CREATE
         ═══════════════════════════════════════════════════════════ --}}
    @if($showModal)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.4);z-index:2000"
             wire:click.self="$set('showModal', false)">
            <div class="bg-white rounded p-4 shadow" style="width:500px;max-width:95vw;max-height:90vh;overflow-y:auto">
                <h5 class="fw-bold mb-3">Ajouter un compte</h5>

                {{-- Numéro EN PREMIER, puis intitulé --}}
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Numéro <span class="text-danger">*</span></label>
                        <input type="text" wire:model="numero_pcg"
                               class="form-control form-control-sm font-monospace @error('numero_pcg') is-invalid @enderror"
                               maxlength="6" placeholder="706">
                        @error('numero_pcg') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small">Intitulé <span class="text-danger">*</span></label>
                        <input type="text" wire:model="intitule"
                               class="form-control form-control-sm @error('intitule') is-invalid @enderror"
                               maxlength="255">
                        @error('intitule') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <p class="small text-muted mb-0">
                    Le numéro détermine la famille du compte (deux premiers caractères)
                    et son sens : 2 pour une immobilisation ou son amortissement, 6 pour une dépense, 7 pour une recette.
                </p>

                {{-- Note de renvoi vers l'écran Usages --}}
                <p class="small text-muted mt-2 mb-0">
                    Les usages comptables (Dons, Cotisations, Inscriptions, Frais km, Abandon de créance) se configurent dans
                    <a href="{{ route('parametres.comptabilite.usages') }}">Paramètres → Comptabilité → Usages</a>.
                </p>

                {{-- Actions --}}
                <div class="d-flex gap-2 justify-content-end mt-4">
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showModal', false)">Annuler</button>
                    <button type="button" class="btn btn-sm btn-primary" wire:click="save">
                        <i class="bi bi-check-lg"></i> Enregistrer
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
