<?php

// app/Services/TiersService.php
declare(strict_types=1);

namespace App\Services;

use App\Models\Devis;
use App\Models\EmailLog;
use App\Models\Facture;
use App\Models\FacturePartenaireDeposee;
use App\Models\NoteDeFrais;
use App\Models\Participant;
use App\Models\Provision;
use App\Models\Tiers;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class TiersService
{
    /** Mergeable scalar fields exposed by the arbitrage UI. */
    public const MERGE_FIELDS = [
        'type', 'nom', 'prenom', 'entreprise', 'email',
        'telephone', 'adresse_ligne1', 'code_postal', 'ville', 'pays',
    ];

    /** Boolean role flags merged with OR semantics. */
    public const BOOLEAN_FIELDS = ['pour_depenses', 'pour_recettes', 'est_helloasso'];

    public function create(array $data): Tiers
    {
        return DB::transaction(fn (): Tiers => Tiers::create($data));
    }

    public function update(Tiers $tiers, array $data): Tiers
    {
        return DB::transaction(function () use ($tiers, $data): Tiers {
            $tiers->update($data);

            return $tiers->fresh();
        });
    }

    public function delete(Tiers $tiers): void
    {
        DB::transaction(function () use ($tiers): void {
            $tiers->delete();
        });
    }

    /**
     * Count records pointing to $tiers across every dependent table — used to
     * present a recap of merge impact before destructive confirmation.
     *
     * @return array<string, int>
     */
    public function countDependentRecords(Tiers $tiers): array
    {
        $id = $tiers->id;

        return [
            'transactions' => Transaction::where('tiers_id', $id)->count(),
            'factures' => Facture::where('tiers_id', $id)->count(),
            'devis' => Devis::where('tiers_id', $id)->count(),
            'notes_de_frais' => NoteDeFrais::where('tiers_id', $id)->count(),
            'factures_partenaires_deposees' => FacturePartenaireDeposee::where('tiers_id', $id)->count(),
            'provisions' => Provision::where('tiers_id', $id)->count(),
            'email_logs' => EmailLog::where('tiers_id', $id)->count(),
            'participants' => Participant::where('tiers_id', $id)->count(),
            'participants_medecin' => Participant::where('medecin_tiers_id', $id)->count(),
            'participants_therapeute' => Participant::where('therapeute_tiers_id', $id)->count(),
            'participants_refere_par' => Participant::where('refere_par_id', $id)->count(),
        ];
    }

    /**
     * Detect blocking conflicts that would make a merge fail at the SQL level
     * (unique constraint violations) or that the user must resolve manually.
     *
     * @return array<int, array{type: string, label: string, detail: string}>
     */
    public function detectMergeConflicts(Tiers $source, Tiers $target): array
    {
        $conflicts = [];

        // Same operation participant — `unique(tiers_id, operation_id)`
        $sharedOps = Participant::where('tiers_id', $source->id)
            ->whereIn('operation_id', Participant::where('tiers_id', $target->id)->pluck('operation_id'))
            ->with('operation:id,nom')
            ->get();

        if ($sharedOps->isNotEmpty()) {
            $names = $sharedOps->map(fn (Participant $p) => $p->operation?->nom ?? '#'.$p->operation_id)
                ->implode(', ');
            $conflicts[] = [
                'type' => 'participants_dup',
                'label' => 'Participation à la même opération',
                'detail' => "Les deux tiers sont participants des mêmes opérations : {$names}. Supprimez l'une des inscriptions avant de fusionner.",
            ];
        }

        // HelloAsso unique — `(helloasso_order_id, tiers_id)` shared between source/target transactions
        $sourceHelloOrders = Transaction::where('tiers_id', $source->id)
            ->whereNotNull('helloasso_order_id')
            ->pluck('helloasso_order_id');

        if ($sourceHelloOrders->isNotEmpty()) {
            $clash = Transaction::where('tiers_id', $target->id)
                ->whereIn('helloasso_order_id', $sourceHelloOrders)
                ->count();
            if ($clash > 0) {
                $conflicts[] = [
                    'type' => 'helloasso_dup',
                    'label' => 'Transaction HelloAsso dupliquée',
                    'detail' => "Source et cible partagent {$clash} transaction(s) HelloAsso sur le même order_id — situation anormale (la synchro HelloAsso s'appuie sur un identifiant tiers unique).",
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Merge $source into $target: apply chosen field values to $target, reaffect
     * every FK from $source to $target, then delete $source. All in one
     * transaction.
     *
     * @param  array<string, ?string>  $resultData  Chosen field values for MERGE_FIELDS
     * @param  array<string, bool>  $sourceBooleans  Boolean flags from source (OR'd into target)
     * @return array{counts: array<string, int>, snapshot: array<string, mixed>}
     *
     * @throws RuntimeException on cross-association or unresolved blocking conflict
     */
    public function merge(Tiers $source, Tiers $target, array $resultData, array $sourceBooleans): array
    {
        if ($source->id === $target->id) {
            throw new RuntimeException('Source et cible identiques — fusion impossible.');
        }

        if ((int) $source->association_id !== (int) $target->association_id) {
            // Cross-association = grave bug; block and log
            Log::error('tiers.merge.cross_association_attempted', [
                'source_id' => $source->id,
                'target_id' => $target->id,
                'source_association_id' => $source->association_id,
                'target_association_id' => $target->association_id,
            ]);
            throw new RuntimeException('Tentative de fusion inter-association refusée.');
        }

        return DB::transaction(function () use ($source, $target, $resultData, $sourceBooleans): array {
            // Lock both rows in a deterministic order to avoid deadlocks
            $first = min($source->id, $target->id);
            $second = max($source->id, $target->id);
            Tiers::whereIn('id', [$first, $second])->lockForUpdate()->get();

            $sourceLocked = Tiers::findOrFail($source->id);
            $targetLocked = Tiers::findOrFail($target->id);

            $conflicts = $this->detectMergeConflicts($sourceLocked, $targetLocked);
            if (! empty($conflicts)) {
                $labels = collect($conflicts)->pluck('label')->implode(' ; ');
                throw new RuntimeException("Fusion bloquée : {$labels}");
            }

            // Snapshot for audit log
            $snapshot = $sourceLocked->toArray();

            // Apply chosen field values (only those explicitly provided —
            // unspecified fields keep their target value).
            foreach (self::MERGE_FIELDS as $field) {
                if (! array_key_exists($field, $resultData)) {
                    continue;
                }
                $value = $resultData[$field];
                $targetLocked->{$field} = ($value === '' ? null : $value);
            }
            // OR booleans
            foreach (self::BOOLEAN_FIELDS as $field) {
                $targetLocked->{$field} = $targetLocked->{$field} || ($sourceBooleans[$field] ?? false);
            }
            // HelloAsso identity: target wins, fall back to source if target empty
            if (empty($targetLocked->helloasso_nom) && ! empty($sourceLocked->helloasso_nom)) {
                $targetLocked->helloasso_nom = $sourceLocked->helloasso_nom;
                $targetLocked->helloasso_prenom = $sourceLocked->helloasso_prenom;
            }
            $targetLocked->save();

            // Reaffect every FK
            $counts = [
                'transactions' => Transaction::where('tiers_id', $source->id)->update(['tiers_id' => $target->id]),
                'factures' => Facture::where('tiers_id', $source->id)->update(['tiers_id' => $target->id]),
                'devis' => Devis::where('tiers_id', $source->id)->update(['tiers_id' => $target->id]),
                'notes_de_frais' => NoteDeFrais::where('tiers_id', $source->id)->update(['tiers_id' => $target->id]),
                'factures_partenaires_deposees' => FacturePartenaireDeposee::where('tiers_id', $source->id)->update(['tiers_id' => $target->id]),
                'provisions' => Provision::where('tiers_id', $source->id)->update(['tiers_id' => $target->id]),
                'email_logs' => EmailLog::where('tiers_id', $source->id)->update(['tiers_id' => $target->id]),
                'participants' => Participant::where('tiers_id', $source->id)->update(['tiers_id' => $target->id]),
                'participants_medecin' => Participant::where('medecin_tiers_id', $source->id)->update(['medecin_tiers_id' => $target->id]),
                'participants_therapeute' => Participant::where('therapeute_tiers_id', $source->id)->update(['therapeute_tiers_id' => $target->id]),
                'participants_refere_par' => Participant::where('refere_par_id', $source->id)->update(['refere_par_id' => $target->id]),
            ];

            // Delete source
            $sourceLocked->delete();

            Log::info('tiers.merged', [
                'source_id' => $source->id,
                'target_id' => $target->id,
                'association_id' => $target->association_id,
                'counts' => $counts,
                'snapshot' => $snapshot,
            ]);

            return ['counts' => $counts, 'snapshot' => $snapshot];
        });
    }
}
