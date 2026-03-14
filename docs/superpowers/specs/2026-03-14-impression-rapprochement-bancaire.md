# Spec : Impression des rapprochements bancaires (PDF)

**Date :** 2026-03-14
**Statut :** Validé

---

## Contexte

L'application SVS Accounting (Laravel 11 + Livewire 4 + Bootstrap 5) permet de gérer des rapprochements bancaires. Il n'existe pas encore de moyen d'imprimer ou d'archiver un rapprochement sous forme de document. Cette spec couvre la génération PDF côté serveur, ainsi que la création d'un module "Association" dans les paramètres pour centraliser les informations de l'entité (nom, adresse, logo) réutilisables dans tous les documents futurs (attestations fiscales, etc.).

L'application est mono-organisation (une seule association par instance). Il n'y a pas d'isolation par organisation entre utilisateurs authentifiés.

---

## Périmètre

### Ce qui est inclus
- Génération d'un PDF pour le **détail d'un rapprochement bancaire** (une seule page de détail)
- Affichage uniquement des **transactions pointées** dans le PDF
- Module **Paramètres > Association** : formulaire pour saisir nom, adresse, contact et uploader le logo
- Bouton "Télécharger PDF" sur la page de détail du rapprochement (statuts en cours et verrouillé)

### Ce qui est exclu
- Impression de la liste des rapprochements (index)
- Génération d'attestations fiscales (prévu ultérieurement, infrastructure posée ici)
- Export vers d'autres formats (Excel, CSV)

---

## Architecture

### Package
- `barryvdh/laravel-dompdf` installé via Composer

### Module Paramètres > Association

**Migration** : table `association` (singleton — une seule ligne avec `id = 1`)
```
id (bigint unsigned, PK), nom, adresse, code_postal, ville, email, telephone, logo_path (nullable), timestamps
```

**Modèle** : `App\Models\Association`
- Casts explicites : `id` → integer, tous les champs texte → string, `logo_path` → nullable string
- Pas de SoftDeletes

**Stratégie singleton** : `updateOrCreate(['id' => 1], [...])` — force toujours `id = 1`. Garantit l'unicité de la ligne sans contrainte DB supplémentaire. La récupération se fait via `Association::find(1)` (plus fiable que `first()` en cas d'ID auto-increment déphasé).

**Composant Livewire** : `App\Livewire\Parametres\AssociationForm`
- Formulaire d'édition : nom, adresse, code postal, ville, email, téléphone
- Upload du logo avec aperçu du logo actuel
- **Gestion du logo** :
  - Avant tout nouvel upload, l'ancien fichier est supprimé : `Storage::disk('public')->delete($ancienLogoPath)` si `logo_path` est non null
  - Le nouveau fichier est stocké via `Storage::disk('public')->putFileAs('association', $file, 'logo.'.$file->extension())`
  - Le chemin dynamique (ex. `association/logo.png`) est persisté dans `logo_path`
- Validation :
  - `nom` : required, string, max 255
  - `email` : nullable, email
  - `telephone` : nullable, string, max 30
  - `logo` : nullable, image, mimes:png,jpg,jpeg, max:2048 (ko)
- Sauvegarde via `updateOrCreate(['id' => 1], [...])` — crée la ligne si inexistante, la met à jour sinon
- Accès réservé aux utilisateurs **admin** (même contrôle que les autres routes `parametres.`)

**Route** :
```
GET /parametres/association → parametres.association
```
Ajoutée en **première position** dans le groupe `parametres.` de `routes/web.php`, protégée par le middleware `auth` déjà appliqué au groupe.

**Navigation** : sous-menu "Association" ajouté en **première position** dans le menu Paramètres (avant Catégories, Comptes bancaires, etc.).

**Prérequis déploiement** : `php artisan storage:link` doit être exécuté pour rendre `storage/app/public/` accessible via `public/storage/`.

### Génération PDF

**Contrôleur** : `App\Http\Controllers\RapprochementPdfController`
- Route : `GET /rapprochement/{rapprochement}/pdf` → `rapprochement.pdf`
  Ajoutée dans le groupe `rapprochement.` existant de `routes/web.php`
- **Autorisation** : tous les utilisateurs authentifiés (middleware `auth`). Pas de contrôle par ressource : l'application est mono-organisation
- Charge les transactions pointées (voir section Données)
- Lit `Association::find(1)` pour l'en-tête (null-safe : vide si non configuré)
- Logo : si `logo_path` non null et `Storage::disk('public')->exists($logo_path)`, encode via `base64_encode(Storage::disk('public')->get($logo_path))` — évite toute dépendance au lien symbolique
- Calcule les totaux débit/crédit
- Retourne `PDF::loadView('pdf.rapprochement', $data)->download('rapprochement-'.$rapprochement->id.'.pdf')` — déclenche un téléchargement direct

