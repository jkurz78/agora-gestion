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
                        @if ($operationId === null)
                            <div class="form-text">Choisissez d'abord une opération.</div>
                        @endif
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
                                           @disabled($operationId === null)
                                           data-budget-affectation-montant="{{ $l['compte_id'] }}"
                                           data-restant="{{ $l['restant'] === null ? '' : $l['restant'] }}"
                                           class="form-control form-control-sm text-end">
                                    {{-- Affiché par le JS ci-dessous pendant la frappe, et déjà présent au
                                         premier rendu pour ne pas dépendre d'un premier événement input. Le
                                         serveur reste seul juge à l'enregistrement : ceci n'est qu'un confort
                                         d'affichage. --}}
                                    <div id="budget-affectation-depassement-{{ $l['compte_id'] }}"
                                         class="text-danger small mt-1 {{ $l['depassement'] > 0 ? '' : 'd-none' }}">
                                        dépasse de {{ number_format($l['depassement'], 2, ',', ' ') }} €
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button wire:click="fermer" type="button" class="btn btn-secondary">Annuler</button>
                <button wire:click="enregistrer" type="button" class="btn btn-primary"
                        @disabled(! $this->canEdit || $operationId === null || $exerciceCloture)>
                    Enregistrer
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Recalcul du dépassement pendant la frappe, en pur JS (pas de Vite/npm dans
     ce projet). La modale n'est rendue qu'à l'ouverture (@if ($ouverte) plus
     haut) : un binding au chargement de la page ne toucherait donc jamais ses
     champs. On délègue l'écoute au document, indépendamment de l'existence des
     inputs au moment du binding — Livewire peut recréer les lignes (filtre,
     changement d'opération) sans que ce script ait besoin de se relier. --}}
<script>
    document.addEventListener('input', function (e) {
        var input = e.target.closest('[data-budget-affectation-montant]');
        if (!input) return;

        var compteId = input.getAttribute('data-budget-affectation-montant');
        var depassementEl = document.getElementById('budget-affectation-depassement-' + compteId);
        if (!depassementEl) return;

        var restantRaw = input.getAttribute('data-restant');

        // Pas d'enveloppe pour ce compte : jamais de dépassement, même si un
        // montant très élevé est saisi (cf. calcul serveur dans lignes()).
        if (restantRaw === '') {
            depassementEl.classList.add('d-none');
            return;
        }

        var restant = parseFloat(restantRaw);
        var montant = parseFloat(String(input.value).replace(',', '.'));

        if (isNaN(montant) || montant <= restant) {
            depassementEl.classList.add('d-none');
            return;
        }

        var depassement = Math.round((montant - restant) * 100) / 100;
        depassementEl.textContent = 'dépasse de ' + depassement.toFixed(2).replace('.', ',') + ' €';
        depassementEl.classList.remove('d-none');
    });
</script>
</div>
