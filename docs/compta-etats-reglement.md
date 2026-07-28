# États de règlement — statuts, libellés UI et étapes comptables

**Date de référence** : 2026-07-18  
**Objet** : fixer le vocabulaire fonctionnel et comptable des états de règlement pour AgoraGestion, afin d'éviter les ambiguïtés entre ancien vocabulaire, libellés affichés et modèle partie double.

---

## 1. Trois niveaux à ne pas mélanger

| Niveau | Rôle | Exemples |
|---|---|---|
| État métier | Ce qui se passe réellement dans la vie de l'association | « chèque reçu mais pas encore déposé », « facture fournisseur payée » |
| Statut technique | Valeur système utilisée par le code, les filtres et les gardes métier | `en_attente`, `en_main`, `recu`, `pointe` |
| Libellé UI | Texte affiché à l'utilisateur selon le sens de la transaction | « Dû », « À remettre », « Remis », « Réglé », « Pointé » |

Un même statut technique peut avoir plusieurs libellés UI selon le sens de trésorerie.

Exemple : `recu` signifie techniquement « règlement dénoué ». Sur une recette il s'affiche « Remis » ; sur une dépense il s'affiche « Réglé ».

---

## 2. Statuts techniques canoniques

| Statut technique | Sens fonctionnel | Libellé recette | Libellé dépense | Preuve comptable générale |
|---|---|---|---|---|
| `en_attente` | Créance ou dette ouverte | Dû | Dû | ligne tiers 411/401 non lettrée |
| `en_main` | Valeur reçue physiquement mais non déposée | À remettre | Non utilisé | 411 lettré, puis 5112 ou 530 non lettré |
| `recu` | Règlement dénoué, non forcément rapproché | Remis | Réglé | tiers lettré et mouvement de trésorerie comptabilisé |
| `pointe` | Règlement rapproché | Pointé | Pointé | transaction porteuse du 512 associée à un rapprochement bancaire |

Décision fonctionnelle : `en_main` est réservé aux recettes chèque/espèces. Les dépenses n'ont pas de notion « en main » dans le périmètre associatif d'AgoraGestion.

---

## 3. Cycle recette par chèque ou espèces

Ce cycle concerne les recettes dont le règlement est reçu physiquement puis déposé plus tard.

| Étape | Événement métier | Statut attendu | Libellé UI attendu | Écriture / preuve comptable |
|---|---|---|---|---|
| 1 | Recette créée, règlement pas encore reçu | `en_attente` | Dû | T1 seule : 411 débit non lettré, 7xx crédit |
| 2 | Clic « Marquer reçu » / paiement reçu | `en_main` | À remettre | T2 créée : 5112 débit pour chèque, ou 530 débit pour espèces ; 411 crédit ; 411 T1 ↔ 411 T2 lettrés |
| 3 | Remise bancaire comptabilisée | `recu` | Remis | T4 créée : 512 débit ; 5112 ou 530 crédit ; 5112/530 T2 ↔ 5112/530 T4 lettrés |
| 4 | Rapprochement bancaire | `pointe` | Pointé | transaction porteuse du 512 rattachée au rapprochement bancaire |

Pour les remises bancaires, chèques et espèces ne se mélangent pas dans une même remise. Une remise a un type unique : chèque ou espèces.

---

## 4. Cycle recette par virement ou carte bancaire directe

Ces modes n'ont pas d'étape « en main » : le paiement va directement vers un compte de trésorerie bancaire.

| Étape | Événement métier | Statut attendu | Libellé UI attendu | Écriture / preuve comptable |
|---|---|---|---|---|
| 1 | Recette créée à crédit, règlement pas encore reçu | `en_attente` | Dû | T1 seule : 411 débit non lettré, 7xx crédit |
| 2 | Clic « Marquer reçu » / paiement reçu | `recu` | Remis | T2 créée : 512 débit ; 411 crédit ; 411 T1 ↔ 411 T2 lettrés |
| 3 | Rapprochement bancaire | `pointe` | Pointé | T2 porteuse du 512 rattachée au rapprochement bancaire |

Si la recette est créée directement comme paiement reçu, le moteur peut créer T1 + T2 dans la même action. Le résultat attendu reste identique : 411 lettré et statut `recu` tant que le mouvement 512 n'est pas rapproché.

---

