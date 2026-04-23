# Portail tiers — séparation en deux blocs (slice A)

**Statut** : Design approuvé, prêt pour plan d'implémentation
**Date** : 2026-04-23
**Slice** : A (plomberie). Slice B (contenu du bloc Membres, factures partenaires) dans une itération ultérieure.

## Contexte

Le portail tiers (`/portail/*` en mode mono, `/{slug}/portail/*` en slug-first) expose aujourd'hui une seule fonctionnalité : les notes de frais. Son Home affiche une carte unique vers `portail.ndf.index`.

Le modèle `Tiers` porte déjà deux flags booléens — `pour_recettes` et `pour_depenses` — qui décrivent si un tiers est utilisable côté recettes (membre, donateur, participant qui paie) ou côté dépenses (partenaire, bénévole qui se fait rembourser). `TiersForm` valide qu'au moins un des deux est coché à la création.

On va utiliser ces flags pour scinder le portail en deux espaces métier. Cette slice A ne fait que la plomberie : séparation UI, routing, contrôle d'accès NDF. Le contenu futur de l'espace Membres (cotisations, dons, attestations) et l'espace Factures partenaires sont sortis du périmètre.

## Objectifs

1. Un tiers `pour_recettes=true, pour_depenses=false` voit un espace Membres (placeholder) et ne peut pas accéder aux NDF.
2. Un tiers `pour_depenses=true, pour_recettes=false` voit l'espace Partenaires avec les NDF (comme aujourd'hui).
3. Un tiers avec les deux flags voit les deux espaces sur son Home.
4. Un admin back-office ne peut pas décocher un flag si le tiers a déjà des transactions du type correspondant — l'intégrité référentielle est protégée.

## Hors périmètre

- Contenu fonctionnel de l'espace Membres (cotisations, dons, attestations) — slice B.
- Espace Factures partenaires — slice B.
- Sidebar ou navigation enrichie — on en reparlera quand la slice B amènera assez de sections pour la justifier.
- Backfill des tiers existants dont les flags seraient incohérents avec leur historique transactionnel. On ne déploie la règle qu'en avant, sans rattrapage rétroactif.

## Architecture

### 1. Home portail — deux sections conditionnelles

La vue `resources/views/livewire/portail/home.blade.php` rend jusqu'à deux sections empilées dans l'ordre fixe **Membres → Partenaires** :

**Section Membres / Participants / Donateurs** — affichée ssi `$tiers->pour_recettes === true`
- Contenu slice A : un placeholder informatif («Votre espace membre sera bientôt enrichi : cotisations, dons, attestations de présence, reçus fiscaux.»).
- Aucune interaction, aucune route enfant. La section existe visuellement pour signaler que le périmètre est reconnu.

**Section Partenaires / Bénévoles** — affichée ssi `$tiers->pour_depenses === true`
- Contenu slice A : la carte actuelle «Vos notes de frais» qui pointait sur `portail.ndf.index`. Déplacée telle quelle depuis le Home racine.

