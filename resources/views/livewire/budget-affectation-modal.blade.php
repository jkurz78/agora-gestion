<div>
@if ($ouverte)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Affecter un budget à une opération</h5>
                <button wire:click="fermer" type="button" class="btn-close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-7">
                        <label class="form-label fw-semibold">Opération</label>
                        <select wire:model.live="operationId" class="form-select">
                            <option value="">— choisir —</option>
                            @foreach ($operations as $op)
                                <option value="{{ $op->id }}">{{ $op->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Filtrer</label>
                        <input wire:model.live.debounce.300ms="filtre" type="text"
                               class="form-control" placeholder="Numéro ou intitulé">
                    </div>
                </div>

                <table class="table table-sm table-hover">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Compte</th>
                            <th class="text-end">Enveloppe</th>
                            <th class="text-end">Restant à ventiler</th>
                            <th class="text-end" style="width:180px;">Budget de l'opération</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $typeCourant = null; @endphp
                        @foreach ($lignes as $l)
                            @if ($typeCourant !== $l['type'])
                                @php $typeCourant = $l['type']; @endphp
                                <tr class="table-secondary">
                                    <td colspan="4" class="fw-bold">
                                        {{ $l['type'] === 'depense' ? 'CHARGES' : 'PRODUITS' }}
                                    </td>
                                </tr>
                            @endif
                            <tr>
                                <td>
                                    <span class="font-monospace">{{ $l['numero'] }}</span> — {{ $l['intitule'] }}
                                </td>
                                <td class="text-end">
                                    {{ $l['enveloppe'] === null ? '—' : number_format($l['enveloppe'], 2, ',', ' ').' €' }}
                                </td>
                                <td class="text-end">
                                    {{ $l['restant'] === null ? '—' : number_format($l['restant'], 2, ',', ' ').' €' }}
                                </td>
                                <td class="text-end">
                                    <input type="number" step="0.01" min="0"
                                           wire:model="montants.{{ $l['compte_id'] }}"
                                           class="form-control form-control-sm text-end">
                                    @if ($l['depassement'] > 0)
                                        <div class="text-danger small mt-1">
                                            dépasse de {{ number_format($l['depassement'], 2, ',', ' ') }} €
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button wire:click="fermer" type="button" class="btn btn-secondary">Annuler</button>
                <button wire:click="enregistrer" type="button" class="btn btn-primary"
                        @disabled(! $this->canEdit || $operationId === null)>
                    Enregistrer
                </button>
            </div>
        </div>
    </div>
</div>
@endif
</div>
