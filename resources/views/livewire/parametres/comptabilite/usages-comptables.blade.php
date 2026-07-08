<div class="container py-4">
    <h1 class="h3 mb-4">Comptabilité</h1>
    <p class="text-muted mb-4">Configure les comptes utilisés pour chaque cas d'usage comptable.</p>

    {{-- Card : Frais kilométriques --}}
    <div class="card mb-3">
        <div class="card-header text-white" style="background:#3d5473;">
            <strong>Comptabilisation des indemnités kilométriques</strong>
            <small class="ms-2">Le compte utilisé quand un tiers déclare des kilomètres en note de frais.</small>
        </div>
        <div class="card-body">
            <div class="d-flex gap-2 align-items-start">
                <select class="form-select" style="max-width:480px" wire:model.live="fraisKmSelectedId" wire:change="saveFraisKilometriques">
                    <option value="">— Aucun —</option>
                    @foreach($comptesDepense as $compte)
                        <option value="{{ $compte->id }}">{{ $compte->numero_pcg }} — {{ $compte->intitule }}</option>
                    @endforeach
                </select>
                <button type="button" class="btn btn-outline-secondary" wire:click="openInline('{{ \App\Enums\UsageComptable::FraisKilometriques->value }}')">
                    + Créer un compte
                </button>
            </div>
        </div>
    </div>

    {{-- Card : Cotisations --}}
    <div class="card mb-3">
        <div class="card-header text-white" style="background:#3d5473;">
            <strong>Comptabilisation des adhésions</strong>
            <small class="ms-2">Comptes utilisés pour les cotisations des membres.</small>
        </div>
        <div class="card-body">
            @foreach($comptesRecette as $compte)
                @php $checked = $comptesCotisation->contains($compte->id); @endphp
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="coti_{{ $compte->id }}"
                        @checked($checked)
                        wire:click="toggleCotisation({{ $compte->id }}, {{ $checked ? 'false' : 'true' }})">
                    <label class="form-check-label" for="coti_{{ $compte->id }}">{{ $compte->numero_pcg }} — {{ $compte->intitule }}</label>
                </div>
            @endforeach
            <button type="button" class="btn btn-outline-secondary mt-2" wire:click="openInline('{{ \App\Enums\UsageComptable::Cotisation->value }}')">
                + Créer un compte
            </button>
        </div>
    </div>

    {{-- Card : Inscriptions --}}
    <div class="card mb-3">
        <div class="card-header text-white" style="background:#3d5473;">
            <strong>Comptabilisation des participations aux opérations</strong>
            <small class="ms-2">Comptes utilisés pour les règlements des opérations.</small>
        </div>
        <div class="card-body">
            @foreach($comptesRecette as $compte)
                @php $checked = $comptesInscription->contains($compte->id); @endphp
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="inscr_{{ $compte->id }}"
                        @checked($checked)
                        wire:click="toggleInscription({{ $compte->id }}, {{ $checked ? 'false' : 'true' }})">
                    <label class="form-check-label" for="inscr_{{ $compte->id }}">{{ $compte->numero_pcg }} — {{ $compte->intitule }}</label>
                </div>
            @endforeach
            <button type="button" class="btn btn-outline-secondary mt-2" wire:click="openInline('{{ \App\Enums\UsageComptable::Inscription->value }}')">
                + Créer un compte
            </button>
        </div>
    </div>

    {{-- Card : Dons + sub-mono AbandonCreance --}}
    <div class="card mb-3">
        <div class="card-header text-white" style="background:#3d5473;">
            <strong>Comptabilisation des Dons</strong>
            <small class="ms-2">Comptes utilisés pour les dons.</small>
        </div>
        <div class="card-body">
            @foreach($comptesRecette as $compte)
                @php $checked = $comptesDon->contains($compte->id); @endphp
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="don_{{ $compte->id }}"
                        @checked($checked)
                        wire:click="toggleDon({{ $compte->id }}, {{ $checked ? 'false' : 'true' }})">
                    <label class="form-check-label" for="don_{{ $compte->id }}">{{ $compte->numero_pcg }} — {{ $compte->intitule }}</label>
                </div>
            @endforeach
            <button type="button" class="btn btn-outline-secondary mt-2" wire:click="openInline('{{ \App\Enums\UsageComptable::Don->value }}')">
                + Créer un compte
            </button>

            @if(count($this->abandonCreanceCandidates) > 0)
                <hr class="my-3">
                <label class="form-label"><strong>Abandon de créance</strong> <small class="text-muted">(compte désigné pour le renoncement au règlement de notes de frais)</small></label>
                <div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="abandonCreance" value="" id="abandon_none"
                            wire:model.live="abandonCreanceSelectedId" wire:change="saveAbandonCreance">
                        <label class="form-check-label" for="abandon_none">— Aucun —</label>
                    </div>
                    @foreach($this->abandonCreanceCandidates as $cand)
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="abandonCreance" value="{{ $cand->id }}" id="abandon_{{ $cand->id }}"
                                wire:model.live="abandonCreanceSelectedId" wire:change="saveAbandonCreance">
                            <label class="form-check-label" for="abandon_{{ $cand->id }}">{{ $cand->numero_pcg }} — {{ $cand->intitule }}</label>
                        </div>
                    @endforeach
                </div>
                @error('abandonCreance') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
            @endif
        </div>
    </div>

    {{-- Modale création inline --}}
    @if($inlineOpen)
        <div class="modal d-block" style="background:rgba(0,0,0,.5)" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Créer un compte</h5>
                        <button type="button" class="btn-close" wire:click="$set('inlineOpen', false)"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Catégorie</label>
                            <select class="form-select" wire:model="inlineCategorieId">
                                <option value="">Sélectionner…</option>
                                @foreach($this->inlineCategoriesEligibles as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->nom }}</option>
                                @endforeach
                            </select>
                            @error('inlineCategorieId') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Intitulé</label>
                            <input type="text" class="form-control" wire:model="inlineNom">
                            @error('inlineNom') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Numéro de compte <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" wire:model="inlineCodeCerfa" placeholder="ex : 706A">
                            @error('inlineCodeCerfa') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="$set('inlineOpen', false)">Annuler</button>
                        <button type="button" class="btn btn-primary" wire:click="submitInline">Créer</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