Cas dégénéré : un tiers sans aucun flag coché ne devrait pas exister (validation `TiersForm` existante). Si cela se produit malgré tout (import, bug passé), le Home affiche un message neutre («Aucun espace n'est activé pour votre compte — contactez votre association.») et le bouton de déconnexion.

Pas de nouvelle route. `portail.home` et `portail.mono.home` continuent de rendre le même composant Livewire `App\Livewire\Portail\Home`.

### 2. Contrôle d'accès aux routes NDF

Nouveau middleware `App\Http\Middleware\Portail\EnsurePourDepenses` :

```
handle(Request $request, Closure $next): Response
    $tiers = Auth::guard('tiers-portail')->user();
    if ($tiers === null || $tiers->pour_depenses !== true) {
        session()->flash('portail.info', "Cet espace n'est pas activé pour votre compte.");
        return redirect(PortailRoute::to('home', $request->route('association')));
    }
    return $next($request);
```

Appliqué sur le groupe `prefix('notes-de-frais')` dans `routes/portail.php` et `routes/portail-mono.php`. Vient en complément des middlewares déjà en place (`EnsureTiersChosen`, `EnforceSessionLifetime`, `Authenticate`).

Parité : on crée aussi `App\Http\Middleware\Portail\EnsurePourRecettes` avec le champ `pour_recettes`. Il n'est pas branché sur une route dans la slice A (rien à protéger encore), mais il est prêt et testé pour que la slice B le branche sur `/portail/cotisations`, `/portail/dons`, etc.

### 3. Confirmation d'intention sur les flags côté back-office

Évolution de `App\Livewire\TiersForm` (pleine page et modal d'édition).

**Règle** : les deux flags restent librement cochables/décochables. En revanche, **décocher** un flag alors que le tiers a au moins une transaction du type correspondant est un acte métier volontaire (déréférencement fournisseur / sortie d'adhérent) qui doit être confirmé explicitement avant persistence.

Détection :
- Décochage `pour_depenses` significatif ssi `$tiers->transactions()->where('type', TypeTransaction::Depense)->exists()`
- Décochage `pour_recettes` significatif ssi `$tiers->transactions()->where('type', TypeTransaction::Recette)->exists()`

Les transactions historiques ne sont jamais altérées — on empêche seulement la sélection future du tiers dans les autocompléteurs correspondants. Ce comportement reste implicite, géré par les scopes existants (`TiersAutocomplete` filtre déjà sur `pour_depenses` / `pour_recettes`).

**UX — workflow de confirmation** :

1. À la soumission du formulaire via `save()`, on détecte chaque flag qui passe de `true` à `false` avec transactions associées.
2. Si au moins un des deux cas est détecté, `save()` ne persiste pas immédiatement. Il stocke le snapshot des intentions (`$pendingChanges`) dans la propriété du composant, renseigne la propriété `$confirmMessage` (ex : «Vous allez déréférencer ce tiers du côté dépenses. 42 transactions historiques existent et resteront inchangées, mais le tiers ne pourra plus être sélectionné pour de nouvelles dépenses. Confirmer ?») et ouvre un **modal Bootstrap** via `Livewire.dispatch('show-tiers-dereference-confirm')`.
3. Le modal a deux boutons :
   - **Annuler** → ferme le modal, les intentions `$pendingChanges` sont vidées, les checkboxes dans le form sont rollback à leur valeur précédente via un événement Livewire.
   - **Confirmer** → appelle `saveConfirmed()` qui rejoue la persistence avec les intentions validées.

Pas de `confirm()` natif — modal Bootstrap conformément aux conventions projet.

**Défense en profondeur** : la détection et le court-circuit vivent dans `save()` côté serveur. Un bypass DOM qui tenterait de soumettre directement avec `confirmed=true` sans passer par le modal serait possible puisqu'on accepte l'intention ; c'est cohérent avec la sémantique retenue (on demande confirmation, on ne bloque pas). Si à l'avenir on veut durcir pour certains cas particuliers (ex : tiers avec transactions d'un exercice clôturé), on l'ajoute sans toucher au patron.

**Import CSV** — `App\Livewire\ImportCsvTiers` ne peut pas présenter un modal, donc on applique un comportement différent : pour une ligne qui décoche un flag avec transactions liées, on passe outre le décochage (les flags en base restent à `true`) et on émet un **warning** non-bloquant dans le rapport d'import («Ligne 42 : décochage ignoré pour {nom_tiers}, transactions liées — utiliser l'écran d'édition pour déréférencer.»). Le reste de la ligne (autres champs) est appliqué normalement.

Le `TypeTransaction::Recette` couvre aujourd'hui les dons, cotisations et recettes métier (Don et Cotisation ont fusionné dans Transaction via des migrations passées — `tiers.transactions` est la source unique).

## Data flow

```
GET /portail/ (mono)
  → Authenticate (tiers-portail guard)
  → Home component
    → render home.blade.php
      → si pour_recettes → section Membres (placeholder)
      → si pour_depenses → section Partenaires (carte NDF)

GET /portail/notes-de-frais
  → Authenticate
  → EnsurePourDepenses  ← NOUVEAU
    → si pour_depenses=true → continue vers Index NDF
    → sinon → redirect /portail/ + flash "non activé"

POST /parametres/tiers (edit, décocher pour_depenses)
  → TiersForm::save()
    → si pour_depenses passe true→false ET transactions depense existent
      → stocke $pendingChanges, ouvre le modal de confirmation (pas de persist)
    → sinon → persist normal

Modal confirmé
  → TiersForm::saveConfirmed()
    → applique $pendingChanges, persist normal

Modal annulé
  → TiersForm::cancelDereference()
    → vide $pendingChanges, rollback des checkboxes côté UI
```

## Tests

### Middleware `EnsurePourDepenses`

- Tiers `pour_depenses=true` sur `/portail/notes-de-frais` → 200
- Tiers `pour_depenses=false` sur `/portail/notes-de-frais` → redirect `/portail/` + session flash présent
- Variante slug-first : `/{slug}/portail/notes-de-frais` → redirect `/{slug}/portail/`
- Mêmes cas pour `EnsurePourRecettes` (même si pas branché en slice A, on teste qu'il fonctionne)

### Home

- Tiers `pour_recettes=true, pour_depenses=false` → voit section Membres placeholder, ne voit pas la carte NDF
- Tiers `pour_recettes=false, pour_depenses=true` → voit carte NDF, ne voit pas section Membres
- Tiers avec les deux flags → voit les deux sections, ordre Membres puis Partenaires
- Tiers sans aucun flag → voit le message neutre «Aucun espace activé»

### TiersForm (back-office)

- Create tiers avec les deux flags → OK, pas de modal
- Update tiers sans transactions, décocher `pour_depenses` → OK direct, pas de modal
- Update tiers avec transaction `Depense`, décocher `pour_depenses` → `save()` déclenche l'événement d'ouverture de modal, rien n'est persisté tant que l'utilisateur n'a pas confirmé
- Après confirmation : `saveConfirmed()` persiste le décochage, le tiers est bien mis à jour en base
- Après annulation : `cancelDereference()` remet les checkboxes dans leur état d'origine, le tiers n'est pas modifié
- Idem pour `pour_recettes` avec transaction `Recette`
- Cocher un flag (activation) reste libre dans tous les cas, pas de modal
- Décocher un flag inactif (déjà à `false`) reste libre

### ImportCsvTiers

- Ligne qui décoche `pour_depenses` sur tiers avec transactions Depense → décochage ignoré, warning dans le rapport, autres champs de la ligne appliqués
- Ligne qui décoche `pour_depenses` sur tiers sans transactions → décochage appliqué normalement
- Autres lignes de l'import → passent normalement

## Décisions

- **Pas de backfill préventif** des flags incohérents dans la base existante. Si un tiers a des transactions sans le flag correspondant (possiblement hérité de l'avant `TiersForm` validation), on ne force rien ; l'admin qui rouvre la fiche verra la case à cocher et pourra décider de son état.
- **Confirmation, pas blocage** sur le décochage d'un flag avec transactions liées. Un décochage est un geste métier légitime (déréférencement d'un fournisseur qui ne livre plus, sortie d'adhérent qui ne cotise plus) ; le modal demande confirmation d'intention, il n'interdit pas.
- **Pas de filtrage au login**. Tout tiers authentifié peut entrer sur le portail ; c'est Home qui module l'affichage et `EnsurePourDepenses` qui protège les routes sensibles.
- **Ordre fixe Membres → Partenaires** sur le Home. Choix arbitraire pour cohérence visuelle.
- **Libellés des blocs** : «Membres, participants, donateurs» et «Partenaires, bénévoles». Reprend la terminologie utilisée par Jurgen dans la description du besoin.
- **Pas de sidebar en slice A**. La décision sera réévaluée au début de la slice B, qui ajoutera assez de sections pour la justifier.

## Risques et mitigation

- **Breaking change pour un tiers avec NDF existantes mais `pour_depenses=false` en base** — peu probable mais pas impossible si la fiche a été éditée avant l'apparition de la validation actuelle. Le middleware le bloquerait. Mitigation : on lance une query d'audit manuelle avant déploiement (`SELECT tiers_id, count(*) FROM transaction WHERE type='depense' JOIN tiers ON ... WHERE tiers.pour_depenses = false`) et on corrige les cas à la main si nécessaire.
- **Import CSV silencieusement tolérant** — on a choisi de ne pas rejeter la ligne entière pour une tentative de décochage : le warning doit rester bien visible dans le rapport sinon l'admin pourrait croire que le décochage s'est appliqué. Tests dédiés sur le rendu du rapport.
