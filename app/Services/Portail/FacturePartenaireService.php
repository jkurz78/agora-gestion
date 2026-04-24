<?php

declare(strict_types=1);

namespace App\Services\Portail;

use App\Enums\StatutFactureDeposee;
use App\Events\Portail\FactureDeposeeComptabilisee;
use App\Events\Portail\FactureDeposeeRejetee;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class FacturePartenaireService
{
    public function submit(Tiers $tiers, array $data, UploadedFile $pdf): FacturePartenaireDeposee
    {
        return DB::transaction(function () use ($tiers, $data, $pdf): FacturePartenaireDeposee {
            $numero = trim((string) $data['numero_facture']);
            $date = Carbon::parse($data['date_facture']);

            $pdfPath = $this->buildPdfPath($tiers, $numero, $date);

            $stored = Storage::disk('local')->putFileAs(dirname($pdfPath), $pdf, basename($pdfPath));
            if ($stored === false) {
                throw new \RuntimeException("Échec de l'écriture du fichier PDF : {$pdfPath}");
            }

            $depot = FacturePartenaireDeposee::create([
                'association_id' => $tiers->association_id,
                'tiers_id' => $tiers->id,
                'date_facture' => $date->toDateString(),
                'numero_facture' => $numero,
                'pdf_path' => $pdfPath,
                'pdf_taille' => $pdf->getSize(),
                'statut' => StatutFactureDeposee::Soumise,
            ]);

            Log::info('portail.facture-partenaire.deposee', [
                'depot_id' => $depot->id,
                'tiers_id' => $tiers->id,
                'numero' => $depot->numero_facture,
            ]);

            return $depot;
        });
    }

    /**
     * Hard-delete a depot (BDD + fichier physique).
     *
     * Ordre choisi (Option A) : delete BDD en premier, puis fichier.
     * Si le fichier reste orphelin après un crash entre les deux, c'est
     * un problème mineur (nettoyable par cron). L'inverse (Option B) laisserait
     * un enregistrement BDD orphelin sans fichier, état plus difficile à corriger.
     *
     * Guards :
     *  – cross-tiers   : DomainException si $depot->tiers_id ≠ $tiers->id.
     *  – statut        : DomainException si statut ∉ {Soumise, Rejetee} (déjà traité).
     */
    public function oublier(FacturePartenaireDeposee $depot, Tiers $tiers): void
    {
        if ((int) $depot->tiers_id !== (int) $tiers->id) {
            throw new \DomainException('Ce dépôt n\'appartient pas au tiers fourni.');
        }

        $statutsAutorisés = [StatutFactureDeposee::Soumise, StatutFactureDeposee::Rejetee];
        if (! in_array($depot->statut, $statutsAutorisés, strict: true)) {
            throw new \DomainException('Seul un dépôt au statut « soumise » ou « rejetée » peut être supprimé.');
        }

        DB::transaction(function () use ($depot, $tiers): void {
            $pdfPath = $depot->pdf_path; // capture before delete
            $depotId = $depot->id;       // capture before delete

            $depot->delete(); // hard delete (no SoftDeletes on this model)

            Storage::disk('local')->delete($pdfPath); // best-effort; silent if file absent

            Log::info('portail.facture-partenaire.oubliee', [
                'depot_id' => $depotId,
                'tiers_id' => $tiers->id,
            ]);
        });
    }

    /**
     * Rejette un dépôt de facture partenaire en renseignant le motif de rejet.
     *
     * - Le PDF est conservé sur le disk (piste d'audit + redépôt potentiel).
     * - Le statut passe à Rejetee ; motif_rejet est renseigné.
     * - Émet l'event FactureDeposeeRejetee.
     *
     * Guards :
     *  – motif         : DomainException si motif vide (après trim).
     *  – statut depot  : DomainException si pas Soumise (déjà traité ou déjà rejeté).
     */
    public function rejeter(FacturePartenaireDeposee $depot, string $motif): void
    {
        if (trim($motif) === '') {
            throw new \DomainException('Le motif de rejet ne peut pas être vide.');
        }

        if ($depot->statut !== StatutFactureDeposee::Soumise) {
            throw new \DomainException('Seul un dépôt au statut « soumise » peut être rejeté.');
        }

        DB::transaction(function () use ($depot, $motif): void {
            $depot->statut = StatutFactureDeposee::Rejetee;
            $depot->motif_rejet = trim($motif);
            $depot->save();

            Event::dispatch(new FactureDeposeeRejetee($depot));

            Log::info('portail.facture-partenaire.rejetee', [
                'depot_id' => $depot->id,
                'tiers_id' => $depot->tiers_id,
                'motif' => $depot->motif_rejet,
            ]);
        });
    }

    /**
     * Comptabilise un dépôt de facture partenaire en le liant à une Transaction.
     *
     * - Déplace le PDF de factures-deposees/ vers transactions/{id}/.
     * - Renseigne piece_jointe_path/nom/mime sur la Transaction.
     * - Passe le dépôt au statut Traitee.
     * - Émet l'event FactureDeposeeComptabilisee.
     *
     * Guards :
     *  – cross-tenant  : DomainException si association_id différent.
     *  – statut depot  : DomainException si pas Soumise.
     *  – piece jointe  : DomainException si transaction a déjà une pièce jointe.
     */
    public function comptabiliser(FacturePartenaireDeposee $depot, Transaction $transaction): void
    {
        // Guard: same-tenant
        if ((int) $depot->association_id !== (int) $transaction->association_id) {
            throw new \DomainException('Le dépôt et la transaction n\'appartiennent pas à la même association.');
        }

        // Guard: depot must be Soumise
        if ($depot->statut !== StatutFactureDeposee::Soumise) {
            throw new \DomainException('Seul un dépôt au statut « soumise » peut être comptabilisé.');
        }

        // Guard: transaction must not already have a piece jointe
        if ($transaction->hasPieceJointe()) {
            throw new \DomainException('La transaction possède déjà une pièce jointe ; comptabilisation refusée.');
        }

        DB::transaction(function () use ($depot, $transaction): void {
            $basename = basename($depot->pdf_path);
            $newFullPath = $transaction->storagePath('transactions/'.$transaction->id.'/'.$basename);

            $moved = Storage::disk('local')->move($depot->pdf_path, $newFullPath);
            if (! $moved) {
                throw new \RuntimeException("Échec du déplacement du PDF vers {$newFullPath}.");
            }

            // Update transaction with the new piece jointe (basename only — matches existing pattern)
            $transaction->piece_jointe_path = $basename;
            $transaction->piece_jointe_nom = sprintf(
                'Facture %s du %s.pdf',
                $depot->numero_facture,
                $depot->date_facture->format('d/m/Y'),
            );
            $transaction->piece_jointe_mime = 'application/pdf';
            $transaction->save();

            // Update depot
            $depot->statut = StatutFactureDeposee::Traitee;
            $depot->transaction_id = $transaction->id;
            $depot->traitee_at = now();
            $depot->save();

            Event::dispatch(new FactureDeposeeComptabilisee($depot));

            Log::info('portail.facture-partenaire.comptabilisee', [
                'depot_id' => $depot->id,
                'transaction_id' => $transaction->id,
            ]);
        });
    }

    private function buildPdfPath(Tiers $tiers, string $numero, Carbon $date): string
    {
        $assocId = $tiers->association_id;
        $year = $date->format('Y');
        $month = $date->format('m');
        $datePrefix = $date->format('Y-m-d');
        $numeroSlug = Str::substr(Str::slug($numero), 0, 30);
        $rand6 = Str::lower(Str::random(6));

        return "associations/{$assocId}/factures-deposees/{$year}/{$month}/{$datePrefix}-{$numeroSlug}-{$rand6}.pdf";
    }
}
