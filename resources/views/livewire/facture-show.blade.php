<div>
    @if (session('success'))
        <div class="alert alert-success alert-dismissible">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- En-tête contextuel --}}
    <div class="d-flex align-items-center gap-2 mb-3">
        @if ($isAcquittee)
            <span class="badge bg-success">Acquittée</span>
        @elseif ($facture->statut === \App\Enums\StatutFacture::Annulee)
            <span class="badge bg-danger">Annulée</span>
        @elseif ($montantRegle > 0)
            <span class="badge bg-warning text-dark">Partiellement réglée</span>
        @else
            <span class="badge bg-secondary">Non réglée</span>
        @endif
        <span class="text-muted small">
            Émission : <strong>{{ $facture->date->format('d/m/Y') }}</strong>
            — Exercice {{ $facture->exercice }}/{{ $facture->exercice + 1 }}
        </span>
        @if ($facture->statut === \App\Enums\StatutFacture::Annulee && $facture->numero_avoir)
            <span class="text-muted small">
                — Avoir <strong>{{ $facture->numero_avoir }}</strong>
                émis le <strong>{{ $facture->date_annulation->format('d/m/Y') }}</strong>
            </span>
        @endif
    </div>

    <div class="row">
        <div class="col-lg-8">
            {{-- Lignes de facture --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-receipt"></i> Lignes de facture</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                <tr>
                                    <th>Désignation</th>
                                    <th class="text-end" style="width: 140px;">Montant</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @foreach ($facture->lignes as $ligne)
                                    <tr wire:key="ligne-{{ $ligne->id }}">
                                        @if ($ligne->type === \App\Enums\TypeLigneFacture::Texte)
                                            <td colspan="2" class="fw-bold">{{ $ligne->libelle }}</td>
                                        @else
                                            <td>{{ $ligne->libelle }}</td>
                                            <td class="text-end fw-semibold text-nowrap">{{ number_format((float) $ligne->montant, 2, ',', "\u{202f}") }}&nbsp;&euro;</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td class="text-end">Total</td>
                                    <td class="text-end text-nowrap">{{ number_format($facture->montantCalcule(), 2, ',', "\u{202f}") }}&nbsp;&euro;</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            @if ($facture->statut !== \App\Enums\StatutFacture::Annulee)
            {{-- Statut de paiement --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-cash-stack"></i> Paiement</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-4 mb-3 mb-md-0">
                            <div class="text-muted small">Montant total</div>
                            <div class="fs-5 fw-bold">{{ number_format($facture->montantCalcule(), 2, ',', "\u{202f}") }}&nbsp;&euro;</div>
                        </div>
                        <div class="col-md-4 mb-3 mb-md-0">
                            <div class="text-muted small">Montant réglé</div>
                            <div class="fs-5 fw-bold text-success">{{ number_format($montantRegle, 2, ',', "\u{202f}") }}&nbsp;&euro;</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Reste dû</div>
                            @php $resteDu = $facture->montantCalcule() - $montantRegle; @endphp
                            <div class="fs-5 fw-bold {{ $resteDu > 0 ? 'text-danger' : 'text-success' }}">
                                {{ number_format($resteDu, 2, ',', "\u{202f}") }}&nbsp;&euro;
                            </div>
                        </div>
                    </div>

                    @if ($isAcquittee)
                        <div class="text-center mt-3">
                            <span class="badge bg-success fs-6"><i class="bi bi-check-circle"></i> Acquittée</span>
                        </div>
                    @elseif ($transactionsAEncaisser->isNotEmpty() && $this->canEdit)
                        <div class="text-center mt-3">
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#encaissementModal">
                                <i class="bi bi-cash-coin"></i> Enregistrer le règlement
                            </button>
                        </div>
                    @endif
                </div>
            </div>
            @endif
        </div>

        <div class="col-lg-4">
            {{-- Tiers --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-person"></i> Tiers</h5>
                </div>
                <div class="card-body">
                    <p class="fw-bold mb-1">{{ $facture->tiers->displayName() }}</p>
                    @if ($facture->tiers->adresse_ligne1)
                        <p class="mb-0 text-muted small">
                            {{ $facture->tiers->adresse_ligne1 }}<br>
                            @if ($facture->tiers->code_postal || $facture->tiers->ville)
                                {{ $facture->tiers->code_postal }} {{ $facture->tiers->ville }}
                            @endif
                        </p>
                    @endif
                </div>
            </div>

            {{-- Conditions de reglement --}}
            @if ($facture->conditions_reglement)
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-clock"></i> Conditions de règlement</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-0">{!! nl2br(e($facture->conditions_reglement)) !!}</p>
                    </div>
                </div>
            @endif

            {{-- Mentions legales --}}
            @if ($facture->mentions_legales)
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-shield-check"></i> Mentions légales</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-0 small">{!! nl2br(e($facture->mentions_legales)) !!}</p>
                    </div>
                </div>
            @endif

            {{-- Notes internes --}}
            @if ($facture->notes)
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-sticky"></i> Notes internes</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-0 small text-muted">{!! nl2br(e($facture->notes)) !!}</p>
                    </div>
                </div>
            @endif

            {{-- Actions --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-lightning"></i> Actions</h5>
                </div>
                <div class="card-body d-grid gap-2">
                    <a href="{{ route(($espace ?? \App\Enums\Espace::Compta)->value . '.factures.pdf', ['facture' => $facture, 'mode' => 'inline']) }}" class="btn btn-outline-primary" target="_blank">
                        <i class="bi bi-file-earmark-pdf"></i>
                        {{ $facture->statut === \App\Enums\StatutFacture::Annulee ? 'Télécharger l\'avoir (PDF)' : 'Télécharger PDF' }}
                    </a>
                    @if ($facture->statut === \App\Enums\StatutFacture::Validee && $this->canEdit)
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#annulationModal">
                            <i class="bi bi-x-circle"></i> Annuler avec avoir
                        </button>
                    @endif
                    @if($this->canEdit)
                    @if ($facture->tiers?->email)
                        <button wire:click="envoyerEmail" class="btn btn-outline-primary" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="envoyerEmail"><i class="bi bi-envelope"></i> Envoyer par email</span>
                            <span wire:loading wire:target="envoyerEmail"><i class="bi bi-hourglass-split"></i> Envoi...</span>
                        </button>
                    @else
                        <button class="btn btn-outline-secondary" disabled title="Aucune adresse email pour ce tiers">
                            <i class="bi bi-envelope-x"></i> Pas d'email
                        </button>
                    @endif
                    @endif
                    @if ($emailMessage)
                        <div class="alert alert-{{ $emailMessageType }} py-2 px-3 mb-0 small">
                            {{ $emailMessage }}
                        </div>
                    @endif
                    <a href="{{ route(($espace ?? \App\Enums\Espace::Compta)->value . '.factures') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Retour à la liste
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- Modale de confirmation d'annulation --}}
    @if ($facture->statut === \App\Enums\StatutFacture::Validee)
    <div class="modal fade" id="annulationModal" tabindex="-1" wire:ignore.self>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Annuler cette facture</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Êtes-vous sûr de vouloir annuler la facture <strong>{{ $facture->numero }}</strong> ?</p>
                    <ul class="text-muted small">
                        <li>Un avoir sera émis avec un numéro séquentiel</li>
                        <li>Les transactions associées seront libérées</li>
                        <li>Cette action est irréversible</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Non, conserver</button>
                    <button type="button" class="btn btn-danger" wire:click="annuler" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Oui, émettre l'avoir
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Modale d'encaissement --}}
    @if ($transactionsAEncaisser->isNotEmpty())
    <div class="modal fade" id="encaissementModal" tabindex="-1" wire:ignore.self>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-cash-coin"></i> Enregistrer le règlement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Sélectionnez les créances reçues et le compte bancaire de destination.</p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Créances en attente</label>
                        @foreach ($transactionsAEncaisser as $tx)
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="tx-{{ $tx->id }}"
                                       wire:click="toggleTransaction({{ $tx->id }})"
                                       @checked(in_array($tx->id, $selectedTransactionIds))>
                                <label class="form-check-label" for="tx-{{ $tx->id }}">
                                    {{ $tx->libelle }}
                                    <span class="fw-semibold text-nowrap">— {{ number_format((float) $tx->montant_total, 2, ',', "\u{202f}") }}&nbsp;&euro;</span>
                                </label>
                            </div>
                        @endforeach
                    </div>

                    <div class="mb-3">
                        <label for="encaissement-compte" class="form-label fw-semibold">Compte bancaire de destination</label>
                        <select wire:model="encaissementCompteId" id="encaissement-compte" class="form-select">
                            <option value="">-- Choisir --</option>
                            @foreach ($comptesDestination as $compte)
                                <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-primary" wire:click="encaisser" data-bs-dismiss="modal">
                        <i class="bi bi-check-lg"></i> Confirmer l'encaissement
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Modale choix expéditeur --}}
    @if ($showEmailSenderModal)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Choisir l'adresse d'expédition</h5>
                    <button type="button" class="btn-close" wire:click="$set('showEmailSenderModal', false)"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Plusieurs adresses d'expédition sont configurées pour les opérations liées à cette facture.</p>
                    @foreach ($emailSenderChoices as $choice)
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="emailSender"
                                   id="sender-{{ $loop->index }}"
                                   value="{{ $choice['email'] }}"
                                   wire:click="$set('selectedEmailFrom', '{{ $choice['email'] }}'); $set('selectedEmailFromName', '{{ $choice['name'] ?? '' }}')"
                                   @checked($selectedEmailFrom === $choice['email'])>
                            <label class="form-check-label" for="sender-{{ $loop->index }}">
                                {{ $choice['label'] }}
                            </label>
                        </div>
                    @endforeach
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="$set('showEmailSenderModal', false)">Annuler</button>
                    <button type="button" class="btn btn-primary" wire:click="confirmSendEmail">
                        <i class="bi bi-envelope"></i> Envoyer
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
