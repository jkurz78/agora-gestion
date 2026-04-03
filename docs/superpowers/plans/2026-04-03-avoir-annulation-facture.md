# Avoir / Annulation Facture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow cancellation of validated invoices by issuing a credit note (avoir), releasing locked transactions.

**Architecture:** Add `FactureService::annuler()` with validation checks, `Transaction::isLockedByRapprochement()`, adapt PDF template for avoir mode, and add annulation UI to `FactureShow`.

**Tech Stack:** Laravel 11, Livewire 4, Pest PHP, dompdf

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Modify | `app/Models/Transaction.php:~131` | Add `isLockedByRapprochement()` |
| Modify | `app/Services/FactureService.php:~320` | Add `annuler()` method |
| Modify | `app/Http/Controllers/FacturePdfController.php` | Support avoir filename |
| Modify | `resources/views/pdf/facture.blade.php:183-298` | Conditional avoir mode |
| Modify | `app/Livewire/FactureShow.php:~60` | Add `annuler()` action |
| Modify | `resources/views/livewire/facture-show.blade.php:~180` | Annuler button + avoir info |
| Create | `tests/Feature/Services/FactureAvoirTest.php` | Tests for annulation logic |

---

### Task 1: Transaction::isLockedByRapprochement() + FactureService::annuler()

**Files:**
- Modify: `app/Models/Transaction.php`
- Modify: `app/Services/FactureService.php`
- Create: `tests/Feature/Services/FactureAvoirTest.php`

- [ ] **Step 1: Write test — annulation succeeds on valid facture**

Create `tests/Feature/Services/FactureAvoirTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ExerciceService;
use App\Services\FactureService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(FactureService::class);
});

it('annule une facture validée et attribue un numéro avoir', function () {
    $exercice = app(ExerciceService::class)->current();
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $compte = CompteBancaire::factory()->create();

    $facture = Facture::create([
        'numero' => sprintf('F-%d-0001', $exercice),
        'date' => now(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'compte_bancaire_id' => $compte->id,
        'montant_total' => 100.00,
        'saisi_par' => $this->user->id,
        'exercice' => $exercice,
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => 'montant',
        'libelle' => 'Prestation',
        'montant' => 100.00,
        'ordre' => 1,
    ]);

    $this->service->annuler($facture);

    $facture->refresh();
    expect($facture->statut)->toBe(StatutFacture::Annulee);
    expect($facture->numero_avoir)->toStartWith('AV-');
    expect($facture->date_annulation)->not->toBeNull();
    // Original facture numero is preserved
    expect($facture->numero)->toBe(sprintf('F-%d-0001', $exercice));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Services/FactureAvoirTest.php`
Expected: FAIL — method annuler does not exist

- [ ] **Step 3: Add `isLockedByRapprochement()` to Transaction model**

In `app/Models/Transaction.php`, after `isLockedByFacture()` (line ~131), add:

```php
    public function isLockedByRapprochement(): bool
    {
        return $this->rapprochement_id !== null;
    }
```

- [ ] **Step 4: Add `annuler()` to FactureService**

In `app/Services/FactureService.php`, after `supprimerLigne()` and before `genererPdf()`, add:

```php
    /**
     * Cancel a validated invoice by issuing a credit note (avoir).
     */
    public function annuler(Facture $facture): void
    {
        if ($facture->statut !== StatutFacture::Validee) {
            throw new \RuntimeException('Seule une facture validée peut être annulée.');
        }

        // Check no linked transaction is locked by rapprochement
        foreach ($facture->transactions as $tx) {
            if ($tx->isLockedByRapprochement()) {
                throw new \RuntimeException(
                    "La transaction « {$tx->libelle} » est rapprochée en banque. Veuillez d'abord annuler le rapprochement."
                );
            }
        }

        $exerciceService = app(ExerciceService::class);
        $exerciceCourant = $exerciceService->current();

        DB::transaction(function () use ($facture, $exerciceCourant): void {
            // Lock avoirs of current exercise for sequential numbering
            $existingAvoirs = Facture::where('exercice', $exerciceCourant)
                ->where('statut', StatutFacture::Annulee)
                ->whereNotNull('numero_avoir')
                ->lockForUpdate()
                ->get();

            $maxSeq = $existingAvoirs
                ->map(fn ($f) => (int) last(explode('-', $f->numero_avoir)))
                ->max() ?? 0;

            $seq = $maxSeq + 1;
            $numeroAvoir = sprintf('AV-%d-%04d', $exerciceCourant, $seq);

            $facture->update([
                'statut' => StatutFacture::Annulee,
                'numero_avoir' => $numeroAvoir,
                'date_annulation' => now()->toDateString(),
            ]);
        });
    }
```