**Vue Blade PDF** : `resources/views/pdf/rapprochement.blade.php`

Structure du document (dans l'ordre d'affichage) :
1. **En-tête** : logo (`<img src="data:image/{ext};base64,{data}">`) à gauche + nom/adresse association ; titre "RAPPROCHEMENT BANCAIRE" + date de génération à droite ; ligne séparatrice
2. **Bloc infos** : compte, date de relevé, statut, saisi par — fond gris clair
3. **Bandeau soldes** : 4 cartes (solde ouverture, solde relevé, solde pointé, écart)
4. **Titre section** : "Transactions pointées (N)"
5. **Tableau** : en-tête fond #e9ecef + texte foncé + bordure basse, zèbrage léger sur les lignes, colonnes Date / Type / Libellé / Réf. / Débit / Crédit, ligne de totaux en pied
6. **Pied de page** : "SVS Accounting — Document généré automatiquement" + numéro de page

**Bouton dans l'UI** : dans `rapprochement-detail.blade.php` :
```html
<a href="{{ route('rapprochement.pdf', $rapprochement) }}" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-file-pdf"></i> Télécharger PDF
</a>
```
Visible dans les deux statuts (en cours et verrouillé). Pas de `target="_blank"` — le téléchargement ne quitte pas la page.

---

## Données

### Transactions incluses dans le PDF (transactions pointées uniquement)
- Dépenses : `where('rapprochement_id', $id)`
- Recettes : `where('rapprochement_id', $id)`
- Dons : `where('rapprochement_id', $id)`
- Cotisations : `where('rapprochement_id', $id)`
- Virements internes source : `where('rapprochement_source_id', $id)` → `montant_signe` négatif (sortant)
- Virements internes destination : `where('rapprochement_destination_id', $id)` → `montant_signe` positif (entrant)

Triées par date ASC. Même logique que `RapprochementDetail`, sans les clauses `whereNull` (qui incluaient les non-pointées).

Le champ `montant_signe` est calculé en amont (comme dans `RapprochementDetail`) : négatif pour les sorties, positif pour les entrées.

### Totaux
- **Total débit** = somme des `montant_signe` strictement négatifs → affiché en valeur absolue
- **Total crédit** = somme des `montant_signe` strictement positifs

---

## Gestion des cas limites

- **Logo absent** : `logo_path` null ou fichier inexistant → en-tête sans logo, pas d'erreur
- **Association non configurée** : `Association::find(1)` null → champs vides dans l'en-tête, PDF généré normalement
- **Aucune transaction pointée** : tableau affiche "Aucune transaction pointée", totaux à 0,00 €
- **Rapprochement inexistant** : Laravel route model binding retourne 404 automatiquement
- **Accès non authentifié** : middleware `auth` redirige vers login

---

## Tests (Pest PHP)

### RapprochementPdfController
- Retourne status 200 avec `Content-Type: application/pdf` et header `Content-Disposition: attachment; filename="rapprochement-{id}.pdf"`
- Seules les transactions pointées sont présentes dans les données ; les non pointées sont absentes
- Si `Association::find(1)` est null, le PDF est généré sans exception
- Si le fichier logo est absent du storage, le PDF est généré sans exception
- Un utilisateur non authentifié est redirigé (HTTP 302) vers login
- Un rapprochement inexistant retourne HTTP 404

### AssociationForm (Livewire)
- Sauvegarde correctement nom, adresse, email, téléphone via `updateOrCreate(['id' => 1], [...])`
- Crée la ligne avec `id = 1` si aucune ligne n'existe
- Met à jour la ligne existante sans créer de doublon
- Validation : rejette si `nom` vide
- Validation : rejette si `email` invalide
- Validation : rejette un logo > 2 Mo
- Validation : rejette un logo avec extension non autorisée
- Upload valide : persiste `logo_path` en base, stocke le fichier sur le disque `public`
- Upload valide : supprime l'ancien fichier avant de stocker le nouveau

---

## Extensibilité

Cette infrastructure PDF (contrôleur dédié, vue Blade PDF, lecture de `Association::find(1)`) sera réutilisée pour les futures attestations fiscales de dons (CERFA 2041-RD). Le module Association centralisera les informations de l'entité pour tous les documents.
