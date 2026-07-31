# Runbook — Reprise initiale des à-nouveaux comptables

| Champ | Valeur |
|---|---|
| Fonction | Création de la première pièce AN de l’exercice courant |
| Commande | `compta:bootstrap-an` |
| Risque | Élevé — écriture comptable structurante et non rejouable |
| Responsable | Administrateur fonctionnel et opérateur de déploiement |
| Principe | Dry-run obligatoire, arbitrage explicite, puis confirmation |

Ce runbook ne concerne que la première activation des à-nouveaux. Les exercices suivants sont pris en charge automatiquement par l’assistant de clôture.

## 1. Résultat attendu

La commande crée une génération d’origine `reprise_initiale` et une pièce équilibrée dans le journal `AN`, datée du premier jour de l’exercice cible.

- les classes 1 à 5 sont reprises ;
- les postes 401 et 411 non lettrés sont conservés individuellement avec leur tiers ;
- les comptes bancaires 512 utilisent le solde initial historique et les mouvements postérieurs à sa date de référence ;
- la contrepartie patrimoniale de la reprise historique est portée sur `102 — Fonds associatifs sans droit de reprise` ;
- aucune ventilation opération/séance n’est créée ;
- l’opération est atomique et une seconde génération active pour le même exercice est refusée.

Pour un exercice décalé, `--exercice=2025` désigne la période du 1er septembre 2025 au 31 août 2026.

## 2. Pré-requis

- [ ] Le code Compta V5 et ses migrations sont déployés.
- [ ] L’identifiant de l’association est confirmé.
- [ ] L’exercice cible est confirmé.
- [ ] L’utilisateur passé dans `--acteur` appartient à l’association et est habilité à valider la reprise.
- [ ] Une sauvegarde MySQL horodatée a été réalisée avant toute confirmation.
- [ ] Aucune saisie n’est effectuée pendant la fenêtre de reprise.

Exemple de sauvegarde :

```bash
mysqldump -u <user> -p <database> > ~/backups/pre-an-$(date +%Y%m%d-%H%M).sql
```

## 3. Audit obligatoire sans écriture

Lancer d’abord la commande sans arbitrage :

```bash
./vendor/bin/sail artisan compta:bootstrap-an \
  --association=1 \
  --exercice=2025 \
  --dry-run
```

Le dry-run n’écrit ni génération, ni transaction, ni ligne comptable. Il doit afficher :

- les comptes repris et leurs montants débit/crédit ;
- le tiers de chaque poste 401/411 ;
- le détail des soldes initiaux bancaires ;
- la date de référence et les mouvements trouvés ce même jour ;
- des totaux débit et crédit strictement égaux.

Si des mouvements existent à la date du solde initial, notamment des ENL au 31 août 2025, la commande s’arrête et demande `--meme-jour=inclus|exclus`.

## 4. Arbitrage des mouvements du même jour

Le choix s’applique au calcul de chaque 512 à partir de son ancien `solde_initial` :

- `--meme-jour=inclus` ajoute les mouvements comptables datés du jour de référence ;
- `--meme-jour=exclus` considère qu’ils sont déjà compris dans le solde initial et évite de les compter une seconde fois.

Les ENL importées en 401/411 restent reprises comme postes ouverts lorsqu’elles ne sont pas lettrées ; ce choix ne les supprime pas du grand livre auxiliaire.

Relancer le dry-run avec l’arbitrage retenu :

```bash
./vendor/bin/sail artisan compta:bootstrap-an \
  --association=1 \
  --exercice=2025 \
  --dry-run \
  --meme-jour=inclus
```

Conserver la sortie de cette commande avec la décision fonctionnelle. Si le résultat d’un 512, d’un 401/411 ou du 102 n’est pas explicable, ne pas confirmer.

## 5. Confirmation de la reprise

Cette étape crée la pièce comptable. Elle doit être déclenchée explicitement par l’utilisateur responsable après validation du dry-run :

```bash
./vendor/bin/sail artisan compta:bootstrap-an \
  --association=1 \
  --acteur=admin@monasso.fr \
  --exercice=2025 \
  --confirmer \
  --meme-jour=inclus
```

Remplacer les valeurs d’exemple par celles validées à l’étape précédente. Utiliser exactement le même arbitrage que lors du dry-run approuvé.

Résultat attendu :

```text
Reprise initiale créée : génération #… , pièce #… .
```

## 6. Contrôles immédiats

- [ ] La commande s’est terminée avec un code retour `0`.
- [ ] Une seule génération active existe pour l’exercice cible.
- [ ] La transaction est datée du premier jour de l’exercice et porte le journal `AN`.
- [ ] Le total débit est égal au total crédit au centime.
- [ ] Aucun compte de classe 6 ou 7 ne figure dans la pièce.
- [ ] Chaque 401/411 a un tiers et demeure non lettré.
- [ ] Chaque 512 correspond au montant validé dans le dry-run.
- [ ] La ligne AN n’est pas proposée au rapprochement bancaire.
- [ ] Une seconde exécution en mode confirmé est refusée avec « une génération active existe déjà ».

Après contrôle, ouvrir l’assistant de clôture et les écrans bancaires sur l’exercice cible afin de vérifier l’absence du bandeau « Soldes d’ouverture indisponibles » et la cohérence des soldes.

## 7. Arrêt et retour arrière

### Échec avant ou pendant la confirmation

La création est exécutée dans une transaction SQL. Une erreur laisse la base sans génération partielle. Corriger la cause, relancer le dry-run et refaire valider l’aperçu.

### Erreur constatée après une confirmation réussie

Ne pas modifier ou supprimer manuellement la transaction AN et ne pas relancer la commande. Stopper les saisies sur l’exercice cible et choisir l’une des procédures suivantes :

1. si aucune donnée n’a été saisie depuis la reprise, restaurer la sauvegarde prise avant confirmation ;
2. sinon, faire préparer une invalidation comptable contrôlée avec conservation de la piste d’audit avant toute nouvelle génération.

La réouverture/reclôture standard s’applique aux AN issus d’une clôture annuelle. Elle ne doit pas être détournée pour corriger silencieusement une reprise initiale.

## 8. Traçabilité à conserver

- sauvegarde utilisée comme point de retour ;
- commande de dry-run et sortie complète ;
- choix `inclus` ou `exclus` et justification ;
- identité de l’acteur ayant confirmé ;
- identifiants de génération et de pièce AN ;
- résultat des contrôles immédiats.
