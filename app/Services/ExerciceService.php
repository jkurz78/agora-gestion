<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrigineANouveau;
use App\Enums\StatutExercice;
use App\Enums\TypeActionExercice;
use App\Exceptions\Compta\EtapeComptaRequiseException;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Exercice;
use App\Models\ExerciceAction;
use App\Models\User;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\ANouveau\ANouveauService;
use App\Services\Compta\EtatComptaResolver;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class ExerciceService
{
    private function moisDebut(): int
    {
        return TenantContext::current()?->exercice_mois_debut ?? 9;
    }

    /**
     * Return the current exercice year.
     *
     * Résolution en cascade : session (le choix de la requête en cours) → pivot
     * association_user (le choix mémorisé pour cet utilisateur sur cette
     * association) → défaut calculé depuis la date du jour.
     *
     * La session expire (SESSION_LIFETIME) sans que la clé survive : c'est
     * précisément pour ça que le pivot existe, il porte le choix au-delà d'une
     * session.
     */
    public function current(): int
    {
        if (session()->has('exercice_actif')) {
            return (int) session('exercice_actif');
        }

        $exercice = $this->exerciceMemorise() ?? $this->exerciceParDefaut();

        // On grave le résultat dans la session dès la première résolution :
        // current() est appelé des dizaines de fois par requête (51 sites
        // d'appel), et sans ça chaque appel repaierait la lecture du pivot et la
        // requête du défaut. Réservé au contexte authentifié — un job ou une
        // commande artisan n'a pas de session à peupler.
        if (Auth::hasUser()) {
            session(['exercice_actif' => $exercice]);
        }

        return $exercice;
    }

    /**
     * Lit le choix mémorisé sur le pivot association_user pour l'utilisateur
     * authentifié et l'association courante.
     *
     * Ne lève jamais : appelée par current(), qui tourne aussi hors requête
     * HTTP (jobs, commandes artisan). Rend null dès qu'un des trois
     * prérequis manque — pas d'utilisateur, pas de tenant booté, pas de
     * valeur enregistrée — pour laisser current() retomber sur le défaut
     * calculé.
     */
    private function exerciceMemorise(): ?int
    {
        if (! Auth::hasUser() || ! TenantContext::hasBooted()) {
            return null;
        }

        $assoId = TenantContext::currentId();

        // wherePivot() + whereNull(revoked_at) : même motif que
        // ResolveTenant, EnsureTenantAccess et ForceWizardIfNotCompleted — un
        // membre révoqué ne doit pas piloter l'exercice affiché depuis un
        // choix mémorisé avant sa révocation.
        $valeur = Auth::user()
            ?->associations()
            ->wherePivot('association_id', $assoId)
            ->whereNull('association_user.revoked_at')
            ->first()
            ?->pivot
            ?->exercice_actif;

        return $valeur !== null ? (int) $valeur : null;
    }

    /**
     * Défaut calculé quand rien n'a été mémorisé : la règle historique
     * (mois >= moisDebut ? année : année - 1), corrigée du seul cas observé —
     * le lendemain de bascule d'exercice, quand la nouvelle année ne porte
     * encore aucune écriture alors que la précédente est toujours celle sur
     * laquelle on travaille.
     *
     * Volontairement étroit : ce n'est PAS une recherche du « dernier exercice
     * ouvert avec des écritures », qui décalerait l'exercice sous les pieds de
     * tout le reste du code. Un seul palier en arrière, et seulement si
     * l'année calculée est vide et l'année précédente ne l'est pas. Dès la
     * première écriture du nouvel exercice, le calcul normal reprend la main
     * spontanément.
     */
    private function exerciceParDefaut(): int
    {
        $now = CarbonImmutable::now();
        $moisDebut = $this->moisDebut();
        $calcule = $now->month >= $moisDebut ? $now->year : $now->year - 1;

        if (! TenantContext::hasBooted()) {
            return $calcule;
        }

        if (! $this->exercicePorteUneEcriture($calcule) && $this->exercicePorteUneEcriture($calcule - 1)) {
            return $calcule - 1;
        }

        return $calcule;
    }

    /**
     * Au moins une écriture de classe 6 ou 7 sur l'exercice donné.
     * Tenant-scopé sur c.association_id — TenantContext::hasBooted() est
     * garanti par l'appelant, exerciceParDefaut(), qui court-circuite avant
     * tout appel si aucun tenant n'est booté.
     */
    private function exercicePorteUneEcriture(int $exercice): bool
    {
        $range = $this->dateRange($exercice);

        return DB::table('transaction_lignes as tl')
            ->join('comptes as c', 'tl.compte_id', '=', 'c.id')
            ->join('transactions as tx', 'tl.transaction_id', '=', 'tx.id')
            ->whereIn('c.classe', [6, 7])
            ->whereNotNull('tl.compte_id')
            ->whereNull('tl.deleted_at')
            ->whereNull('tx.deleted_at')
            ->whereBetween('tx.date', [$range['start']->toDateString(), $range['end']->toDateString()])
            ->where('c.association_id', TenantContext::currentId())
            ->exists();
    }

    /**
     * Return the start and end dates for a given exercice.
     * Dates are computed from the current tenant's exercice_mois_debut.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function dateRange(int $exercice): array
    {
        $moisDebut = $this->moisDebut();
        $start = CarbonImmutable::create($exercice, $moisDebut, 1)->startOfDay();

        if ($moisDebut === 1) {
            // Calendrier : exercice jan–déc de la même année
            $end = CarbonImmutable::create($exercice, 12, 31)->startOfDay();
        } else {
            // Décalé : fin le dernier jour du mois précédant moisDebut, année suivante
            $endMonth = $moisDebut - 1;
            $end = CarbonImmutable::create($exercice + 1, $endMonth, 1)->endOfMonth()->startOfDay();
        }

        return compact('start', 'end');
    }

    /**
     * Return a display label for the given exercice.
     * Returns e.g. "2026" for a calendar exercice, "2025-2026" for a shifted one.
     */
    public function label(int $exercice): string
    {
        return $this->moisDebut() === 1
            ? (string) $exercice
            : $exercice.'-'.($exercice + 1);
    }

    /**
     * Return the best default date for a new entry in the active exercice.
     * Returns today if in range, dateFin if past, dateDebut if future.
     */
    public function defaultDate(): string
    {
        $range = $this->dateRange($this->current());
        $today = CarbonImmutable::today();

        if ($today->lt($range['start'])) {
            return $range['start']->toDateString();
        }

        if ($today->gt($range['end'])) {
            return $range['end']->toDateString();
        }

        return $today->toDateString();
    }

    /**
     * Return the Exercice model for the currently displayed exercice.
     */
    public function exerciceAffiche(): ?Exercice
    {
        return Exercice::where('annee', $this->current())->first();
    }

    /**
     * Calculate which exercice a given date belongs to.
     * Month >= moisDebut → that year, otherwise → previous year.
     */
    public function anneeForDate(CarbonImmutable|Carbon $date): int
    {
        $moisDebut = $this->moisDebut();

        return $date->month >= $moisDebut ? $date->year : $date->year - 1;
    }

    /**
     * Assert that the exercice for the given year is open.
     * Throws ExerciceCloturedException if closed.
     * Does nothing if the exercice does not exist in database (graceful for fresh installs).
     */
    public function assertOuvert(int $annee): void
    {
        $exercice = Exercice::where('annee', $annee)->first();

        $this->assertModeleOuvert($exercice, $annee);
    }

    /**
     * Verrouille l'exercice source pendant une écriture comptable.
     *
     * L'ordre canonique partagé avec cloturer() est : exercice source, exercice
     * cible éventuel, puis écritures. Le verrou reste porté par la transaction
     * appelante jusqu'au commit.
     */
    public function assertOuvertVerrouille(int $annee): void
    {
        $exercice = Exercice::where('annee', $annee)
            ->lockForUpdate()
            ->first();

        $this->assertModeleOuvert($exercice, $annee);
    }

    /**
     * Protocole canonique de verrou avant toute écriture comptable rattachée à
     * un exercice : l'association d'abord, l'exercice ensuite — exactement
     * l'ordre de cloturer() et d'ANouveauService::persister().
     *
     * L'ordre importe autant que les verrous eux-mêmes : deux chemins qui
     * prendraient les mêmes verrous dans l'ordre inverse (exercice puis
     * association) s'inter-bloqueraient au lieu de s'exclure proprement.
     * Mutualisé ici pour que chaque service cesse de reconstruire sa propre
     * moitié de la séquence.
     *
     * Les verrous ne valent que si l'appel a lieu à l'intérieur du
     * DB::transaction() qui porte aussi l'écriture — ils sont tenus jusqu'à son
     * commit. C'est la responsabilité de l'appelant.
     *
     * Retourne l'exercice verrouillé, ou null s'il n'existe pas en base
     * (installation neuve : l'absence d'exercice n'est pas une clôture).
     */
    public function verrouillerPourEcriture(int $annee): ?Exercice
    {
        DB::table('association')
            ->where('id', TenantContext::currentId())
            ->lockForUpdate()
            ->first();

        return Exercice::where('annee', $annee)
            ->lockForUpdate()
            ->first();
    }

    /**
     * verrouillerPourEcriture() suivi du contrôle d'ouverture : la variante à
     * utiliser quand le refus attendu est l'ExerciceCloturedException standard.
     *
     * Les appelants qui doivent lever leur propre exception métier (par exemple
     * DotationService, avec DotationInterditeException) appellent
     * verrouillerPourEcriture() et contrôlent le statut eux-mêmes.
     */
    public function assertOuvertPourEcriture(int $annee): void
    {
        $this->assertModeleOuvert($this->verrouillerPourEcriture($annee), $annee);
    }

    /**
     * Close an exercice: update status, record action.
     */
    public function cloturer(Exercice $exercice, User $user): void
    {
        DB::transaction(function () use ($exercice, $user): void {
            // Même premier verrou qu'ANouveauService::persister() afin d'éviter
            // l'inversion tenant → exercice / exercice → tenant.
            DB::table('association')
                ->where('id', TenantContext::currentId())
                ->lockForUpdate()
                ->first();

            $exerciceVerrouille = Exercice::query()
                ->whereKey((int) $exercice->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Défense en profondeur : les gardes de l'assistant sont
            // consultatives, ce service peut être appelé directement. Refuser ici
            // rend la garde réelle. Un utilisateur normal ne voit jamais cette
            // exception : l'assistant l'a arrêté avant.
            $etatCompta = app(EtatComptaResolver::class)->pourTenantCourant();
            if (! $etatCompta->estOperationnel()) {
                throw EtapeComptaRequiseException::pour($etatCompta);
            }

            $exerciceCible = Exercice::query()
                ->where('annee', (int) $exerciceVerrouille->annee + 1)
                ->lockForUpdate()
                ->first();

            if ($exerciceCible?->isCloture()) {
                throw new \RuntimeException('Impossible de générer les à-nouveaux : l’exercice cible est clôturé.');
            }

            $preview = app(ANouveauPreviewBuilder::class)->build((int) $exerciceVerrouille->annee);
            app(ANouveauService::class)->persister(
                $preview,
                OrigineANouveau::Cloture,
                $user,
            );

            $exerciceVerrouille->update([
                'statut' => StatutExercice::Cloture,
                'date_cloture' => now(),
                'cloture_par_id' => $user->id,
            ]);

            ExerciceAction::create([
                'exercice_id' => $exerciceVerrouille->id,
                'action' => TypeActionExercice::Cloture,
                'user_id' => $user->id,
            ]);
        });
    }

    private function assertModeleOuvert(?Exercice $exercice, int $annee): void
    {
        if ($exercice !== null && $exercice->isCloture()) {
            throw new ExerciceCloturedException($annee);
        }
    }

    /**
     * Reopen a closed exercice with a mandatory comment.
     */
    public function reouvrir(Exercice $exercice, User $user, string $commentaire): void
    {
        DB::transaction(function () use ($exercice, $user, $commentaire): void {
            app(ANouveauService::class)->invalider($exercice, $user, $commentaire);

            $exercice->update([
                'statut' => StatutExercice::Ouvert,
                'date_cloture' => null,
                'cloture_par_id' => null,
            ]);

            ExerciceAction::create([
                'exercice_id' => $exercice->id,
                'action' => TypeActionExercice::Reouverture,
                'user_id' => $user->id,
                'commentaire' => $commentaire,
            ]);
        });
    }

    /**
     * Create a new exercice year.
     * association_id is auto-filled by TenantModel's creating observer from TenantContext.
     */
    public function creerExercice(int $annee, User $user): Exercice
    {
        return DB::transaction(function () use ($annee, $user): Exercice {
            $exercice = Exercice::create([
                'annee' => $annee,
                'statut' => StatutExercice::Ouvert,
            ]);

            ExerciceAction::create([
                'exercice_id' => $exercice->id,
                'action' => TypeActionExercice::Creation,
                'user_id' => $user->id,
            ]);

            return $exercice;
        });
    }

    /**
     * Return available exercice years for dropdowns.
     * From current year + 1 down to current year - 3.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $currentYear = (int) now()->format('Y');

        return range($currentYear + 1, $currentYear - 3);
    }

    /**
     * Same as availableYears() but excludes years for which the Exercice
     * is closed in the database. Useful for forms that must not allow
     * write operations on a closed exercise (cohérence comptable).
     *
     * @return list<int>
     */
    public function openYears(): array
    {
        $years = $this->availableYears();
        $closedYears = Exercice::query()
            ->whereIn('annee', $years)
            ->where('statut', StatutExercice::Cloture->value)
            ->pluck('annee')
            ->map(fn ($a) => (int) $a)
            ->all();

        return array_values(array_diff($years, $closedYears));
    }

    /**
     * Switch the displayed exercice : session ET pivot association_user.
     *
     * Seul site d'écriture de la préférence. La session porte le choix pour
     * la requête en cours ; le pivot le fait survivre à l'expiration de la
     * session (SESSION_LIFETIME = 120 min), par utilisateur et par
     * association — volontaire en multi-tenant, un utilisateur peut suivre
     * un exercice différent chez chaque association.
     */
    public function changerExerciceAffiche(Exercice $exercice): void
    {
        session(['exercice_actif' => $exercice->annee]);

        if (Auth::hasUser() && TenantContext::hasBooted()) {
            Auth::user()->associations()->updateExistingPivot(TenantContext::currentId(), [
                'exercice_actif' => $exercice->annee,
            ]);
        }
    }
}
