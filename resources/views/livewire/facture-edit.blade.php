<div>
    {{-- En-tete contextuel --}}
    <div class="mb-3">
        <p class="text-muted mb-0 small">
            Tiers : <strong>{{ $facture->tiers->displayName() }}</strong>
            — Exercice {{ $facture->exercice }}/{{ $facture->exercice + 1 }}
            @if ($facture->devis_id !== null && $facture->devis !== null)
                — <span class="text-info">
                    <i class="bi bi-link-45deg"></i>
                    Issue du devis
                    <a href="{{ route('devis-manuels.show', $facture->devis) }}"
                       class="text-info">{{ $facture->devis->numero ?? '#' . $facture->devis_id }}</a>
                </span>
            @endif
        </p>
    </div>

    @if (session()->has('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if (session()->has('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            {{-- Panel 1 : Transactions disponibles --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-list-check"></i> Transactions disponibles</h5>
                </div>
                <div class="card-body p-0">
                    @if ($transactions->isEmpty())
                        <div class="p-3">
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-info-circle"></i> Aucune transaction disponible pour ce tiers.
                            </div>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-striped table-hover align-middle mb-0">
                                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                    <tr>
                                        <th style="width: 40px;"></th>
                                        <th>Date</th>
                                        <th>Reference</th>
                                        <th>Libelle</th>
                                        <th class="text-end">Montant</th>
                                    </tr>
                                </thead>
                                <tbody style="color:#555">
                                    @foreach ($transactions as $transaction)
                                        <tr wire:key="tx-{{ $transaction->id }}"
                                            class="{{ in_array($transaction->id, $selectedIds) ? 'table-success' : '' }}"
                                            style="cursor: pointer;"
                                            wire:click="toggleTransaction({{ $transaction->id }})">
                                            <td>
                                                <input type="checkbox"
                                                       class="form-check-input"
                                                       @checked(in_array($transaction->id, $selectedIds))
                                                       wire:click.stop="toggleTransaction({{ $transaction->id }})">
                                            </td>
                                            <td class="small">{{ $transaction->date->format('d/m/Y') }}</td>
                                            <td class="small">{{ $transaction->reference }}</td>
                                            <td class="small">{{ $transaction->libelle }}</td>
                                            <td class="text-end small fw-semibold text-nowrap">{{ number_format((float) $transaction->montant_total, 2, ',', ' ') }} &euro;</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td colspan="4" class="text-end">Total transactions selectionnees</td>
                                        <td class="text-end text-nowrap">
                                            {{ number_format((float) $transactions->whereIn('id', $selectedIds)->sum('montant_total'), 2, ',', ' ') }} &euro;
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Panel 2 : Lignes de facture --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-receipt"></i> Lignes de facture</h5>
                </div>
                <div class="card-body p-0">
                    @if ($lignes->isEmpty())
                        <div class="p-3">
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-info-circle"></i> Aucune ligne. Selectionnez des transactions ci-dessus.
                            </div>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-striped align-middle mb-0">
                                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                    <tr>
                                        <th>Libelle</th>
                                        <th class="text-end" style="width: 120px;">Montant</th>
                                        <th style="width: 120px;" class="text-center">Ordre</th>
                                        <th style="width: 50px;"></th>
                                    </tr>
                                </thead>
                                <tbody style="color:#555">
                                    @foreach ($lignes as $ligne)
                                        <tr wire:key="ligne-{{ $ligne->id }}">
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    @if ($ligne->type === \App\Enums\TypeLigneFacture::MontantManuel)
                                                        <span class="badge bg-secondary" title="Ligne manuelle">M</span>
                                                    @elseif ($ligne->type === \App\Enums\TypeLigneFacture::Texte)
                                                        <span class="badge bg-light text-dark border" title="Ligne texte">T</span>
                                                    @endif
                                                    <input type="text"
                                                           class="form-control form-control-sm"
                                                           value="{{ $ligne->libelle }}"
                                                           wire:blur="updateLibelle({{ $ligne->id }}, $event.target.value)">
                                                </div>
                                                @if ($ligne->type === \App\Enums\TypeLigneFacture::MontantManuel && $ligne->prix_unitaire !== null)
                                                    <div class="d-flex align-items-center gap-2 mt-1 ps-4">
                                                        <label class="form-label form-label-sm mb-0 text-muted text-nowrap">PU&nbsp;€</label>
                                                        <input type="number"
                                                               class="form-control form-control-sm text-end"
                                                               style="max-width:100px"
                                                               step="0.01"
                                                               min="0.01"
                                                               value="{{ number_format((float) $ligne->prix_unitaire, 2, '.', '') }}"
                                                               wire:blur="updatePrixUnitaire({{ $ligne->id }}, $event.target.value)">
                                                        <label class="form-label form-label-sm mb-0 text-muted text-nowrap">&times;&nbsp;Qté</label>
                                                        <input type="number"
                                                               class="form-control form-control-sm text-end"
                                                               style="max-width:90px"
                                                               step="0.001"
                                                               min="0.001"
                                                               value="{{ number_format((float) $ligne->quantite, 3, '.', '') }}"
                                                               wire:blur="updateQuantite({{ $ligne->id }}, $event.target.value)">
                                                    </div>
                                                @endif
                                                @if ($ligne->type === \App\Enums\TypeLigneFacture::MontantManuel)
                                                    <div class="row g-2 mt-1 ps-4">
                                                        <div class="col-md-4">
                                                            <select class="form-select form-select-sm @if ($ligne->sous_categorie_id === null) is-invalid @endif"
                                                                    wire:change="updateSousCategorie({{ $ligne->id }}, $event.target.value)">
                                                                <option value="">— Sous-catégorie (requise) —</option>
                                                                @foreach ($sousCategoriesRecettes as $sc)
                                                                    <option value="{{ $sc->id }}" @selected((int) $ligne->sous_categorie_id === (int) $sc->id)>{{ $sc->nom }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <select class="form-select form-select-sm"
                                                                    wire:change="updateOperation({{ $ligne->id }}, $event.target.value)">
                                                                <option value="">— Opération (optionnel) —</option>
                                                                @foreach ($operations as $op)
                                                                    <option value="{{ $op->id }}" @selected((int) $ligne->operation_id === (int) $op->id)>{{ $op->nom }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                        @php $opLigne = $ligne->operation_id !== null ? $operations->firstWhere('id', $ligne->operation_id) : null; @endphp
                                                        @if ($opLigne !== null && (int) $opLigne->nombre_seances > 0)
                                                            <div class="col-md-2">
                                                                <select class="form-select form-select-sm"
                                                                        wire:change="updateSeance({{ $ligne->id }}, $event.target.value)">
                                                                    <option value="">— Séance —</option>
                                                                    @for ($i = 1; $i <= (int) $opLigne->nombre_seances; $i++)
                                                                        <option value="{{ $i }}" @selected((int) $ligne->seance === $i)>{{ $i }}</option>
                                                                    @endfor
                                                                </select>
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="text-end small fw-semibold text-nowrap">
                                                @if ($ligne->type === \App\Enums\TypeLigneFacture::Montant || $ligne->type === \App\Enums\TypeLigneFacture::MontantManuel)
                                                    {{ number_format((float) $ligne->montant, 2, ',', ' ') }} &euro;
                                                @else
                                                    <span class="text-muted fst-italic">texte</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <button wire:click="moveUp({{ $ligne->id }})"
                                                        wire:loading.attr="disabled" wire:target="updateLibelle"
                                                        class="btn btn-sm btn-outline-secondary"
                                                        title="Monter">
                                                    <i class="bi bi-arrow-up"></i>
                                                </button>
                                                <button wire:click="moveDown({{ $ligne->id }})"
                                                        wire:loading.attr="disabled" wire:target="updateLibelle"
                                                        class="btn btn-sm btn-outline-secondary"
                                                        title="Descendre">
                                                    <i class="bi bi-arrow-down"></i>
                                                </button>
                                            </td>
                                            <td class="text-center">
                                                @if ($ligne->type === \App\Enums\TypeLigneFacture::Texte || $ligne->type === \App\Enums\TypeLigneFacture::MontantManuel)
                                                    <button wire:click="supprimerLigneEditable({{ $ligne->id }})"
                                                            wire:confirm="Supprimer cette ligne ?"
                                                            wire:loading.attr="disabled" wire:target="updateLibelle"
                                                            class="btn btn-sm btn-outline-danger"
                                                            title="Supprimer">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td class="text-end">Total</td>
                                        <td class="text-end text-nowrap">{{ number_format((float) $totalLignes, 2, ',', ' ') }} &euro;</td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif

                    {{-- Boutons d'ajout de lignes (brouillon uniquement) --}}
                    @if ($this->canEdit && $facture->statut === \App\Enums\StatutFacture::Brouillon)
                        <div class="p-3 border-top d-flex flex-wrap gap-2">
                            <button wire:click="ouvrirFormLigneManuelle"
                                    class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-plus-circle"></i> Ajouter ligne facture
                            </button>
                            <button wire:click="ouvrirFormLigneTexteManuelle"
                                    class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-text-left"></i> Ajouter ligne texte
                            </button>
                        </div>

                        @if ($afficherFormLigneMontant)
                            @include('livewire.facture-edit.partials.ligne-manuelle-montant-form')
                        @endif

                        @if ($afficherFormLigneTexte)
                            @include('livewire.facture-edit.partials.ligne-texte-form')
                        @endif
                    @endif
                </div>
            </div>

            {{-- Résumé paiement --}}
            @php
                $montantTotal = $facture->montantCalcule();
                $montantRegle = $facture->montantRegle();
                $resteDu = $montantTotal - $montantRegle;
            @endphp
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-cash-stack"></i> Paiement</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="text-muted small">Total</div>
                            <div class="fs-6 fw-bold">{{ number_format($montantTotal, 2, ',', "\u{202F}") }}&nbsp;&euro;</div>
                        </div>
                        <div class="col-4">
                            <div class="text-muted small">Réglé</div>
                            <div class="fs-6 fw-bold text-success">{{ number_format($montantRegle, 2, ',', "\u{202F}") }}&nbsp;&euro;</div>
                        </div>
                        <div class="col-4">
                            <div class="text-muted small">Reste dû</div>
                            <div class="fs-6 fw-bold {{ $resteDu > 0 ? 'text-danger' : 'text-success' }}">{{ number_format($resteDu, 2, ',', "\u{202F}") }}&nbsp;&euro;</div>
                        </div>
                    </div>
                    @if ($montantRegle >= $montantTotal && $montantTotal > 0)
                        <div class="text-center mt-2">
                            <span class="badge bg-success"><i class="bi bi-check-circle"></i> Acquittée</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Conditions de règlement (visible ssi >= 1 ligne MontantManuel) --}}
            @if ($aLignesMontantManuel)
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-bank"></i> Conditions de règlement</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-0">
                            <label for="mode-paiement-prevu" class="form-label">
                                Mode de paiement prévu
                                @if ($facture->statut === \App\Enums\StatutFacture::Brouillon)
                                    <span class="text-danger" title="Requis à la validation">*</span>
                                @endif
                            </label>
                            <select id="mode-paiement-prevu"
                                    name="modePaiementPrevu"
                                    class="form-select"
                                    wire:model.live="modePaiementPrevu"
                                    {{ $facture->statut !== \App\Enums\StatutFacture::Brouillon ? 'disabled' : '' }}>
                                <option value="">— Sélectionner —</option>
                                @foreach ($modesPaiement as $mode)
                                    <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            {{-- Actions (en haut de la colonne droite) --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-lightning"></i> Actions</h5>
                </div>
                <div class="card-body d-grid gap-2">
                    @if($this->canEdit)
                    <button wire:click="sauvegarder" class="btn btn-primary">
                        <i class="bi bi-save"></i> Enregistrer
                    </button>

                    <button wire:click="valider"
                            wire:confirm="Valider cette facture ? Un numero sera attribue et la facture ne pourra plus etre modifiee."
                            class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Valider la facture
                    </button>
                    @endif

                    <a href="{{ route('facturation.factures.pdf', ['facture' => $facture, 'mode' => 'inline']) }}"
                       target="_blank"
                       class="btn btn-outline-secondary">
                        <i class="bi bi-file-earmark-pdf"></i> Prévisualiser PDF
                    </a>

                    @if($this->canEdit)
                    <button wire:click="supprimer"
                            wire:confirm="Supprimer ce brouillon ? Cette action est irreversible."
                            class="btn btn-outline-danger">
                        <i class="bi bi-trash"></i> Supprimer le brouillon
                    </button>
                    @endif
                </div>
            </div>

            {{-- Informations --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-gear"></i> Informations</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="facture-date" class="form-label">Date</label>
                        <input type="date" id="facture-date" class="form-control"
                               wire:model="date">
                    </div>

                    <div class="mb-3">
                        <label for="facture-compte" class="form-label">Compte bancaire</label>
                        <select id="facture-compte" class="form-select"
                                wire:model="compte_bancaire_id">
                            <option value="">-- Aucun --</option>
                            @foreach ($comptesBancaires as $compte)
                                <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="facture-conditions" class="form-label">Conditions de reglement</label>
                        <textarea id="facture-conditions" class="form-control" rows="2"
                                  wire:model="conditions_reglement"></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="facture-mentions" class="form-label">Mentions legales</label>
                        <textarea id="facture-mentions" class="form-control" rows="3"
                                  wire:model="mentions_legales"></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="facture-notes" class="form-label">Notes internes</label>
                        <textarea id="facture-notes" class="form-control" rows="2"
                                  wire:model="notes"></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