Add `use App\Services\ExerciceService;` to imports if not present.

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Services/FactureAvoirTest.php --filter="annule une facture"`
Expected: PASS

- [ ] **Step 6: Write test — annulation blocked on brouillon**

```php
it('refuse annulation sur un brouillon', function () {
    $facture = Facture::create([
        'date' => now(),
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => Tiers::factory()->create()->id,
        'montant_total' => 0,
        'saisi_par' => $this->user->id,
        'exercice' => app(ExerciceService::class)->current(),
    ]);

    expect(fn () => $this->service->annuler($facture))
        ->toThrow(\RuntimeException::class, 'Seule une facture validée');
});
```

- [ ] **Step 7: Write test — annulation blocked if transaction rapprochée**

```php
it('refuse annulation si une transaction est rapprochée', function () {
    $exercice = app(ExerciceService::class)->current();
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $compte = CompteBancaire::factory()->create();

    $facture = Facture::create([
        'numero' => sprintf('F-%d-0099', $exercice),
        'date' => now(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'compte_bancaire_id' => $compte->id,
        'montant_total' => 50.00,
        'saisi_par' => $this->user->id,
        'exercice' => $exercice,
    ]);

    $tx = Transaction::create([
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'type' => 'recette',
        'date' => now(),
        'libelle' => 'Paiement test',
        'montant_total' => 50.00,
        'mode_paiement' => 'virement',
        'saisi_par' => $this->user->id,
        'rapprochement_id' => 1, // simulates linked rapprochement
    ]);

    $facture->transactions()->attach($tx->id);

    expect(fn () => $this->service->annuler($facture))
        ->toThrow(\RuntimeException::class, 'rapprochée en banque');
});
```

- [ ] **Step 8: Write test — transactions libérées après annulation**

```php
it('libère les transactions après annulation', function () {
    $exercice = app(ExerciceService::class)->current();
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $compte = CompteBancaire::factory()->create();

    $facture = Facture::create([
        'numero' => sprintf('F-%d-0050', $exercice),
        'date' => now(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'compte_bancaire_id' => $compte->id,
        'montant_total' => 75.00,
        'saisi_par' => $this->user->id,
        'exercice' => $exercice,
    ]);

    $tx = Transaction::create([
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'type' => 'recette',
        'date' => now(),
        'libelle' => 'Paiement',
        'montant_total' => 75.00,
        'mode_paiement' => 'cb',
        'saisi_par' => $this->user->id,
    ]);
    $facture->transactions()->attach($tx->id);

    // Before: transaction is locked
    expect($tx->fresh()->isLockedByFacture())->toBeTrue();

    $this->service->annuler($facture);

    // After: transaction is free
    expect($tx->fresh()->isLockedByFacture())->toBeFalse();
});
```

- [ ] **Step 9: Run all tests and commit**

Run: `./vendor/bin/sail test tests/Feature/Services/FactureAvoirTest.php -v`
Expected: All 4 tests PASS

```bash
git add app/Models/Transaction.php app/Services/FactureService.php tests/Feature/Services/FactureAvoirTest.php
git commit -m "feat: FactureService::annuler() avec numérotation avoir et garde rapprochement"
```

---

### Task 2: PDF Avoir Mode

**Files:**
- Modify: `resources/views/pdf/facture.blade.php`
- Modify: `app/Services/FactureService.php` (genererPdf — skip Factur-X for avoir)
- Modify: `app/Http/Controllers/FacturePdfController.php` (avoir filename)

- [ ] **Step 1: Adapt PDF template for avoir mode**

In `resources/views/pdf/facture.blade.php`, replace the title/number section (lines 183-189):

```blade
                <td style="width: 40%; text-align: right;">
                    @if ($facture->statut === \App\Enums\StatutFacture::Annulee && $facture->numero_avoir)
                        <div class="doc-title">AVOIR</div>
                        <div class="doc-info">
                            <span><strong>N&deg; :</strong> {{ $facture->numero_avoir }}</span>
                            <span><strong>Date :</strong> {{ $facture->date_annulation->format('d/m/Y') }}</span>
                        </div>
                        <div style="font-size: 9px; color: #6c757d; margin-top: 4px;">
                            Annule la facture {{ $facture->numero }}
                            du {{ $facture->date->format('d/m/Y') }}
                        </div>
                    @else
                        <div class="doc-title">FACTURE</div>
                        <div class="doc-info">
                            <span><strong>N&deg; :</strong> {{ $facture->numero ?? 'Brouillon' }}</span>
                            <span><strong>Date :</strong> {{ $facture->date->format('d/m/Y') }}</span>
                        </div>
                    @endif
                </td>
```

- [ ] **Step 2: Show negative amounts for avoir in lines table**

In the lines table (line 226), wrap the amount display:

```blade
                        <td class="text-end">{{ $facture->statut === \App\Enums\StatutFacture::Annulee ? '-' : '' }}{{ number_format((float) $ligne->montant, 2, ',', "\u{00A0}") }} &euro;</td>
```

And in the total footer (line 235):

```blade
                <td class="text-end">{{ $facture->statut === \App\Enums\StatutFacture::Annulee ? '-' : '' }}{{ number_format($facture->montantCalcule(), 2, ',', "\u{00A0}") }} &euro;</td>
```

- [ ] **Step 3: Hide payment section and acquittée stamp for avoir**

Wrap the payment table (lines 241-251) and acquittée stamp (lines 253-258) with:

```blade
    @if ($facture->statut !== \App\Enums\StatutFacture::Annulee)
    {{-- existing payment table and acquittée stamp --}}
    @endif
```

- [ ] **Step 4: Skip Factur-X for avoir in genererPdf()**

In `app/Services/FactureService.php`, modify `genererPdf()` around line 354. Change:

```php
        if ($facture->statut === StatutFacture::Brouillon) {
            return $pdfContent;
        }
```

To:

```php
        if ($facture->statut !== StatutFacture::Validee) {
            return $pdfContent;
        }
```

This skips Factur-X for both brouillon AND annulee.

- [ ] **Step 5: Support avoir filename in FacturePdfController**

In `app/Http/Controllers/FacturePdfController.php`, update the `__invoke` method:

```php
    public function __invoke(Facture $facture, FactureService $service): Response
    {
        $facture->load('tiers');

        $pdfContent = $service->genererPdf($facture);

        if ($facture->statut === StatutFacture::Annulee && $facture->numero_avoir) {
            $label = $facture->numero_avoir;
            $prefix = 'Avoir';
        } else {
            $label = $facture->numero ?? 'Brouillon';
            $prefix = 'Facture';
        }
        $filename = "{$prefix} {$label} - {$facture->tiers->displayName()}.pdf";

        $inline = request()->query('mode') === 'inline';

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename=\"{$filename}\"",
        ]);
    }
