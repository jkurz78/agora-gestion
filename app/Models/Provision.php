<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CasProvision;
use App\Traits\TenantStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use RuntimeException;

/**
 * Un rattachement à l'exercice : la décision de rattacher à un exercice donné un
 * produit ou une charge qui appartient économiquement à l'autre (CCA, PCA, FNP,
 * PAR). Les écritures comptables en sont dérivées, elles ne sont pas stockées ici.
 *
 * Référence : docs/specs/2026-09-30-provisions-rattachement-exercice-design.md
 * (§ 3 pour les décisions citées en commentaire, § 4 pour le modèle de données).
 *  - D2  : le cas est déduit de la classe du compte et de l'adossement, jamais stocké.
 *  - D5  : exactement l'une de `transaction_ligne_id` (adossé : CCA/PCA) ou
 *          `compte_id` (saisie libre : FNP/PAR) est renseignée.
 *  - D12 : tout ou rien — le montant est celui de l'origine, strictement positif.
 */
final class Provision extends TenantModel
{
    use HasFactory, SoftDeletes, TenantStorage;

    protected $fillable = [
        'association_id',
        'exercice',
        'transaction_ligne_id',
        'compte_id',
        'libelle',
        'montant',
        'tiers_id',
        'operation_id',
        'seance',
        'notes',
        'piece_jointe_path',
        'piece_jointe_nom',
        'piece_jointe_mime',
        'saisi_par',
    ];

    protected static function booted(): void
    {
        // Indispensable : TenantModel::booted() pose le scope global fail-closed sur
        // association_id et l'hydratation de association_id à la création.
        parent::booted();

        self::saving(function (self $provision): void {
            $adossee = $provision->transaction_ligne_id !== null;
            $libre = $provision->compte_id !== null;

            // D5 : exactement l'une des deux colonnes. La nullité porte le sens —
            // adossée (CCA/PCA) ou saisie libre (FNP/PAR), jamais les deux, jamais
            // aucune.
            if ($adossee === $libre) {
                throw new InvalidArgumentException(
                    'Rattachement : renseignez transaction_ligne_id (CCA/PCA) OU compte_id (FNP/PAR), jamais les deux ni aucun.'
                );
            }

            // D12 : le rattachement est tout ou rien, et le sens est porté par le
            // cas. Un montant négatif était l'ancienne mécanique du signe.
            if (round((float) $provision->montant, 2) <= 0.0) {
                throw new InvalidArgumentException('Rattachement : le montant est strictement positif.');
            }

            // Le compte de résultat doit être de classe 6 ou 7 : sans quoi cas() ne
            // peut rien déduire et lève plus tard, à la lecture, là où l'erreur
            // casse tout un récapitulatif pour une seule ligne. On ne contrôle que
            // quand l'origine change, pour ne pas bloquer la mise à jour d'une
            // pièce jointe sur un rattachement dont le compte aurait été supprimé.
            if (! $provision->exists || $provision->isDirty(['transaction_ligne_id', 'compte_id'])) {
                $classe = $provision->classeDuCompteResultatEnBase();

                if ($classe === null) {
                    throw new InvalidArgumentException(
                        'Rattachement : le compte de résultat est introuvable (compte ou ligne d\'origine inexistant pour cette association).'
                    );
                }

                if (! in_array($classe, [6, 7], true)) {
                    throw new InvalidArgumentException(
                        "Rattachement : le compte de résultat doit être de classe 6 ou 7 — classe {$classe} reçue."
                    );
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'exercice' => 'integer',
            'transaction_ligne_id' => 'integer',
            'compte_id' => 'integer',
            'tiers_id' => 'integer',
            'operation_id' => 'integer',
            'seance' => 'integer',
            'saisi_par' => 'integer',
        ];
    }

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(TransactionLigne::class, 'transaction_ligne_id');
    }

    public function estAdossee(): bool
    {
        return $this->transaction_ligne_id !== null;
    }

    /**
     * Le cas se déduit, il ne se stocke pas (D2) : voir CasProvision::deduire().
     */
    public function cas(): CasProvision
    {
        $compte = $this->compteResultat()
            ?? throw new RuntimeException("Rattachement #{$this->id} : aucun compte de résultat résolu.");

        return CasProvision::deduire((int) $compte->classe, $this->estAdossee());
    }

    /** Le compte de résultat mouvementé par l'écriture. */
    public function compteResultat(): ?Compte
    {
        return $this->estAdossee() ? $this->ligne?->compte : $this->compte;
    }

    /** L'opération héritée de l'origine, ou saisie en mode libre. */
    public function operationEffectiveId(): ?int
    {
        if (! $this->estAdossee()) {
            return $this->operation_id !== null ? (int) $this->operation_id : null;
        }

        $op = $this->ligne?->operation_id;

        return $op !== null ? (int) $op : null;
    }

    /** La séance héritée de l'origine, ou saisie en mode libre. */
    public function seanceEffective(): ?int
    {
        if (! $this->estAdossee()) {
            return $this->seance !== null ? (int) $this->seance : null;
        }

        $s = $this->ligne?->seance;

        return $s !== null ? (int) $s : null;
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_id');
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Charge ce que compteResultat() lit : la ligne d'origine et son compte pour
     * un rattachement adossé, le compte saisi pour un rattachement libre. Cette
     * liste est le pendant, côté chargement, du choix que compteResultat() fait.
     *
     * @param  Builder<Provision>  $query
     */
    public function scopeAvecCompteResultat(Builder $query): Builder
    {
        return $query->with('ligne.compte', 'compte');
    }

    /**
     * @param  Builder<Provision>  $query
     */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->where('exercice', $exercice);
    }

    public function hasPieceJointe(): bool
    {
        return $this->piece_jointe_path !== null;
    }

    public function pieceJointeFullPath(): ?string
    {
        return $this->piece_jointe_path
            ? $this->storagePath('provisions/'.$this->id.'/'.basename($this->piece_jointe_path))
            : null;
    }

    /**
     * Classe du compte de résultat telle qu'elle est en base, sans passer par les
     * relations déjà chargées (qui peuvent être périmées quand on change l'origine).
     * Nulle quand le compte ou la ligne d'origine est introuvable dans l'association.
     */
    private function classeDuCompteResultatEnBase(): ?int
    {
        $compte = $this->estAdossee()
            ? TransactionLigne::query()->with('compte')->find($this->transaction_ligne_id)?->compte
            : Compte::query()->find($this->compte_id);

        return $compte !== null ? (int) $compte->classe : null;
    }
}
