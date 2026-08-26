{{-- resources/views/livewire/parametres/facturation-form.blade.php --}}
<div>
    <x-flash-message />

    <p class="text-muted small mb-4">
        Ces informations apparaissent sur les <strong>factures</strong> émises par l'application (pied de page, mentions légales, coordonnées bancaires).
    </p>

    <form wire:submit="save">
        <div class="mb-3">
            <label class="form-label">Conditions de règlement</label>
            <textarea class="form-control @error('facture_conditions_reglement') is-invalid @enderror"
                      wire:model="facture_conditions_reglement" rows="2"
                      placeholder="Ex : Payable à réception"></textarea>
            @error('facture_conditions_reglement') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <label class="form-label">Mentions légales</label>
            <textarea class="form-control @error('facture_mentions_legales') is-invalid @enderror"
                      wire:model="facture_mentions_legales" rows="3"
                      placeholder="Ex : TVA non applicable, art. 261-7-1° du CGI"></textarea>
            @error('facture_mentions_legales') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <label class="form-label">Mentions pénalités B2B</label>
            <textarea class="form-control @error('facture_mentions_penalites') is-invalid @enderror"
                      wire:model="facture_mentions_penalites" rows="3"
                      placeholder="Pénalités de retard, indemnité forfaitaire de recouvrement…"></textarea>
            @error('facture_mentions_penalites') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mb-4">
            <label class="form-label">Compte bancaire par défaut</label>
            <select class="form-select @error('facture_compte_bancaire_id') is-invalid @enderror"
                    wire:model="facture_compte_bancaire_id">
                <option value="">— Aucun —</option>
                @foreach($comptesBancaires as $compte)
                    <option value="{{ $compte->id }}">{{ $compte->nom }}@if($compte->iban) — {{ $compte->iban }}@endif</option>
                @endforeach
            </select>
            @error('facture_compte_bancaire_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
            <span wire:loading.remove><i class="bi bi-floppy"></i> Enregistrer</span>
            <span wire:loading>Enregistrement…</span>
        </button>
    </form>
</div>
