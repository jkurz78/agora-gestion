@php
    $euros = fn (int $centimes): string => number_format($centimes / 100, 2, ',', ' ');
@endphp

<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Dotations aux amortissements</h4>
        <div class="d-flex gap-2 align-items-center">
            <select class="form-select form-select-sm" wire:model.live="exercice" style="width:auto">
                @foreach ($exercicesDisponibles as $annee)
                    <option value="{{ $annee }}">{{ $exerciceService->label($annee) }}</option>
                @endforeach
            </select>
            <button type="button" class="btn btn-primary btn-sm" wire:click="genererTout">
                <i class="bi bi-play-fill me-1"></i> Générer les dotations manquantes
            </button>
        </div>
    </div>

    @if ($flashMessage !== '')
        <div class="alert alert-{{ $flashType }} alert-dismissible fade show">
            {{ $flashMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if ($lignes->isEmpty())
        <div class="alert alert-info">Aucune immobilisation au registre.</div>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Numéro</th>
                        <th>Libellé</th>
                        <th class="text-end">Mois</th>
                        <th class="text-end">Comptabilisé</th>
                        <th class="text-end">Recalculé</th>
                        <th class="text-end">Écart</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lignes as $ligne)
                        @php
                            $ecart = $ligne->montantRecalculeCentimes - $ligne->montantComptabiliseCentimes;
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('immobilisations.show', $ligne->immobilisation) }}">
                                    {{ $ligne->immobilisation->numero }}
                                </a>
                            </td>
                            <td>{{ $ligne->immobilisation->libelle }}</td>
                            <td class="text-end">{{ $ligne->moisEcoules }}</td>
                            <td class="text-end">
                                {{ $ligne->dejaComptabilisee ? $euros($ligne->montantComptabiliseCentimes).' €' : '—' }}
                            </td>
                            <td class="text-end">{{ $euros($ligne->montantRecalculeCentimes) }} €</td>
                            <td class="text-end {{ $ligne->enEcart() ? 'text-danger fw-semibold' : '' }}">
                                @if ($ligne->enEcart())
                                    Écart {{ $euros($ecart) }} €
                                @else
                                    —
                                @endif
                            </td>
                            <td class="d-flex gap-2 align-items-center">
                                @if ($ligne->enEcart())
                                    <button type="button" class="btn btn-warning btn-sm"
                                            wire:click="recalculer({{ $ligne->immobilisation->id }})"
                                            wire:confirm="La dotation actuelle sera remplacée. Si elle avait été ventilée sur des opérations, cette ventilation sera perdue et devra être refaite. Continuer ?">
                                        Recalculer
                                    </button>
                                @elseif ($ligne->aGenerer())
                                    <span class="badge bg-primary">À générer</span>
                                @elseif (! $ligne->dejaComptabilisee)
                                    <span class="text-muted small">Rien à doter</span>
                                @endif

                                @if ($ligne->dejaComptabilisee)
                                    @unless ($ligne->enEcart())
                                        <span class="badge bg-success">À jour</span>
                                    @endunless
                                    <button type="button" class="btn btn-outline-danger btn-sm"
                                            wire:click="annulerDotation({{ $ligne->immobilisation->id }})"
                                            wire:confirm="L'écriture comptable de cette dotation sera supprimée. Si elle avait été ventilée sur des opérations, cette ventilation sera perdue et devra être refaite. Continuer ?">
                                        Annuler
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
