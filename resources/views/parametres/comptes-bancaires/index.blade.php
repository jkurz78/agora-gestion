<x-app-layout>
    <h1 class="mb-4">Comptes bancaires</h1>

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

    <div class="mb-3">
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse"
                data-bs-target="#addCompteForm">
            <i class="bi bi-plus-lg"></i> Ajouter un compte bancaire
        </button>
    </div>

    <div class="collapse mb-3" id="addCompteForm">
        <div class="card card-body">
            <form action="{{ route('parametres.comptes-bancaires.store') }}" method="POST" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label for="cb_nom" class="form-label">Nom</label>
                    <input type="text" name="nom" id="cb_nom" class="form-control" required maxlength="150">
                </div>
                <div class="col-md-3">
                    <label for="cb_iban" class="form-label">IBAN</label>
                    <input type="text" name="iban" id="cb_iban" class="form-control" maxlength="34">
                </div>
                <div class="col-md-2">
                    <label for="cb_solde" class="form-label">Solde initial</label>
                    <input type="number" name="solde_initial" id="cb_solde" class="form-control" required step="0.01">
                </div>
                <div class="col-md-2">
                    <label for="cb_date" class="form-label">Date solde</label>
                    <x-date-input name="date_solde_initial" value="" required />
                </div>
                <div class="col-md-2">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="actif_recettes_depenses"
                               id="cb_actif_rd" value="1" checked>
                        <label class="form-check-label" for="cb_actif_rd">Rec./Dép.</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="actif_dons_cotisations"
                               id="cb_actif_dc" value="1" checked>
                        <label class="form-check-label" for="cb_actif_dc">Dons/Cot.</label>
                    </div>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-success w-100">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <table class="table table-striped table-hover">
        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
            <tr>
                <th>Nom</th>
                <th>IBAN</th>
                <th>Solde initial</th>
                <th>Date solde</th>
                <th class="text-center">Rec./Dép.</th>
                <th class="text-center">Dons/Cot.</th>
                <th style="width: 130px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($comptesBancaires as $compte)
                <tr>
                    <td>{{ $compte->nom }}</td>
                    <td>{{ $compte->iban ?? '—' }}</td>
                    <td>{{ number_format((float) $compte->solde_initial, 2, ',', ' ') }} &euro;</td>
                    <td>{{ $compte->date_solde_initial->format('d/m/Y') }}</td>
                    <td class="text-center">
                        @if ($compte->actif_recettes_depenses)
                            <i class="bi bi-check-circle-fill text-success"></i>
                        @else
                            <i class="bi bi-x-circle text-secondary"></i>
                        @endif
                    </td>
                    <td class="text-center">
                        @if ($compte->actif_dons_cotisations)
                            <i class="bi bi-check-circle-fill text-success"></i>
                        @else
                            <i class="bi bi-x-circle text-secondary"></i>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('comptes-bancaires.transactions', $compte) }}"
                           class="btn btn-sm btn-outline-secondary"
                           data-bs-toggle="tooltip" title="Voir les transactions">
                            <i class="bi bi-list-ul"></i>
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-bs-toggle="modal"
                                data-bs-target="#editCompteModal"
                                data-update-url="{{ route('parametres.comptes-bancaires.update', $compte) }}"
                                data-nom="{{ $compte->nom }}"
                                data-iban="{{ $compte->iban ?? '' }}"
                                data-solde="{{ $compte->solde_initial }}"
                                data-date="{{ $compte->date_solde_initial->format('Y-m-d') }}"
                                data-actif-rd="{{ $compte->actif_recettes_depenses ? '1' : '0' }}"
                                data-actif-dc="{{ $compte->actif_dons_cotisations ? '1' : '0' }}"
                                onclick="fillEditModal(this)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form action="{{ route('parametres.comptes-bancaires.destroy', $compte) }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Supprimer ce compte bancaire ?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-muted">Aucun compte bancaire enregistré.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

{{-- Modal édition compte bancaire --}}
<div class="modal fade" id="editCompteModal" tabindex="-1" aria-labelledby="editCompteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="editCompteForm" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title" id="editCompteModalLabel">Modifier le compte bancaire</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label for="edit_nom" class="form-label">Nom <span class="text-danger">*</span></label>
                        <input type="text" name="nom" id="edit_nom" class="form-control" required maxlength="150">
                    </div>
                    <div class="col-12">
                        <label for="edit_iban" class="form-label">IBAN</label>
                        <input type="text" name="iban" id="edit_iban" class="form-control" maxlength="34">
                    </div>
                    <div class="col-md-6">
                        <label for="edit_solde" class="form-label">Solde initial <span class="text-danger">*</span></label>
                        <input type="number" name="solde_initial" id="edit_solde" class="form-control" required step="0.01">
                    </div>
                    <div class="col-md-6">
                        <label for="edit_date" class="form-label">Date solde <span class="text-danger">*</span></label>
                        <x-date-input name="date_solde_initial" id="edit_date" value="" required />
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="actif_recettes_depenses"
                                   id="edit_actif_rd" value="1">
                            <label class="form-check-label" for="edit_actif_rd">
                                Utilisable pour les recettes et dépenses
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="actif_dons_cotisations"
                                   id="edit_actif_dc" value="1">
                            <label class="form-check-label" for="edit_actif_dc">
                                Utilisable pour les dons et cotisations
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function fillEditModal(btn) {
    const form = document.getElementById('editCompteForm');
    form.action = btn.dataset.updateUrl;
    document.getElementById('edit_nom').value = btn.dataset.nom;
    document.getElementById('edit_iban').value = btn.dataset.iban;
    document.getElementById('edit_solde').value = btn.dataset.solde;
    const editDateWrapper = document.getElementById('edit_date');
    const editDateInput = editDateWrapper ? editDateWrapper.querySelector('input[type=text]') : null;
    if (editDateInput && editDateInput._flatpickr) {
        editDateInput._flatpickr.setDate(btn.dataset.date, true, 'Y-m-d');
    }
    document.getElementById('edit_actif_rd').checked = btn.dataset.actifRd === '1';
    document.getElementById('edit_actif_dc').checked = btn.dataset.actifDc === '1';
}
</script>
</x-app-layout>
