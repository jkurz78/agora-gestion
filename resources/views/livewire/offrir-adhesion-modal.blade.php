<div x-on:keydown.escape.window="$wire.close()">
    @if($visible)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="z-index:2055">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-gift me-2"></i>Offrir une adhésion
                        </h5>
                        <button type="button" class="btn-close" wire:click="close"></button>
                    </div>
                    <div class="modal-body">
                        @if(session()->has('error'))
                            <div class="alert alert-danger alert-dismissible fade show py-2 mb-3" role="alert">
                                {{ session('error') }}
                                <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
                            </div>
                        @endif

                        {{-- Tiers --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Adhérent</label>
                            <livewire:tiers-autocomplete
                                wire:model="tiersId"
                                :key="'offrir-adhesion-tiers-'.($visible ? '1' : '0')"
                                context="adhesion" />
                            @error('tiersId')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Exercice --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="offrir-exercice">Exercice</label>
                            <select id="offrir-exercice"
                                    class="form-select form-select-sm @error('exercice') is-invalid @enderror"
                                    wire:model="exercice">
                                <option value="">— Choisir un exercice —</option>
                                @foreach($availableYears as $year)
                                    <option value="{{ $year }}">{{ $year }}-{{ $year + 1 }}</option>
                                @endforeach
                            </select>
                            @error('exercice')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Motif (avec auto-suggestion sur les motifs déjà saisis) --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="offrir-motif">Motif</label>
                            <input id="offrir-motif"
                                   type="text"
                                   list="offrir-motif-suggestions"
                                   class="form-control form-control-sm @error('motif') is-invalid @enderror"
                                   wire:model="motif"
                                   maxlength="255"
                                   autocomplete="off"
                                   placeholder="Ex : Membre d'honneur, bénévole…">
                            <datalist id="offrir-motif-suggestions">
                                @foreach($motifsSuggestions as $suggestion)
                                    <option value="{{ $suggestion }}"></option>
                                @endforeach
                            </datalist>
                            @error('motif')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" wire:click="close">
                            Annuler
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" wire:click="submit">
                            <i class="bi bi-gift me-1"></i>Offrir l'adhésion
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show" style="z-index:2054"></div>
    @endif
</div>
