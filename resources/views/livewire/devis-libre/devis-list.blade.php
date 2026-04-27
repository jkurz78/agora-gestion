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

    {{-- Titre + bouton Nouveau devis --}}
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h4 class="mb-0">Devis libres</h4>
        <button wire:click="creerDevis" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> Nouveau devis
        </button>
    </div>

    {{-- Filtres --}}
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        {{-- Filtre statut --}}
        <select wire:model.live="filtreStatut" class="form-select form-select-sm" style="max-width:200px;">
            <option value="">Tous (sauf annulés)</option>
            <option value="brouillon">Brouillon</option>
            <option value="envoye">Envoyé</option>
            <option value="accepte">Accepté</option>
            <option value="refuse">Refusé</option>
            <option value="annule">Annulé</option>
        </select>

        {{-- Filtre tiers --}}
        <select wire:model.live="filtreTiersId" class="form-select form-select-sm" style="max-width:220px;">
            <option value="">Tous les tiers</option>
            @foreach (\App\Models\Tiers::orderBy('nom')->get() as $t)
                <option value="{{ $t->id }}">{{ $t->displayName() }}</option>
            @endforeach
        </select>

        {{-- Filtre exercice --}}
        <input type="number"
               wire:model.live="filtreExercice"
               class="form-control form-control-sm"
               style="max-width:100px;"
               placeholder="Exercice">

        {{-- Recherche --}}
        <input type="text"
               wire:model.live.debounce.300ms="search"
               class="form-control form-control-sm"
               style="max-width:220px;"
               placeholder="Rechercher…">
    </div>

    {{-- Table --}}
    @if ($devis->isEmpty())
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Aucun devis pour ces critères.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Numéro</th>
                        <th>Date émission</th>
                        <th>Tiers</th>
                        <th>Libellé</th>
                        <th class="text-end">Montant total</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody style="color:#555">
                    @foreach ($devis as $d)
                        @php $expired = $this->expire($d); @endphp
                        <tr wire:key="devis-{{ $d->id }}">
                            <td class="small" data-sort="{{ $d->numero ?? '' }}">
                                @if ($d->numero)
                                    {{ $d->numero }}
                                @else
                                    <span class="text-muted fst-italic">—</span>
                                @endif
                            </td>
                            <td class="small text-nowrap" data-sort="{{ $d->date_emission->format('Y-m-d') }}">
                                {{ $d->date_emission->format('d/m/Y') }}
                            </td>
                            <td class="small">
                                {{ $d->tiers?->displayName() }}
                            </td>
                            <td class="small">{{ $d->libelle }}</td>
                            <td class="text-end small text-nowrap fw-semibold" data-sort="{{ $d->montant_total }}">
                                {{ number_format((float) $d->montant_total, 2, ',', "\u{202F}") }}&nbsp;&euro;
                            </td>
                            <td>
                                @if ($d->statut === \App\Enums\StatutDevis::Brouillon)
                                    <span class="badge bg-secondary" style="font-size:.7rem">
                                        <i class="bi bi-pencil"></i> Brouillon
                                    </span>
                                @elseif ($d->statut === \App\Enums\StatutDevis::Envoye)
                                    <span class="badge bg-primary" style="font-size:.7rem">
                                        <i class="bi bi-send"></i> Envoyé
                                    </span>
                                    @if ($expired)
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">
                                            <i class="bi bi-clock-history"></i> Expiré
                                        </span>
                                    @endif
                                @elseif ($d->statut === \App\Enums\StatutDevis::Accepte)
                                    <span class="badge bg-success" style="font-size:.7rem">
                                        <i class="bi bi-check-circle"></i> Accepté
                                    </span>
                                @elseif ($d->statut === \App\Enums\StatutDevis::Refuse)
                                    <span class="badge bg-danger" style="font-size:.7rem">
                                        <i class="bi bi-x-circle"></i> Refusé
                                    </span>
                                @elseif ($d->statut === \App\Enums\StatutDevis::Annule)
                                    <span class="badge bg-dark" style="font-size:.7rem">
                                        <i class="bi bi-slash-circle"></i> Annulé
                                    </span>
                                @endif
                            </td>
                            <td>
                                {{-- TODO(step-11): lien vers devis-libres.show une fois DevisEdit implémenté --}}
                                <a href="#" class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $devis->links() }}
        </div>
    @endif
</div>
