<div
    x-data="{}"
    x-on:keydown.escape.window="$wire.close()"
>
    {{-- Modale avertissement avant première émission reçu fiscal --}}
    @if($showModaleAvertissement)
        <div class="modal fade show d-block" tabindex="-1" style="z-index:2060;background-color:rgba(0,0,0,.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Vérifications avant émission</h5>
                        <button type="button" class="btn-close" wire:click="fermerModaleAvertissement"></button>
                    </div>
                    <div class="modal-body">
                        @if(in_array('helloasso', $avertissementsActifs))
                            <div class="alert alert-warning">
                                <strong>HelloAsso peut avoir déjà émis un reçu fiscal pour ce don.</strong>
                                Le donateur ne doit pas déduire deux fois le même montant.
                            </div>
                        @endif
                        @if(in_array('donnees_modifiees', $avertissementsActifs))
                            <div class="alert alert-info">
                                Les coordonnées du donateur ou de l'association ont été modifiées depuis le don.
                                Le reçu portera les coordonnées actuelles.
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="fermerModaleAvertissement">Annuler</button>
                        <button type="button" class="btn btn-primary" wire:click="continuerTelechargement">Continuer</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modale annulation + ré-émission reçu fiscal --}}
    @if($showModaleAnnulation)
        <div class="modal fade show d-block" tabindex="-1" style="z-index:2060;background-color:rgba(0,0,0,.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Annuler et ré-émettre</h5>
                        <button type="button" class="btn-close" wire:click="fermerModaleAnnulation"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">Le reçu actuel sera annulé et un nouveau sera généré avec les coordonnées actuelles du tiers.</p>
                        <label for="motif-annulation" class="form-label">Motif</label>
                        <input type="text"
                               id="motif-annulation"
                               class="form-control"
                               wire:model="motifAnnulation"
                               placeholder="Ex : Adresse corrigée">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="fermerModaleAnnulation">Annuler</button>
                        <button type="button" class="btn btn-primary" wire:click="confirmerReEmission">
                            Confirmer la ré-émission
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($visible && $tiers !== null)
        {{-- Backdrop --}}
        <div
            class="position-fixed top-0 start-0 w-100 h-100"
            style="background:rgba(0,0,0,.45);z-index:2049"
            wire:click="close"
        ></div>

        {{-- Floating card --}}
        <div
            class="position-fixed top-50 start-50 translate-middle shadow-lg rounded-3"
            style="z-index:2050;width:560px;max-height:85vh;overflow-y:auto;background:#fff"
            @click.stop
        >
            {{-- Header --}}
            <div class="px-3 py-2 rounded-top-3" style="background:#f0e8f5;border-bottom:1px solid #d9c7ea">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-1 overflow-hidden">
                        <span style="font-size:.8rem">@if($tiers->type === 'entreprise')🏢@else👤@endif</span>
                        <span class="fw-semibold text-truncate" style="color:#4a1060;max-width:280px;font-size:.85rem">
                            {{ $tiers->displayName() }}
                        </span>
                    </div>
                    <div class="d-flex flex-column align-items-end flex-shrink-0">
                        <div class="d-flex align-items-center gap-2">
                            <select wire:model.live="exercice"
                                    class="form-select py-0 px-1 border-0"
                                    style="width:auto;font-size:.6rem;background:#f0e8f5;color:#4a1060">
                                @foreach($availableYears as $year)
                                    <option value="{{ $year }}">{{ $year }}-{{ $year + 1 }}</option>
                                @endforeach
                            </select>
                            <button type="button" class="btn-close" wire:click="close" aria-label="Fermer" style="font-size:.55rem"></button>
                        </div>
                    </div>
                </div>
                {{-- Contact + Adhésion --}}
                <div class="d-flex justify-content-between align-items-center mt-1" style="font-size:.6rem">
                    <div class="d-flex gap-3">
                        @if(!empty($summary['contact']['email']))
                            <a href="mailto:{{ $summary['contact']['email'] }}" class="text-decoration-none" style="color:#6b5077">
                                <i class="bi bi-envelope me-1"></i>{{ $summary['contact']['email'] }}
                            </a>
                        @endif
                        @if(!empty($summary['contact']['telephone']))
                            <a href="tel:{{ $summary['contact']['telephone'] }}" class="text-decoration-none" style="color:#6b5077">
                                <i class="bi bi-telephone me-1"></i>{{ $summary['contact']['telephone'] }}
                            </a>
                        @endif
                        @if(!empty($summary['contact']['is_abonne_newsletter']))
                            <span class="badge bg-success" style="font-size:.55rem" title="Abonné·e à la newsletter">
                                <i class="bi bi-envelope-heart me-1"></i>Newsletter
                            </span>
                        @endif
                        @if(!empty($summary['contact']['is_optout']))
                            <span class="badge bg-warning text-dark" style="font-size:.55rem" title="Le tiers s'est désinscrit des communications (RGPD)">
                                <i class="bi bi-envelope-slash me-1"></i>Opt-out
                            </span>
                        @endif
                    </div>
                    @isset($summary['cotisations'])
                        <span style="color:#4a1060">
                            <i class="bi bi-person-check-fill me-1"></i>Adhérent — {{ number_format((float)$summary['cotisations']['total'], 2, ',', ' ') }} €
                        </span>
                    @endisset
                </div>
            </div>

            <div class="p-3">

                @php
                    $hasSections = isset($summary['depenses'])
                        || isset($summary['recettes'])
                        || isset($summary['dons'])
                        || isset($summary['cotisations'])
                        || isset($summary['participations'])
                        || isset($summary['referent'])
                        || isset($summary['factures'])
                        || isset($summary['devis_libres']);
                    // cotisations shown in header, factures in flux line — but still count for "has activity"
                @endphp

                @if(!$hasSections)
                    <p class="text-muted small mb-0 text-center py-3">
                        <i class="bi bi-inbox me-1"></i>Aucune activité sur cet exercice.
                    </p>
                @else

                    {{-- Flux financiers (ligne compacte avec badges) --}}
                    @if(isset($summary['recettes']) || isset($summary['dons']) || isset($summary['depenses']) || isset($summary['factures']))
                        <div class="d-flex flex-wrap gap-3 mb-3 small justify-content-center">
                            @isset($summary['recettes'])
                                <a href="{{ route('tiers.transactions', $tiers->id) }}" class="text-decoration-none text-nowrap text-dark">
                                    <span class="badge bg-success" style="font-size:.65rem">{{ $summary['recettes']['count'] }} REC</span>
                                    {{ number_format((float)$summary['recettes']['total'], 2, ',', ' ') }} €
                                </a>
                            @endisset
                            @isset($summary['dons'])
                                <a href="{{ route('tiers.transactions', $tiers->id) }}" class="text-decoration-none text-nowrap text-dark">
                                    <span class="badge bg-warning text-dark" style="font-size:.65rem">{{ $summary['dons']['count'] }} DON</span>
                                    {{ number_format((float)$summary['dons']['total'], 2, ',', ' ') }} €
                                </a>
                            @endisset
                            @isset($summary['depenses'])
                                <a href="{{ route('tiers.transactions', $tiers->id) }}" class="text-decoration-none text-nowrap text-dark">
                                    <span class="badge bg-danger" style="font-size:.65rem">{{ $summary['depenses']['count'] }} DEP</span>
                                    {{ number_format((float)$summary['depenses']['total'], 2, ',', ' ') }} €
                                </a>
                            @endisset
                        </div>
                    @endif

                    {{-- Détail dépenses par opération --}}
                    @if(isset($summary['depenses']['par_operation']) && count($summary['depenses']['par_operation']) > 0)
                        <div class="mb-3">
                            <div class="fw-semibold small mb-1">Dépenses par opération</div>
                            <ul class="list-unstyled ms-2 mb-0">
                                @foreach($summary['depenses']['par_operation'] as $op)
                                    <li class="small text-muted">
                                        <a href="{{ route('operations.show', $op['operation_id']) }}"
                                           class="text-decoration-none text-muted">
                                            <i class="bi bi-link-45deg"></i>{{ $op['operation_nom'] }}
                                        </a>
                                        @if($op['sous_categorie']) — {{ $op['sous_categorie'] }}@endif
                                        : {{ number_format((float)$op['total'], 2, ',', ' ') }} € ({{ $op['count'] }})
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Participations --}}
                    @isset($summary['participations'])
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-1 mb-1">
                                <i class="bi bi-calendar-event small text-primary"></i>
                                <span class="fw-semibold small">Participations</span>
                            </div>
                            <ul class="list-unstyled ms-3 mb-0">
                                @foreach($summary['participations'] as $part)
                                    <li class="small text-muted">
                                        <a href="{{ route('operations.show', $part['operation_id']) }}"
                                           class="text-decoration-none text-muted" target="_blank">
                                            <i class="bi bi-link-45deg"></i>{{ $part['operation_nom'] }}
                                        </a>
                                        @if(!empty($part['date_debut']))
                                            <span class="ms-1">— {{ \Carbon\Carbon::parse($part['date_debut'])->format('d/m/Y') }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endisset

                    {{-- Encadrements --}}
                    @isset($summary['animations'])
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-1 mb-1">
                                <i class="bi bi-person-workspace small text-info"></i>
                                <span class="fw-semibold small">Encadrements</span>
                            </div>
                            <ul class="list-unstyled ms-3 mb-0">
                                @foreach($summary['animations'] as $anim)
                                    <li class="small text-muted">
                                        <a href="{{ route('operations.show', $anim['operation_id']) }}"
                                           class="text-decoration-none text-muted" target="_blank">
                                            <i class="bi bi-link-45deg"></i>{{ $anim['operation_nom'] }}
                                        </a>
                                        @if(!empty($anim['date_debut']))
                                            <span class="ms-1">— {{ \Carbon\Carbon::parse($anim['date_debut'])->format('d/m/Y') }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endisset

                    {{-- Référent --}}
                    @isset($summary['referent'])
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-1 mb-1">
                                <i class="bi bi-people-fill small text-secondary"></i>
                                <span class="fw-semibold small">Référents</span>
                            </div>
                            <ul class="list-unstyled ms-3 mb-0">
                                @isset($summary['referent']['refere_par'])
                                    <li class="small text-muted mb-1">
                                        <i class="bi bi-person-fill me-1"></i><em>A référé :</em>
                                        @foreach($summary['referent']['refere_par'] as $r)
                                            <div class="ps-3">{{ $r['nom'] }}@if($r['operation']) <span class="text-muted">— {{ $r['operation'] }}</span>@endif</div>
                                        @endforeach
                                    </li>
                                @endisset
                                @isset($summary['referent']['medecin'])
                                    <li class="small text-muted mb-1">
                                        <i class="bi bi-hospital me-1"></i><em>Médecin de :</em>
                                        @foreach($summary['referent']['medecin'] as $r)
                                            <div class="ps-3">{{ $r['nom'] }}@if($r['operation']) <span class="text-muted">— {{ $r['operation'] }}</span>@endif</div>
                                        @endforeach
                                    </li>
                                @endisset
                                @isset($summary['referent']['therapeute'])
                                    <li class="small text-muted mb-1">
                                        <i class="bi bi-heart-pulse me-1"></i><em>Thérapeute de :</em>
                                        @foreach($summary['referent']['therapeute'] as $r)
                                            <div class="ps-3">{{ $r['nom'] }}@if($r['operation']) <span class="text-muted">— {{ $r['operation'] }}</span>@endif</div>
                                        @endforeach
                                    </li>
                                @endisset
                            </ul>
                        </div>
                    @endisset

                    {{-- Factures moved to flux financiers line --}}

                    {{-- Devis manuels --}}
                    @isset($summary['devis_libres'])
                        @php
                            $dl = $summary['devis_libres'];
                            $dlStatuts = [
                                'brouillon' => ['label' => 'Brouillon', 'badge' => 'secondary'],
                                'envoye'    => ['label' => 'Envoyé',    'badge' => 'primary'],
                                'accepte'   => ['label' => 'Accepté',   'badge' => 'success'],
                                'refuse'    => ['label' => 'Refusé',    'badge' => 'danger'],
                                'annule'    => ['label' => 'Annulé',    'badge' => 'dark'],
                            ];
                        @endphp
                        <div class="mb-3">
                            <div class="d-flex align-items-center justify-content-between gap-1 mb-1">
                                <div class="d-flex align-items-center gap-1">
                                    <i class="bi bi-file-earmark-text small text-warning"></i>
                                    <span class="fw-semibold small">Devis manuels</span>
                                </div>
                                <a href="{{ route('devis-manuels.index', ['filtreTiersId' => $tiers->id]) }}"
                                   class="small text-decoration-none" style="color:#722281;font-size:.65rem" target="_blank">
                                    Voir tous →
                                </a>
                            </div>
                            <div class="d-flex flex-wrap gap-2 ms-3">
                                @foreach($dlStatuts as $statut => $meta)
                                    @if(($dl['counts'][$statut] ?? 0) > 0)
                                        <span class="badge bg-{{ $meta['badge'] }}" style="font-size:.65rem">
                                            {{ $dl['counts'][$statut] }} {{ $meta['label'] }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                            @if((float)($dl['total_acceptes'] ?? 0) > 0)
                                <div class="ms-3 mt-1 small text-muted">
                                    Total accepté : <strong>{{ number_format((float)$dl['total_acceptes'], 2, ',', ' ') }} €</strong>
                                </div>
                            @endif
                        </div>
                    @endisset

                @endif

                {{-- Dons détaillés avec reçus fiscaux --}}
                @if(isset($dons) && $dons->isNotEmpty())
                    <div class="mt-3">
                        <div class="d-flex align-items-center gap-1 mb-1">
                            <i class="bi bi-heart-fill small text-warning"></i>
                            <span class="fw-semibold small">Dons du tiers</span>
                        </div>
                        <table class="table table-sm table-hover mb-0" style="font-size:.75rem">
                            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                <tr>
                                    <th>Date</th>
                                    <th>Sous-catégorie</th>
                                    <th>Mode</th>
                                    <th class="text-end">Montant</th>
                                    <th>Reçu fiscal</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($dons as $don)
                                    <tr>
                                        <td data-sort="{{ $don->transaction->date->format('Y-m-d') }}">
                                            {{ $don->transaction->date->format('d/m/Y') }}
                                        </td>
                                        <td>{{ $don->sousCategorie->nom }}</td>
                                        <td>{{ ucfirst($don->transaction->mode_paiement?->value ?? '—') }}</td>
                                        <td class="text-end" data-sort="{{ $don->montant }}">
                                            {{ number_format((float) $don->montant, 2, ',', ' ') }} €
                                        </td>
                                        <td>
                                            @if(isset($recusParLigne[$don->id]))
                                                @php $recu = $recusParLigne[$don->id]; @endphp
                                                <a href="{{ route('tiers.dons.recu-fiscal', ['tiers' => $tiers, 'ligne' => $don]) }}"
                                                   class="badge bg-success text-decoration-none"
                                                   target="_blank">
                                                    n° {{ $recu->numero }}
                                                </a>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-secondary ms-1 py-0 lh-1"
                                                        style="font-size:.65rem"
                                                        wire:click="ouvrirModaleAnnulation({{ $recu->id }})"
                                                        title="Annuler et ré-émettre">⋯</button>
                                            @else
                                                @php $alertes = $alertesParLigne[$don->id] ?? []; @endphp
                                                @if(!empty($alertes))
                                                    <button type="button"
                                                            class="btn btn-sm btn-primary py-0"
                                                            style="font-size:.65rem"
                                                            wire:click="afficherAvertissements({{ $don->id }})">
                                                        Télécharger reçu fiscal
                                                    </button>
                                                    <div class="mt-1 d-flex flex-wrap gap-1">
                                                        @if(in_array('helloasso', $alertes))
                                                            <span class="badge bg-warning text-dark" style="font-size:.55rem" title="HelloAsso peut avoir déjà émis un reçu fiscal">
                                                                ⚠ HelloAsso
                                                            </span>
                                                        @endif
                                                        @if(in_array('donnees_modifiees', $alertes))
                                                            <span class="badge bg-info text-dark" style="font-size:.55rem" title="Les coordonnées ont été modifiées depuis le don">
                                                                ⚠ coordonnées modifiées
                                                            </span>
                                                        @endif
                                                    </div>
                                                @else
                                                    <a href="{{ route('tiers.dons.recu-fiscal', ['tiers' => $tiers, 'ligne' => $don]) }}"
                                                       class="btn btn-sm btn-primary py-0"
                                                       style="font-size:.65rem"
                                                       target="_blank">
                                                        Télécharger reçu fiscal
                                                    </a>
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

            </div>

            {{-- Footer --}}
            <div class="px-3 pb-3 pt-0 text-end">
                <a href="{{ route('tiers.transactions', $tiers->id) }}"
                   class="small text-decoration-none" style="color:#722281" target="_blank">
                    Toutes les transactions →
                </a>
            </div>
        </div>
    @endif
</div>
