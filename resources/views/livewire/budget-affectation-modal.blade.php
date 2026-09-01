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
                @error('montants')
                    <div class="alert alert-danger py-2">{{ $message }}</div>
                @enderror

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
                            <th class="text-end">Réalisé</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $typeCourant = null; @endphp
                        @foreach ($lignes as $l)
                            @if ($typeCourant !== $l['type'])
                                @if ($typeCourant !== null)
                                    {{-- Total de la section qui se termine — CHARGES ici, avant de
                                         basculer sur PRODUITS. Même structure que le total de fin de
                                         boucle plus bas (dernière section) : si tu touches l'un,
                                         touche l'autre. --}}
                                    <tr class="table-light fw-bold">
                                        <td colspan="3" class="text-end">
                                            {{ $typeCourant === 'depense' ? 'Total charges' : 'Total produits' }}
                                        </td>
                                        <td class="text-end" id="budget-affectation-total-{{ $typeCourant }}-prevu">
                                            {{ number_format($typeCourant === 'depense' ? $totaux['charges_prevu'] : $totaux['produits_prevu'], 2, ',', ' ') }} €
                                        </td>
                                        <td class="text-end">
                                            @php $totalRealiseSection = $typeCourant === 'depense' ? $totaux['charges_realise'] : $totaux['produits_realise']; @endphp
                                            {{ $totalRealiseSection === null ? '—' : number_format($totalRealiseSection, 2, ',', ' ').' €' }}
                                        </td>
                                    </tr>
                                @endif
                                @php $typeCourant = $l['type']; @endphp
                                <tr class="table-secondary">
                                    <td colspan="5" class="fw-bold">
                                        {{ $l['type'] === 'depense' ? 'CHARGES' : 'PRODUITS' }}
                                    </td>
                                </tr>
                            @endif
                            @php
                                // La BASE (enveloppe − ventilations des AUTRES opérations) reste le
                                // calcul serveur inchangé de lignes() — c'est lui qui empêche le
                                // restant de fondre à chaque réouverture de la modale. Seul
                                // L'AFFICHAGE change : la colonne montre base − montant saisi, pour
                                // que la valeur soit juste dès le premier rendu (avant tout JS) et
                                // après enregistrement — le JS ci-dessous ne fait que la recalculer
                                // en direct pendant la frappe, sans aller-retour serveur.
                                $restantNet = $l['restant'] === null ? null : round($l['restant'] - $l['montant'], 2);
                            @endphp
                            <tr>
                                <td>
                                    <span class="font-monospace">{{ $l['numero'] }}</span> — {{ $l['intitule'] }}
                                </td>
                                <td class="text-end">
                                    {{ $l['enveloppe'] === null ? '—' : number_format($l['enveloppe'], 2, ',', ' ').' €' }}
                                </td>
                                <td class="text-end">
                                    <span id="budget-affectation-restant-{{ $l['compte_id'] }}"
                                          class="{{ $restantNet !== null && $restantNet < 0 ? 'text-danger fw-semibold' : '' }}">
                                        {{ $restantNet === null ? '—' : number_format($restantNet, 2, ',', ' ').' €' }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <input type="number" step="0.01" min="0"
                                           wire:model="montants.{{ $l['compte_id'] }}"
                                           @disabled($operationId === null)
                                           data-budget-affectation-montant="{{ $l['compte_id'] }}"
                                           data-budget-affectation-type="{{ $l['type'] }}"
                                           data-restant="{{ $l['restant'] === null ? '' : $l['restant'] }}"
                                           class="form-control form-control-sm text-end">
                                </td>
                                <td class="text-end small text-muted" id="budget-affectation-realise-{{ $l['compte_id'] }}">
                                    {{-- Un fait, jamais recalculé côté client : voir le docblock de
                                         BudgetAffectationModal::totaux(). --}}
                                    {{ $l['realise'] === null ? '—' : number_format($l['realise'], 2, ',', ' ').' €' }}
                                </td>
                            </tr>
                        @endforeach
                        @if ($typeCourant !== null)
                            {{-- Total de la DERNIÈRE section (PRODUITS, normalement) — même
                                 structure que le total de transition ci-dessus. --}}
                            <tr class="table-light fw-bold">
                                <td colspan="3" class="text-end">
                                    {{ $typeCourant === 'depense' ? 'Total charges' : 'Total produits' }}
                                </td>
                                <td class="text-end" id="budget-affectation-total-{{ $typeCourant }}-prevu">
                                    {{ number_format($typeCourant === 'depense' ? $totaux['charges_prevu'] : $totaux['produits_prevu'], 2, ',', ' ') }} €
                                </td>
                                <td class="text-end">
                                    @php $totalRealiseSection = $typeCourant === 'depense' ? $totaux['charges_realise'] : $totaux['produits_realise']; @endphp
                                    {{ $totalRealiseSection === null ? '—' : number_format($totalRealiseSection, 2, ',', ' ').' €' }}
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
            <div class="modal-footer justify-content-between">
                <div class="small">
                    {{-- "Sur l'exercice", jamais "de l'opération" : une opération à cheval sur
                         deux exercices porte DEUX budgets, un par exercice, et cette modale n'en
                         montre qu'un — "résultat de l'opération" serait un contresens. --}}
                    <span class="text-muted">Sur l'exercice {{ $exerciceLabel }}</span>
                    <span class="ms-2">Prévu</span>
                    <span id="budget-affectation-resultat-prevu"
                          class="fw-bold {{ $totaux['resultat_prevu'] < 0 ? 'text-danger' : 'text-success' }}">
                        {{ $totaux['resultat_prevu'] >= 0 ? '+' : '' }}{{ number_format($totaux['resultat_prevu'], 2, ',', ' ') }} €
                    </span>
                    <span id="budget-affectation-resultat-prevu-label">
                        {{ $totaux['resultat_prevu'] < 0 ? 'Déficit' : 'Excédent' }}
                    </span>
                    <span class="ms-2 text-muted">Réalisé</span>
                    @if ($totaux['resultat_realise'] === null)
                        <span class="text-muted">—</span>
                    @else
                        <span class="fw-bold {{ $totaux['resultat_realise'] < 0 ? 'text-danger' : 'text-success' }}">
                            {{ $totaux['resultat_realise'] >= 0 ? '+' : '' }}{{ number_format($totaux['resultat_realise'], 2, ',', ' ') }} €
                        </span>
                        {{ $totaux['resultat_realise'] < 0 ? 'Déficit' : 'Excédent' }}
                    @endif
                </div>
                <div>
                    <button wire:click="fermer" type="button" class="btn btn-secondary">Annuler</button>
                    <button wire:click="enregistrer" type="button" class="btn btn-primary"
                            @disabled(! $this->canEdit || $operationId === null || $exerciceCloture)>
                        Enregistrer
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Recalcul en direct pendant la frappe, en pur JS (pas de Vite/npm dans ce
     projet) : le "Restant à ventiler" de la ligne, les totaux "prévu" par
     section, et le résultat prévisionnel. La modale n'est rendue qu'à
     l'ouverture (@if ($ouverte) plus haut) : un binding au chargement de la
     page ne toucherait donc jamais ses champs. On délègue l'écoute au
     document, indépendamment de l'existence des inputs au moment du binding —
     Livewire peut recréer les lignes (filtre, changement d'opération) sans
     que ce script ait besoin de se relier.

     Le "réalisé" (par ligne, par section, dans le résultat) n'est JAMAIS
     touché ici : c'est un fait, pas une saisie — voir le docblock de
     BudgetAffectationModal::totaux(). Seule la colonne "prévu" et ce qui en
     dérive bougent pendant la frappe ; le serveur reste seul juge à
     l'enregistrement, et calcule déjà tout correctement au premier rendu. --}}
<script>
    document.addEventListener('input', function (e) {
        var input = e.target.closest('[data-budget-affectation-montant]');
        if (!input) return;

        // 1. "Restant à ventiler" de CETTE ligne : base serveur (jamais
        //    recalculée ici) moins le montant qui vient d'être saisi.
        var compteId = input.getAttribute('data-budget-affectation-montant');
        var restantEl = document.getElementById('budget-affectation-restant-' + compteId);
        var restantRaw = input.getAttribute('data-restant');

        if (restantEl && restantRaw !== '') {
            var base = parseFloat(restantRaw);
            var montantLigne = parseFloat(String(input.value).replace(',', '.'));
            if (isNaN(montantLigne)) montantLigne = 0;

            var net = Math.round((base - montantLigne) * 100) / 100;
            restantEl.textContent = net.toFixed(2).replace('.', ',') + ' €';
            restantEl.classList.toggle('text-danger', net < 0);
            restantEl.classList.toggle('fw-semibold', net < 0);
        }

        // 2. Totaux "prévu" par section + résultat prévisionnel : somme de
        //    TOUS les champs de saisie actuellement affichés, groupés par
        //    data-budget-affectation-type. Un filtre (recherche compte) peut
        //    masquer des lignes entre deux frappes, mais celles-ci sont alors
        //    hors du DOM et légitimement exclues de la somme affichée.
        var totalCharges = 0;
        var totalProduits = 0;
        document.querySelectorAll('[data-budget-affectation-montant]').forEach(function (inp) {
            var v = parseFloat(String(inp.value).replace(',', '.'));
            if (isNaN(v)) v = 0;
            if (inp.getAttribute('data-budget-affectation-type') === 'depense') {
                totalCharges += v;
            } else {
                totalProduits += v;
            }
        });
        totalCharges = Math.round(totalCharges * 100) / 100;
        totalProduits = Math.round(totalProduits * 100) / 100;

        var chargesEl = document.getElementById('budget-affectation-total-depense-prevu');
        if (chargesEl) chargesEl.textContent = totalCharges.toFixed(2).replace('.', ',') + ' €';

        var produitsEl = document.getElementById('budget-affectation-total-recette-prevu');
        if (produitsEl) produitsEl.textContent = totalProduits.toFixed(2).replace('.', ',') + ' €';

        var resultat = Math.round((totalProduits - totalCharges) * 100) / 100;
        var resultatEl = document.getElementById('budget-affectation-resultat-prevu');
        if (resultatEl) {
            var signe = resultat >= 0 ? '+' : '';
            resultatEl.textContent = signe + resultat.toFixed(2).replace('.', ',') + ' €';
            resultatEl.classList.toggle('text-danger', resultat < 0);
            resultatEl.classList.toggle('text-success', resultat >= 0);
        }
        var labelEl = document.getElementById('budget-affectation-resultat-prevu-label');
        if (labelEl) labelEl.textContent = resultat < 0 ? 'Déficit' : 'Excédent';
    });
</script>
</div>
