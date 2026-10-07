@php
    $euros = fn (float $montant): string => number_format($montant, 2, ',', "\u{202F}");
    // Le nombre tel que le tri client le lit : un point, deux décimales.
    $nombre = fn (float $montant): string => number_format($montant, 2, '.', '');
    $fermee = $motifPeriodeFermee !== null;
    // Les boutons qui écrivent : désactivés par la période fermée ou par le rôle. Le service reste l'autorité.
    $peutAgir = $peutEcrire && ! $fermee;
    $pourquoiInactif = $fermee ? 'Période clôturée' : 'Réservé aux utilisateurs qui peuvent écrire en comptabilité';
@endphp

<div>
    <style>
        th[data-tri] { cursor: pointer; user-select: none; white-space: nowrap; }
        th[data-tri]::after { content: ' \2195'; opacity: .45; font-size: .75rem; }
        th[data-tri][aria-sort="ascending"]::after { content: ' \25B2'; opacity: 1; }
        th[data-tri][aria-sort="descending"]::after { content: ' \25BC'; opacity: 1; }
    </style>

    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2">
        <h4 class="fw-bold mb-0">Écritures de rattachement</h4>
        <div class="small text-muted" data-role="exercice">
            Exercice <strong>{{ $exerciceLabel }}</strong>
            · <a href="{{ route('exercices.changer') }}" data-role="changer-exercice">changer d’exercice</a>
        </div>
    </div>

    <p class="text-muted mb-3" data-role="introduction">
        Un produit encaissé ou une charge payée pendant l’exercice peut en réalité concerner le suivant — un parcours
        qui ne démarre qu’en septembre, par exemple. Cet écran le retire du résultat de l’exercice {{ $exerciceLabel }}
        et le reconnaît au début du suivant, par deux écritures qui se compensent.
    </p>

    @if ($fermee)
        <div class="alert alert-warning" data-role="periode-fermee">
            <i class="bi bi-lock me-1"></i> {{ $motifPeriodeFermee }}
        </div>
    @endif

    @if ($flashMessage !== '')
        <div class="alert alert-{{ in_array($flashType, ['success', 'warning', 'danger'], true) ? $flashType : 'info' }} alert-dismissible fade show"
             data-role="message" data-type="{{ $flashType }}">
            {{ $flashMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" wire:click="$set('flashMessage', '')"></button>
        </div>
    @endif

    {{-- ═══ À rattacher ═══ --}}
    <section data-zone="a-rattacher" class="mb-4">
        <h5 class="fw-bold">À rattacher</h5>
        <p class="small text-muted mb-2">
            Les charges et les produits de l’exercice affectés à une opération qui ne démarre qu’après sa fin.
        </p>

        @if ($candidats->isEmpty())
            <div class="alert alert-info mb-0" data-role="vide">
                <i class="bi bi-info-circle"></i> Aucune ligne à rattacher : aucune charge ni aucun produit de l’exercice
                {{ $exerciceLabel }} n’est affecté à une opération qui démarre après sa fin.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" data-triable>
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th data-tri>Date</th>
                            <th data-tri>Pièce</th>
                            <th data-tri>Compte</th>
                            <th data-tri>Libellé</th>
                            <th data-tri>Tiers</th>
                            <th data-tri>Opération</th>
                            <th data-tri class="text-end">Montant</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody style="color:#555">
                        @foreach ($candidats as $c)
                            <tr wire:key="candidat-{{ $c->transaction_ligne_id }}" data-ligne-id="{{ $c->transaction_ligne_id }}">
                                <td class="small text-nowrap" data-col="date" data-sort="{{ $c->piece_date->format('Y-m-d') }}">{{ $c->piece_date->format('d/m/Y') }}</td>
                                <td class="small text-nowrap" data-col="piece">{{ $c->numero_piece }}</td>
                                <td class="small" data-col="compte" data-sort="{{ $c->numero_pcg }}">
                                    <span class="fw-semibold">{{ $c->numero_pcg }}</span>
                                    <span class="text-muted">{{ $c->compte_intitule }}</span>
                                </td>
                                <td class="small" data-col="libelle">{{ filled($c->ligne_libelle) ? $c->ligne_libelle : $c->piece_libelle }}</td>
                                <td class="small" data-col="tiers">{{ $c->tiers_nom }}</td>
                                <td class="small" data-col="operation" data-sort="{{ $c->operation_nom }}">
                                    {{ $c->operation_nom }}
                                    @if ($c->operation_debut)
                                        <div class="text-muted text-nowrap">
                                            @if ($c->operation_fin)
                                                du {{ $c->operation_debut->format('d/m/Y') }} au {{ $c->operation_fin->format('d/m/Y') }}
                                            @else
                                                à partir du {{ $c->operation_debut->format('d/m/Y') }}
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="text-end small text-nowrap fw-semibold" data-col="montant" data-sort="{{ $nombre($c->montant) }}">{{ $euros($c->montant) }}&nbsp;€</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary text-nowrap"
                                            data-action="constater" data-ligne-id="{{ $c->transaction_ligne_id }}"
                                            wire:click="ouvrir({{ $c->transaction_ligne_id }})"
                                            @disabled(! $peutAgir) @if (! $peutAgir) title="{{ $pourquoiInactif }}" @endif>
                                        <i class="bi bi-calendar-plus"></i> Constater d’avance
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ═══ Recherche libre ═══ --}}
    <section data-zone="recherche" class="mb-4">
        <h5 class="fw-bold">Rechercher une autre ligne</h5>
        <p class="small text-muted mb-2">
            Le décalage porté par une opération n’est pas le seul : cherchez une ligne de charge ou de produit de
            l’exercice par son numéro de pièce, son libellé ou son montant.
        </p>
        <input type="search" class="form-control form-control-sm mb-2" style="max-width:420px"
               placeholder="Numéro de pièce, libellé ou montant…" autocomplete="off"
               data-role="recherche" wire:model.live.debounce.300ms="recherche">

        @if (trim($recherche) !== '')
            @if ($resultatsRecherche->isEmpty())
                <div class="small text-muted" data-role="recherche-vide">
                    Aucune ligne de charge ou de produit de l’exercice {{ $exerciceLabel }} ne correspond.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0" data-triable>
                        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                            <tr>
                                <th data-tri>Date</th>
                                <th data-tri>Pièce</th>
                                <th data-tri>Compte</th>
                                <th data-tri>Libellé</th>
                                <th data-tri class="text-end">Montant</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody style="color:#555">
                            @foreach ($resultatsRecherche as $ligne)
                                @php $montantLigne = round(max((float) $ligne->debit, (float) $ligne->credit), 2); @endphp
                                <tr wire:key="resultat-{{ $ligne->id }}" data-ligne-id="{{ $ligne->id }}">
                                    <td class="small text-nowrap" data-col="date" data-sort="{{ $ligne->transaction->date->format('Y-m-d') }}">{{ $ligne->transaction->date->format('d/m/Y') }}</td>
                                    <td class="small text-nowrap" data-col="piece">{{ $ligne->transaction->numero_piece }}</td>
                                    <td class="small" data-col="compte" data-sort="{{ $ligne->compte->numero_pcg }}">
                                        <span class="fw-semibold">{{ $ligne->compte->numero_pcg }}</span>
                                        <span class="text-muted">{{ $ligne->compte->intitule }}</span>
                                    </td>
                                    <td class="small" data-col="libelle">{{ filled($ligne->libelle) ? $ligne->libelle : $ligne->transaction->libelle }}</td>
                                    <td class="text-end small text-nowrap fw-semibold" data-col="montant" data-sort="{{ $nombre($montantLigne) }}">{{ $euros($montantLigne) }}&nbsp;€</td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary text-nowrap"
                                                data-action="constater" data-ligne-id="{{ $ligne->id }}"
                                                wire:click="ouvrir({{ $ligne->id }})"
                                                @disabled(! $peutAgir) @if (! $peutAgir) title="{{ $pourquoiInactif }}" @endif>
                                            <i class="bi bi-calendar-plus"></i> Constater d’avance
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($rechercheTronquee)
                    <div class="small text-muted mt-1" data-role="recherche-tronquee">
                        Seules les 20 premières lignes sont affichées : précisez votre recherche.
                    </div>
                @endif
            @endif
        @endif
    </section>

    {{-- ═══ Lignes écartées ═══ --}}
    @if ($ecartees->isNotEmpty())
        <section data-zone="ecartees" class="mb-4">
            <h5 class="fw-bold">Lignes écartées</h5>
            <p class="small text-muted mb-2">
                Ces lignes concernent l’exercice suivant, mais ne peuvent pas être rattachées. Le motif dit pourquoi.
            </p>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" data-triable>
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th data-tri>Date</th>
                            <th data-tri>Pièce</th>
                            <th data-tri>Compte</th>
                            <th data-tri>Libellé</th>
                            <th>Opérations</th>
                            <th data-tri class="text-end">Montant</th>
                            <th>Motif</th>
                        </tr>
                    </thead>
                    <tbody style="color:#555">
                        @foreach ($ecartees as $e)
                            <tr wire:key="ecartee-{{ $e->transaction_ligne_id }}" data-ligne-id="{{ $e->transaction_ligne_id }}">
                                <td class="small text-nowrap" data-col="date" data-sort="{{ $e->piece_date->format('Y-m-d') }}">{{ $e->piece_date->format('d/m/Y') }}</td>
                                <td class="small text-nowrap" data-col="piece">{{ $e->numero_piece }}</td>
                                <td class="small" data-col="compte" data-sort="{{ $e->numero_pcg }}">
                                    <span class="fw-semibold">{{ $e->numero_pcg }}</span>
                                    <span class="text-muted">{{ $e->compte_intitule }}</span>
                                </td>
                                <td class="small" data-col="libelle">{{ filled($e->ligne_libelle) ? $e->ligne_libelle : $e->piece_libelle }}</td>
                                <td class="small" data-col="operation">
                                    @if ($e->operation_nom)
                                        <div>{{ $e->operation_nom }}</div>
                                    @endif
                                    @foreach ($ventilationsDesEcartees->get($e->transaction_ligne_id, collect()) as $operation)
                                        <div>
                                            {{ $operation->nom }}
                                            <span class="text-muted text-nowrap">(à partir du {{ \Carbon\CarbonImmutable::parse($operation->date_debut)->format('d/m/Y') }})</span>
                                        </div>
                                    @endforeach
                                </td>
                                <td class="text-end small text-nowrap fw-semibold" data-col="montant" data-sort="{{ $nombre($e->montant) }}">{{ $euros($e->montant) }}&nbsp;€</td>
                                <td class="small" data-col="motif">{{ $e->motif }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- ═══ Rattachements de l'exercice ═══ --}}
    <section data-zone="rattachements" class="mb-4">
        <h5 class="fw-bold">Rattachements de l’exercice</h5>

        @if ($rattachements->isEmpty())
            <div class="alert alert-info mb-0" data-role="vide">
                <i class="bi bi-info-circle"></i> Aucun rattachement sur l’exercice {{ $exerciceLabel }}.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" data-triable>
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th data-tri>Cas</th>
                            <th data-tri>Origine</th>
                            <th data-tri>Compte</th>
                            <th data-tri>Libellé</th>
                            <th data-tri>Opération</th>
                            <th data-tri class="text-end">Montant</th>
                            <th>Pièce jointe</th>
                            <th data-tri>Saisi par</th>
                            <th data-tri>Saisi le</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody style="color:#555">
                        @foreach ($rattachements as $p)
                            @php
                                $compteResultat = $p->compteResultat();
                                $operationId = $p->operationEffectiveId();
                                $operation = $operationId !== null ? $operationsDesRattachements->get($operationId) : null;
                            @endphp
                            <tr wire:key="rattachement-{{ $p->id }}" data-provision-id="{{ $p->id }}">
                                <td class="small" data-col="cas">{{ $compteResultat !== null ? $p->cas()->label() : '—' }}</td>
                                {{-- Le numéro de la pièce d'origine : il n'existe pas de lien direct vers une pièce. --}}
                                <td class="small text-nowrap" data-col="origine">{{ $p->ligne?->transaction?->numero_piece ?? '—' }}</td>
                                <td class="small" data-col="compte" data-sort="{{ $compteResultat?->numero_pcg }}">
                                    @if ($compteResultat)
                                        <span class="fw-semibold">{{ $compteResultat->numero_pcg }}</span>
                                        <span class="text-muted">{{ $compteResultat->intitule }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="small" data-col="libelle">{{ $p->libelleEffectif() }}</td>
                                <td class="small" data-col="operation">{{ $operation?->nom ?? '—' }}</td>
                                <td class="text-end small text-nowrap fw-semibold" data-col="montant" data-sort="{{ $nombre((float) $p->montant) }}">{{ $euros((float) $p->montant) }}&nbsp;€</td>
                                <td class="small" data-col="piece-jointe">
                                    @if ($p->hasPieceJointe())
                                        <button type="button" class="btn btn-link btn-sm p-0 text-start"
                                                data-action="telecharger" wire:click="telechargerPieceJointe({{ $p->id }})">
                                            <i class="bi bi-paperclip"></i> {{ $p->piece_jointe_nom }}
                                        </button>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="small" data-col="auteur">{{ $p->saisiPar?->nom ?? '—' }}</td>
                                <td class="small text-nowrap" data-col="saisie" data-sort="{{ $p->created_at?->format('Y-m-d H:i:s') }}">{{ $p->created_at?->format('d/m/Y') }}</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger text-nowrap"
                                            data-action="retirer" data-provision-id="{{ $p->id }}"
                                            wire:click="retirer({{ $p->id }})"
                                            wire:confirm="Retirer ce rattachement ? Ses deux écritures seront supprimées et la ligne d’origine redeviendra modifiable."
                                            @disabled(! $peutAgir) @if (! $peutAgir) title="{{ $pourquoiInactif }}" @endif>
                                        <i class="bi bi-x-circle"></i> Retirer
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ═══ La modale « Constater d'avance » ═══ --}}
    @if ($modale !== null)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)"
             data-bs-backdrop="static" data-bs-keyboard="false" wire:key="modale-rattachement" data-role="modale">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Constater d’avance</h5>
                        <button type="button" class="btn-close" wire:click="fermer" aria-label="Fermer"></button>
                    </div>

                    <div class="modal-body">
                        @if ($modale['refus'] !== null)
                            <div class="alert alert-warning" data-role="modale-refus">
                                <i class="bi bi-exclamation-triangle me-1"></i> {{ $modale['refus'] }}
                            </div>
                        @endif

                        @if ($modale['ligne'] !== null)
                            @if ($modale['phrase'] !== null)
                                <div class="alert alert-info" data-role="modale-phrase">
                                    <strong>{{ $modale['cas']->label() }}</strong> — {{ $modale['phrase'] }}
                                </div>
                            @endif

                            {{-- Ce que la ligne d'origine apporte : en lecture seule, le rattachement porte la ligne entière. --}}
                            <dl class="row small mb-3" data-role="modale-origine">
                                <dt class="col-sm-3">Compte</dt>
                                <dd class="col-sm-9" data-champ="compte">
                                    @if ($modale['compte'])
                                        <span class="fw-semibold">{{ $modale['compte']->numero_pcg }}</span> {{ $modale['compte']->intitule }}
                                    @else
                                        —
                                    @endif
                                </dd>
                                <dt class="col-sm-3">Opération</dt>
                                <dd class="col-sm-9" data-champ="operation">
                                    @if ($modale['operation'])
                                        {{ $modale['operation']->nom }}
                                        @if ($modale['operation']->date_debut)
                                            <span class="text-muted">
                                                ({{ $modale['operation']->date_fin
                                                    ? 'du '.$modale['operation']->date_debut->format('d/m/Y').' au '.$modale['operation']->date_fin->format('d/m/Y')
                                                    : 'à partir du '.$modale['operation']->date_debut->format('d/m/Y') }})
                                            </span>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </dd>
                                <dt class="col-sm-3">Séance</dt>
                                <dd class="col-sm-9" data-champ="seance">{{ $modale['seance'] ?? '—' }}</dd>
                                <dt class="col-sm-3">Tiers</dt>
                                <dd class="col-sm-9" data-champ="tiers">{{ $modale['tiers'] ?? '—' }}</dd>
                                <dt class="col-sm-3">Montant</dt>
                                <dd class="col-sm-9 fw-semibold" data-champ="montant">{{ $euros($modale['montant']) }}&nbsp;€</dd>
                                <dt class="col-sm-3">Date de la pièce</dt>
                                <dd class="col-sm-9" data-champ="date-piece">
                                    {{ $modale['datePiece']?->format('d/m/Y') ?? '—' }}
                                    @if ($modale['numeroPiece'])
                                        <span class="text-muted">· pièce {{ $modale['numeroPiece'] }}</span>
                                    @endif
                                </dd>
                            </dl>
                        @endif

                        @if ($modale['apercu'] !== null)
                            <div class="mb-3" data-role="apercu">
                                <div class="fw-semibold mb-2">Les deux écritures qui seront passées</div>
                                @foreach ($modale['apercu'] as $ecriture)
                                    <div class="mb-3" data-ecriture="{{ $ecriture['cle'] }}">
                                        <div class="small fw-semibold">
                                            {{ $ecriture['titre'] }} — {{ $ecriture['date']->format('d/m/Y') }}
                                        </div>
                                        <div class="small text-muted mb-1">{{ $ecriture['libelle'] }}</div>
                                        <table class="table table-sm mb-0">
                                            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                                <tr>
                                                    <th>Compte</th>
                                                    <th class="text-end">Débit</th>
                                                    <th class="text-end">Crédit</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($ecriture['lignes'] as $jambe)
                                                    <tr data-date="{{ $ecriture['date']->format('Y-m-d') }}" data-compte="{{ $jambe['numero_pcg'] }}"
                                                        data-debit="{{ $nombre($jambe['debit']) }}" data-credit="{{ $nombre($jambe['credit']) }}">
                                                        <td class="small">
                                                            <span class="fw-semibold">{{ $jambe['numero_pcg'] }}</span>
                                                            <span class="text-muted">{{ $jambe['intitule'] }}</span>
                                                        </td>
                                                        {{-- Le côté inutilisé reste vide : c'est la présentation d'une écriture. --}}
                                                        <td class="text-end small text-nowrap">@if ($jambe['debit'] > 0){{ $euros($jambe['debit']) }}&nbsp;€@endif</td>
                                                        <td class="text-end small text-nowrap">@if ($jambe['credit'] > 0){{ $euros($jambe['credit']) }}&nbsp;€@endif</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endforeach
                            </div>

                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label small mb-1">Notes</label>
                                    <textarea class="form-control form-control-sm @error('notes') is-invalid @enderror"
                                              rows="2" wire:model="notes"></textarea>
                                    @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1"><i class="bi bi-paperclip"></i> Justificatif</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <x-zone-depot>
                                            <label class="btn btn-sm btn-outline-secondary mb-0">
                                                <i class="bi bi-paperclip"></i> Joindre un justificatif
                                                <input type="file" wire:model="pieceJointe" accept=".pdf,.jpg,.jpeg,.png" class="d-none">
                                            </label>
                                        </x-zone-depot>
                                        @if ($pieceJointe)
                                            <span class="small text-success"><i class="bi bi-check-circle"></i> {{ $pieceJointe->getClientOriginalName() }}</span>
                                        @endif
                                        <div wire:loading wire:target="pieceJointe" class="spinner-border spinner-border-sm text-primary"></div>
                                    </div>
                                    @error('pieceJointe') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                </div>
                            </div>
                        @endif

                        @if ($erreurModale !== '')
                            <div class="alert alert-danger mt-3 mb-0" data-role="modale-erreur">
                                <i class="bi bi-x-octagon me-1"></i> {{ $erreurModale }}
                            </div>
                        @endif
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="fermer">{{ $modale['apercu'] !== null ? 'Annuler' : 'Fermer' }}</button>
                        @if ($modale['apercu'] !== null)
                            <button type="button" class="btn btn-primary" data-action="valider" wire:click="confirmer"
                                    wire:loading.attr="disabled" wire:target="confirmer,pieceJointe"
                                    @disabled(! $peutAgir) @if (! $peutAgir) title="{{ $pourquoiInactif }}" @endif>
                                <i class="bi bi-check-lg"></i> Constater le rattachement
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
