<div>
    {{-- Alertes session --}}
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- En-tête contextuel --}}
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="fw-semibold">
                @if ($devis->numero)
                    {{ $devis->numero }}
                @else
                    <span class="text-muted">Devis brouillon</span>
                @endif
            </span>
            @if ($devis->statut === \App\Enums\StatutDevis::Brouillon)
                <span class="badge bg-secondary" style="font-size:.75rem"><i class="bi bi-pencil"></i> Brouillon</span>
            @elseif ($devis->statut === \App\Enums\StatutDevis::Valide)
                <span class="badge bg-primary" style="font-size:.75rem"><i class="bi bi-patch-check"></i> Validé</span>
                @if ($this->estExpire())
                    <span class="badge bg-warning text-dark" style="font-size:.75rem"><i class="bi bi-clock-history"></i> Expiré</span>
                @endif
            @elseif ($devis->statut === \App\Enums\StatutDevis::Accepte)
                <span class="badge bg-success" style="font-size:.75rem"><i class="bi bi-check-circle"></i> Accepté</span>
            @elseif ($devis->statut === \App\Enums\StatutDevis::Refuse)
                <span class="badge bg-danger" style="font-size:.75rem"><i class="bi bi-x-circle"></i> Refusé</span>
            @elseif ($devis->statut === \App\Enums\StatutDevis::Annule)
                <span class="badge bg-dark" style="font-size:.75rem"><i class="bi bi-slash-circle"></i> Annulé</span>
            @endif
            <span class="text-muted small ms-2">
                Tiers : <strong>{{ $devis->tiers?->displayName() }}</strong>
                — Exercice {{ $devis->exercice }}
            </span>
        </div>
        <a href="{{ route('devis-libres.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Retour à la liste
        </a>
    </div>

    @if ($this->estVerrouille())
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3" role="alert">
            <i class="bi bi-lock-fill fs-5"></i>
            <div>
                Ce devis est <strong>verrouillé</strong> (statut : {{ $devis->statut->label() }}).
                Il ne peut plus être modifié. Vous pouvez le dupliquer pour créer un nouveau brouillon.
            </div>
        </div>
    @endif

    <div class="row">
        {{-- Colonne principale --}}
        <div class="col-lg-8">

            {{-- En-tête du devis --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Informations du devis</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label for="devis-libelle" class="form-label">Libellé</label>
                            <input type="text"
                                   id="devis-libelle"
                                   class="form-control"
                                   wire:model="libelle"
                                   placeholder="Objet du devis..."
                                   @disabled($this->estVerrouille())
                                   @if($this->estVerrouille()) title="Ce devis est verrouillé" @endif>
                        </div>
                        <div class="col-md-6">
                            <label for="devis-date-emission" class="form-label">Date d'émission</label>
                            <input type="date"
                                   id="devis-date-emission"
                                   class="form-control"
                                   wire:model="dateEmission"
                                   @disabled($this->estVerrouille())
                                   @if($this->estVerrouille()) title="Ce devis est verrouillé" @endif>
                        </div>
                        <div class="col-md-6">
                            <label for="devis-date-validite" class="form-label">
                                Date de validité
                                @if ($this->estExpire())
                                    <span class="badge bg-warning text-dark ms-1">Expiré</span>
                                @endif
                            </label>
                            <input type="date"
                                   id="devis-date-validite"
                                   class="form-control"
                                   wire:model="dateValidite"
                                   @disabled($this->estVerrouille())
                                   @if($this->estVerrouille()) title="Ce devis est verrouillé" @endif>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Lignes du devis --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-list-ul"></i> Lignes du devis</h5>
                </div>
                <div class="card-body p-0">
                    @if ($lignes->isEmpty())
                        <div class="p-3">
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-info-circle"></i> Aucune ligne. Ajoutez des lignes ci-dessous.
                            </div>
                        </div>
                    @else
                        @php $lignesCount = $lignes->count(); @endphp
                        <div class="table-responsive">
                            <table class="table table-striped align-middle mb-0">
                                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                    <tr>
                                        @if (! $this->estVerrouille())
                                            <th style="width:60px;"></th>
                                        @endif
                                        <th>Libellé</th>
                                        <th class="text-end" style="width:110px;">P.U.</th>
                                        <th class="text-end" style="width:80px;">Qté</th>
                                        <th class="text-end" style="width:120px;">Montant</th>
                                        @if (! $this->estVerrouille())
                                            <th style="width:50px;"></th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody style="color:#555">
                                    @foreach ($lignes as $idx => $ligne)
                                        @php
                                            $isTexte = $ligne->type === \App\Enums\TypeLigneDevis::Texte;
                                            $isFirst = $idx === 0;
                                            $isLast  = $idx === $lignesCount - 1;
                                        @endphp
                                        <tr wire:key="ligne-{{ $ligne->id }}">
                                            @if (! $this->estVerrouille())
                                                <td class="text-center p-1">
                                                    <div class="d-flex flex-column gap-1">
                                                        <button wire:click="moveUp({{ $ligne->id }})"
                                                                class="btn btn-sm btn-outline-secondary py-0 px-1"
                                                                title="Monter"
                                                                @disabled($isFirst)>
                                                            <i class="bi bi-chevron-up" style="font-size:.7rem"></i>
                                                        </button>
                                                        <button wire:click="moveDown({{ $ligne->id }})"
                                                                class="btn btn-sm btn-outline-secondary py-0 px-1"
                                                                title="Descendre"
                                                                @disabled($isLast)>
                                                            <i class="bi bi-chevron-down" style="font-size:.7rem"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            @endif
                                            @if ($isTexte)
                                                <td colspan="{{ $this->estVerrouille() ? 3 : 3 }}" class="fst-italic text-muted small py-2">
                                                    <input type="text"
                                                           class="form-control form-control-sm fst-italic"
                                                           value="{{ $ligne->libelle }}"
                                                           @if ($this->estVerrouille())
                                                               disabled
                                                               title="Ce devis est verrouillé"
                                                           @else
                                                               wire:blur="modifierLigneLibelle({{ $ligne->id }}, $event.target.value)"
                                                           @endif>
                                                </td>
                                                <td class="text-end small text-muted">—</td>
                                            @else
                                                <td>
                                                    <input type="text"
                                                           class="form-control form-control-sm"
                                                           value="{{ $ligne->libelle }}"
                                                           @if ($this->estVerrouille())
                                                               disabled
                                                               title="Ce devis est verrouillé"
                                                           @else
                                                               wire:blur="modifierLigneLibelle({{ $ligne->id }}, $event.target.value)"
                                                           @endif>
                                                </td>
                                                <td class="text-end">
                                                    <input type="number"
                                                           class="form-control form-control-sm text-end"
                                                           value="{{ number_format((float) $ligne->prix_unitaire, 2, '.', '') }}"
                                                           step="0.01"
                                                           @if ($this->estVerrouille())
                                                               disabled
                                                               title="Ce devis est verrouillé"
                                                           @else
                                                               wire:blur="modifierLignePrixUnitaire({{ $ligne->id }}, $event.target.value)"
                                                           @endif>
                                                </td>
                                                <td class="text-end">
                                                    <input type="number"
                                                           class="form-control form-control-sm text-end"
                                                           value="{{ number_format((float) $ligne->quantite, 3, '.', '') }}"
                                                           step="0.001"
                                                           @if ($this->estVerrouille())
                                                               disabled
                                                               title="Ce devis est verrouillé"
                                                           @else
                                                               wire:blur="modifierLigneQuantite({{ $ligne->id }}, $event.target.value)"
                                                           @endif>
                                                </td>
                                                <td class="text-end small fw-semibold text-nowrap" data-sort="{{ $ligne->montant }}">
                                                    {{ number_format((float) $ligne->montant, 2, ',', "\u{202F}") }}&nbsp;&euro;
                                                </td>
                                            @endif
                                            @if (! $this->estVerrouille())
                                                <td class="text-center">
                                                    <button wire:click="supprimerLigne({{ $ligne->id }})"
                                                            wire:confirm="Supprimer cette ligne ?"
                                                            class="btn btn-sm btn-outline-danger"
                                                            title="Supprimer la ligne">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td colspan="{{ $this->estVerrouille() ? 4 : 4 }}" class="text-end">Total</td>
                                        <td class="text-end text-nowrap" data-sort="{{ $devis->montant_total }}">
                                            {{ number_format((float) $devis->montant_total, 2, ',', "\u{202F}") }}&nbsp;&euro;
                                        </td>
                                        @if (! $this->estVerrouille())
                                            <td></td>
                                        @endif
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif

                    {{-- Formulaire d'ajout de ligne montant --}}
                    @if (! $this->estVerrouille())
                        <div class="p-3 border-top">
                            <h6 class="small fw-semibold text-muted mb-2">Ajouter une ligne</h6>
                            <div class="row g-2 align-items-end">
                                <div class="col-md-5">
                                    <label class="form-label form-label-sm">Libellé *</label>
                                    <input type="text"
                                           class="form-control form-control-sm"
                                           placeholder="Libellé de la prestation..."
                                           wire:model="nouvelleLigneLibelle"
                                           wire:keydown.enter="ajouterLigne">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label form-label-sm">Prix unitaire</label>
                                    <input type="number"
                                           class="form-control form-control-sm text-end"
                                           placeholder="0.00"
                                           step="0.01"
                                           wire:model="nouvelleLignePrixUnitaire">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label form-label-sm">Quantité</label>
                                    <input type="number"
                                           class="form-control form-control-sm text-end"
                                           placeholder="1"
                                           step="0.001"
                                           wire:model="nouvelleLigneQuantite">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label form-label-sm">Sous-catégorie</label>
                                    <select class="form-select form-select-sm" wire:model="nouvelleLigneSousCategorieId">
                                        <option value="">— Aucune —</option>
                                        @foreach ($sousCategoriesDisponibles as $sc)
                                            <option value="{{ $sc->id }}">{{ $sc->nom }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-1">
                                    <button wire:click="ajouterLigne"
                                            class="btn btn-sm btn-primary w-100"
                                            title="Ajouter la ligne">
                                        <i class="bi bi-plus-lg"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        {{-- Formulaire d'ajout de ligne texte --}}
                        <div class="p-3 border-top bg-light">
                            <h6 class="small fw-semibold text-muted mb-2">Ajouter une ligne texte (commentaire / titre de section)</h6>
                            <div class="row g-2 align-items-end">
                                <div class="col">
                                    <input type="text"
                                           class="form-control form-control-sm fst-italic"
                                           placeholder="Ex : Section A — Prestations de formation..."
                                           wire:model="nouveauLigneTexte"
                                           wire:keydown.enter="ajouterLigneTexte">
                                </div>
                                <div class="col-auto">
                                    <button wire:click="ajouterLigneTexte"
                                            class="btn btn-sm btn-outline-secondary"
                                            title="Ajouter une ligne texte">
                                        <i class="bi bi-text-left"></i> Ajouter ligne texte
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Traces (pour statuts tracés) --}}
            @if ($devis->statut === \App\Enums\StatutDevis::Accepte && $devis->accepteParUser)
                <div class="card mb-4 border-success">
                    <div class="card-body text-success small">
                        <i class="bi bi-check-circle-fill me-1"></i>
                        Accepté par <strong>{{ $devis->accepteParUser->name }}</strong>
                        le {{ $devis->accepte_le?->format('d/m/Y à H:i') }}
                    </div>
                </div>
            @endif

            @if ($devis->statut === \App\Enums\StatutDevis::Refuse && $devis->refuseParUser)
                <div class="card mb-4 border-danger">
                    <div class="card-body text-danger small">
                        <i class="bi bi-x-circle-fill me-1"></i>
                        Refusé par <strong>{{ $devis->refuseParUser->name }}</strong>
                        le {{ $devis->refuse_le?->format('d/m/Y à H:i') }}
                    </div>
                </div>
            @endif

            @if ($devis->statut === \App\Enums\StatutDevis::Annule && $devis->annuleParUser)
                <div class="card mb-4 border-dark">
                    <div class="card-body text-secondary small">
                        <i class="bi bi-slash-circle-fill me-1"></i>
                        Annulé par <strong>{{ $devis->annuleParUser->name }}</strong>
                        le {{ $devis->annule_le?->format('d/m/Y à H:i') }}
                    </div>
                </div>
            @endif
        </div>

        {{-- Colonne actions --}}
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-lightning"></i> Actions</h5>
                </div>
                <div class="card-body d-grid gap-2">

                    {{-- Enregistrer (brouillon / envoyé seulement) --}}
                    @if (! $this->estVerrouille())
                        <button wire:click="sauvegarder" class="btn btn-primary">
                            <i class="bi bi-save"></i> Enregistrer
                        </button>
                        <hr class="my-1">
                    @endif

                    {{-- Valider (brouillon seulement) --}}
                    @if ($devis->statut === \App\Enums\StatutDevis::Brouillon)
                        @php $peutEnvoyer = $this->peutEtreEnvoye(); @endphp
                        <button wire:click="marquerValide"
                                wire:confirm="Valider ce devis ? Un numéro lui sera attribué."
                                class="btn btn-success"
                                @disabled(! $peutEnvoyer)
                                title="{{ $peutEnvoyer ? 'Valider le devis' : 'Ajoutez au moins une ligne avec un montant avant de valider le devis.' }}">
                            <i class="bi bi-send"></i> Valider
                        </button>
                    @endif

                    {{-- Marquer accepté (validé seulement) --}}
                    @if ($devis->statut === \App\Enums\StatutDevis::Valide)
                        <button wire:click="marquerAccepte"
                                wire:confirm="Marquer ce devis comme accepté ?"
                                class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Marquer accepté
                        </button>

                        <button wire:click="marquerRefuse"
                                wire:confirm="Marquer ce devis comme refusé ?"
                                class="btn btn-outline-danger">
                            <i class="bi bi-x-circle"></i> Marquer refusé
                        </button>
                    @endif

                    {{-- Annuler (tout statut sauf annulé) --}}
                    @if ($devis->statut !== \App\Enums\StatutDevis::Annule)
                        <button wire:click="annuler"
                                wire:confirm="Annuler ce devis ? Cette action est irréversible."
                                class="btn btn-outline-secondary">
                            <i class="bi bi-slash-circle"></i> Annuler le devis
                        </button>
                    @endif

                    <hr class="my-1">

                    {{-- PDF — ouvre dans un nouvel onglet --}}
                    @php $peutPdf = $this->peutEtreEnvoye(); @endphp
                    @if ($peutPdf)
                        <a href="{{ route('devis-libres.pdf', $devis) }}"
                           target="_blank"
                           class="btn btn-outline-secondary">
                            <i class="bi bi-file-earmark-pdf"></i> Exporter PDF
                        </a>
                    @else
                        <button class="btn btn-outline-secondary"
                                disabled
                                title="Ajoutez au moins une ligne avec un montant pour générer un PDF.">
                            <i class="bi bi-file-earmark-pdf"></i> Exporter PDF
                        </button>
                    @endif

                    {{-- Email (validé/accepté/refusé uniquement — pas brouillon) --}}
                    @php $peutEmail = ($devis->statut !== \App\Enums\StatutDevis::Brouillon) && $peutPdf; @endphp
                    <button wire:click="ouvrirModaleEmail"
                            class="btn btn-outline-primary"
                            @disabled(! $peutEmail)
                            title="{{ $peutEmail ? 'Envoyer par email' : 'Le devis doit être validé et avoir au moins une ligne pour être transmis par email.' }}">
                        <i class="bi bi-envelope"></i> Envoyer par email
                    </button>

                    <hr class="my-1">

                    {{-- Dupliquer (masqué en brouillon, disponible pour les autres statuts) --}}
                    @if ($devis->statut !== \App\Enums\StatutDevis::Brouillon)
                        <button wire:click="dupliquer"
                                wire:confirm="Dupliquer ce devis ? Un nouveau brouillon sera créé."
                                class="btn btn-outline-info">
                            <i class="bi bi-copy"></i> Dupliquer
                        </button>
                    @endif

                    {{-- Transformer en facture (uniquement si statut Accepté) --}}
                    @if ($devis->statut === \App\Enums\StatutDevis::Accepte)
                        @php $dejaTransforme = $devis->aDejaUneFacture(); @endphp
                        @if ($dejaTransforme)
                            <button class="btn btn-outline-success"
                                    disabled
                                    title="Une facture issue de ce devis existe déjà">
                                <i class="bi bi-file-earmark-arrow-up"></i> Transformer en facture
                            </button>
                        @else
                            <button wire:click="transformerEnFacture"
                                    wire:confirm="Transformer ce devis en facture brouillon ?"
                                    class="btn btn-outline-success">
                                <i class="bi bi-file-earmark-arrow-up"></i> Transformer en facture
                            </button>
                        @endif
                    @endif
                </div>
            </div>

            {{-- Récapitulatif --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-cash-stack"></i> Récapitulatif</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-12">
                            <div class="text-muted small">Montant total</div>
                            <div class="fs-4 fw-bold">
                                {{ number_format((float) $devis->montant_total, 2, ',', "\u{202F}") }}&nbsp;&euro;
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="small text-muted">
                        <div>Émis le : {{ $devis->date_emission->format('d/m/Y') }}</div>
                        <div class="{{ $this->estExpire() ? 'text-danger fw-semibold' : '' }}">
                            Valide jusqu'au : {{ $devis->date_validite->format('d/m/Y') }}
                            @if ($this->estExpire())
                                <span class="badge bg-warning text-dark ms-1">Expiré</span>
                            @endif
                        </div>
                        @if ($devis->numero)
                            <div>Référence : <strong>{{ $devis->numero }}</strong></div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════════
         Modale d'envoi par email
         ════════════════════════════════════════════════════════════════════ --}}
    @if ($showEnvoyerEmailModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);" role="dialog">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-envelope"></i> Envoyer par email</h5>
                        <button type="button" class="btn-close" wire:click="$set('showEnvoyerEmailModal', false)"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Destinataire</label>
                            <input type="text"
                                   class="form-control"
                                   value="{{ $devis->tiers?->email ?? '—' }}"
                                   disabled>
                        </div>
                        <div class="mb-3">
                            <label for="email-sujet" class="form-label">Sujet *</label>
                            <input type="text"
                                   id="email-sujet"
                                   class="form-control"
                                   wire:model="emailSujet"
                                   placeholder="Objet de l'email...">
                        </div>
                        <div class="mb-3">
                            <label for="email-corps" class="form-label">Message *</label>
                            <textarea id="email-corps"
                                      class="form-control"
                                      rows="6"
                                      wire:model="emailCorps"
                                      placeholder="Rédigez votre message..."></textarea>
                        </div>
                        <div class="alert alert-info small mb-0">
                            <i class="bi bi-paperclip"></i>
                            Le PDF du devis sera joint automatiquement à l'email.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button"
                                class="btn btn-secondary"
                                wire:click="$set('showEnvoyerEmailModal', false)">
                            Annuler
                        </button>
                        <button type="button"
                                class="btn btn-primary"
                                wire:click="envoyerEmail">
                            <i class="bi bi-send"></i> Envoyer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modale de confirmation Bootstrap (remplace window.confirm) --}}
    @include('partials.confirm-modal')
</div>