```

Add `use App\Enums\StatutFacture;` to imports.

- [ ] **Step 6: Run tests and commit**

Run: `./vendor/bin/sail test tests/Feature/Services/FactureAvoirTest.php tests/Feature/Livewire/FactureShowTest.php tests/Feature/Livewire/FactureEditTest.php -v`
Expected: All PASS

```bash
git add resources/views/pdf/facture.blade.php app/Services/FactureService.php app/Http/Controllers/FacturePdfController.php
git commit -m "feat: PDF avoir mode — titre, montants négatifs, référence facture annulée"
```

---

### Task 3: FactureShow UI — Bouton annuler et affichage avoir

**Files:**
- Modify: `app/Livewire/FactureShow.php`
- Modify: `resources/views/livewire/facture-show.blade.php`

- [ ] **Step 1: Add `annuler()` method to FactureShow**

In `app/Livewire/FactureShow.php`, add after `encaisser()` (line ~99):

```php
    public function annuler(): void
    {
        try {
            app(FactureService::class)->annuler($this->facture);
            $this->facture->refresh();
            $this->facture->load(['tiers', 'compteBancaire', 'lignes', 'transactions.compte']);
            session()->flash('success', "Avoir {$this->facture->numero_avoir} émis. La facture {$this->facture->numero} est annulée.");
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }
```

- [ ] **Step 2: Add annulation info block in the Blade view**

In `resources/views/livewire/facture-show.blade.php`, after the date line (line ~39) and before the closing `</div>` of the header, add:

```blade
            @if ($facture->statut === \App\Enums\StatutFacture::Annulee && $facture->numero_avoir)
                <p class="text-muted mb-0">
                    Avoir <strong>{{ $facture->numero_avoir }}</strong>
                    émis le <strong>{{ $facture->date_annulation->format('d/m/Y') }}</strong>
                    — Annule la facture {{ $facture->numero }}
                </p>
            @endif
```

- [ ] **Step 3: Add annuler button in the Actions card**

In `resources/views/livewire/facture-show.blade.php`, in the Actions card (after the "Télécharger PDF" link at line ~187), add:

```blade
                    @if ($facture->statut === \App\Enums\StatutFacture::Validee)
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#annulationModal">
                            <i class="bi bi-x-circle"></i> Annuler avec avoir
                        </button>
                    @endif
```

- [ ] **Step 4: Add confirmation modal at the end of the view**

Before the closing `</div>` of the component, add:

```blade
    {{-- Modale de confirmation d'annulation --}}
    @if ($facture->statut === \App\Enums\StatutFacture::Validee)
    <div class="modal fade" id="annulationModal" tabindex="-1" wire:ignore.self>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Annuler cette facture</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Êtes-vous sûr de vouloir annuler la facture <strong>{{ $facture->numero }}</strong> ?</p>
                    <ul class="text-muted small">
                        <li>Un avoir sera émis avec un numéro séquentiel</li>
                        <li>Les transactions associées seront libérées</li>
                        <li>Cette action est irréversible</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Non, conserver</button>
                    <button type="button" class="btn btn-danger" wire:click="annuler" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Oui, émettre l'avoir
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
```

- [ ] **Step 5: Hide payment/encaissement section for annulée**

In the Blade view, wrap the entire payment card (lines ~85-121) with a condition:

```blade
            @if ($facture->statut !== \App\Enums\StatutFacture::Annulee)
            {{-- existing payment card --}}
            @endif
```

- [ ] **Step 6: Update PDF button label for avoir**

In the Actions card, change the PDF download link to adapt for avoir:

```blade
                    <a href="{{ route(($espace ?? \App\Enums\Espace::Compta)->value . '.factures.pdf', ['facture' => $facture, 'mode' => 'inline']) }}" class="btn btn-outline-primary" target="_blank">
                        <i class="bi bi-file-earmark-pdf"></i>
                        {{ $facture->statut === \App\Enums\StatutFacture::Annulee ? 'Télécharger l\'avoir (PDF)' : 'Télécharger PDF' }}
                    </a>
```

- [ ] **Step 7: Run all tests and commit**

Run: `./vendor/bin/sail test`
Expected: All tests PASS

```bash
git add app/Livewire/FactureShow.php resources/views/livewire/facture-show.blade.php
git commit -m "feat: bouton Annuler avec avoir + affichage info avoir sur FactureShow"
```

---

### Task 4: Run Full Suite + Pint

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: All tests PASS

- [ ] **Step 2: Run Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint --test`
If fixes needed: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 3: Commit if needed**

```bash
git add -A
git commit -m "style: apply Pint formatting"
```