## 5. Cycle dépense, tous modes associatifs

Décision fonctionnelle : pour une association, le clic « Marquer payé » constate le paiement. On ne modélise pas les états intermédiaires des grosses structures comme « chèque imprimé non envoyé » ou « lot de virements préparé ».

| Étape | Événement métier | Statut attendu | Libellé UI attendu | Écriture / preuve comptable |
|---|---|---|---|---|
| 1 | Dépense créée, facture fournisseur pas encore payée | `en_attente` | Dû | T1 seule : 6xx débit, 401 crédit non lettré |
| 2 | Clic « Marquer payé » / paiement effectué | `recu` | Réglé | T2 créée : 401 débit ; trésorerie créditée ; 401 T1 ↔ 401 T2 lettrés |
| 3 | Rapprochement bancaire si le paiement passe par banque | `pointe` | Pointé | T2 porteuse du 512 rattachée au rapprochement bancaire |

Notes par mode :

| Mode dépense | Compte de trésorerie attendu | Remarque |
|---|---|---|
| Virement | 512 crédit | Cas standard |
| Carte bancaire | 512 crédit | Cas standard |
| Chèque émis | 512 crédit dans le modèle associatif actuel | Pas d'état `en_main` |
| Espèces | 530 crédit | Payé depuis la caisse ; pas forcément rapprochable en banque |

---

## 6. HelloAsso

HelloAsso doit être lu avec une distinction entre moyen de paiement déclaré par HelloAsso et flux bancaire réel.

| Origine HelloAsso | Mode résolu | Statut actuel à l'import | Compte cible actuel | Lecture fonctionnelle |
|---|---|---|---|---|
| CB | `cb` | `recu` | compte HelloAsso | paiement encaissé par HelloAsso, cashout ultérieur vers banque |
| SEPA / prélèvement | `prelevement` | `recu` | compte HelloAsso | paiement encaissé par HelloAsso, cashout ultérieur vers banque |
| Chèque | `cheque` | `en_attente` | compte de versement configuré, fallback compte HelloAsso | à traiter comme règlement non encore constaté localement |
| Espèces | `especes` | `en_attente` | compte de versement configuré, fallback compte HelloAsso | à traiter comme règlement non encore constaté localement |
| Virement | `virement` | `en_attente` | compte de versement configuré, fallback compte HelloAsso | à traiter comme règlement non encore constaté localement |

Point de vigilance : les flux HelloAsso chèque/espèces/virement restent à vérifier en recette fonctionnelle. La règle ci-dessus documente le comportement actuel et l'intention générale : ne pas assimiler automatiquement tous les moyens HelloAsso à un paiement bancaire reçu localement.

---

## 7. Règles de workflow importantes

| Situation | Règle attendue |
|---|---|
| Retirer une source d'un brouillon de remise | La transaction revient à `en_main` / « À remettre » : elle n'est plus dans cette remise, mais le paiement reste reçu |
| Annuler le fait qu'un paiement a été reçu | Action explicite via le toggle paiement reçu/payé sur la transaction ; ce n'est pas le rôle de la modification de remise |
| Supprimer une remise brouillon | Les sources reviennent à l'état cohérent avec leur trace comptable, généralement `en_main` pour recettes chèque/espèces reçues |
| Comptabiliser une remise | Les sources passent à `recu` / « Remis » et une T4 est créée |
| Pointer une transaction bancaire | Le statut devient `pointe` / « Pointé » lorsque le mouvement 512 est rattaché au rapprochement |

Cette règle explique le constat N-5 de la recette fonctionnelle 2026-07 : retirer un chèque d'un brouillon de remise ne doit pas remettre la transaction à `en_attente` / « Dû ».

---

## 8. Résumé compact

| Sens | Modes | Cycle attendu |
|---|---|---|
| Recette | Chèque, espèces | `en_attente` → `en_main` → `recu` → `pointe` |
| Recette | Virement, CB directe | `en_attente` → `recu` → `pointe` |
| Recette | HelloAsso CB/SEPA | `recu` → `pointe` via cashout / rapprochement |
| Recette | HelloAsso chèque/espèces/virement | `en_attente` puis cycle selon constatation du règlement |
| Dépense | Tous modes associatifs | `en_attente` → `recu` → `pointe` si mouvement bancaire rapprochable |

