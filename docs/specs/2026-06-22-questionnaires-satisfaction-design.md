# Questionnaires de satisfaction et sondages — Design

> Spec de conception (brainstorming). La cible complete est decrite ici ;
> l'implementation doit se faire par lots, en commencant par le besoin primaire :
> questionnaires de satisfaction lies aux operations.

**Date :** 2026-06-22
**Branche cible :** `main`
**Statut :** en attente de validation utilisateur

---

## 1. Contexte & probleme

AgoraGestion gere deja les operations, les participants, les seances et les
communications. A l'issue de chaque formation ou parcours, l'association a besoin de
recueillir des retours de satisfaction. Ce besoin est recurrent et varie selon les
types d'operations : une formation courte, un parcours therapeutique ou un suivi a
froid ne posent pas exactement les memes questions.

Aujourd'hui, le contournement naturel serait un lien externe vers un outil de
questionnaire. Cela resout la saisie, mais deplace hors d'AgoraGestion des informations
qui sont pourtant metier : operation concernee, participants invites, relances, taux de
reponse, anonymat et exports exploitables.

Le besoin n'est pas un questionnaire hard-code une fois pour toutes. Il faut un moteur
simple, configurable par l'administrateur, capable d'assembler des questions types pour
produire des questionnaires reutilisables.

## 2. Objectifs

Livrer un moteur interne de questionnaires, limite mais propre, avec une premiere
experience centree sur les questionnaires de satisfaction d'operations.

Objectifs de la V1 :

- creer des modeles de questionnaires reutilisables ;
- assembler une liste ordonnee de questions typees ;
- marquer chaque question comme obligatoire ou facultative ;
- creer une campagne de questionnaire rattachee a une operation ;
- inviter les participants via un lien personnel securise ;
- collecter les reponses avec anonymat par defaut ;
- permettre au repondant de lever volontairement l'anonymat pour etre recontacte ;
- consulter les resultats dans AgoraGestion ;
- exporter les reponses au format Excel pour analyse hors logiciel ;
- imprimer un questionnaire papier avec QR code individuel ;
- permettre une saisie assistee par scan/OCR, toujours validee par un humain avant
  sauvegarde.

## 3. Principes de conception

### 3.1 Moteur interne simple

Le choix recommande est un moteur interne a AgoraGestion plutot qu'une integration
LimeSurvey. LimeSurvey reste une reference fonctionnelle utile, mais son integration
creerait une seconde application a administrer et compliquerait le lien avec les
operations, participants, communications, relances et tableaux de bord metier.

Le moteur interne ne cherche pas a reproduire LimeSurvey. Il couvre le besoin metier
principal et garde des limites explicites : pas de logique conditionnelle, pas de
matrices, pas de scoring avance, pas de branchements complexes en V1.

### 3.2 Donnees structurees, pas formulaire opaque

Les questions et les reponses sont stockees de maniere structuree. Un questionnaire ne
doit pas etre un gros bloc JSON opaque, car les resultats doivent etre affichables,
exportables et testables proprement.

Un champ JSON reste acceptable pour les options des questions qui en ont besoin
(choix unique radio/combo), mais le coeur du modele reste relationnel :
questionnaires, questions, campagnes, invitations, reponses.

### 3.3 Anonymat honnete

Les reponses sont anonymes par defaut dans l'interface d'analyse. En pratique, pour des
parcours de 7 a 12 participants, l'anonymat est relatif : un verbatim ou une situation
particuliere peut indirectement identifier une personne.

L'application ne promet donc pas un anonymat absolu. Elle doit parler de questionnaire
confidentiel ou non nominatif, avec une mention claire indiquant que les petits groupes
peuvent rendre certains retours reconnaissables.

Le repondant peut choisir explicitement d'etre recontacte. Dans ce cas seulement,
l'identite du participant peut etre exposee a l'equipe sur cette reponse.

### 3.4 Collecte hybride papier + numerique

Le questionnaire doit pouvoir exister sous deux formes pour une meme invitation :

- un lien en ligne, utilisable directement depuis un email ou un QR code ;
- une feuille papier imprimable, remplissable au stylo.

Le QR code imprime sur la feuille porte l'identifiant securise de l'invitation. Il a
deux usages :

- si la personne a un smartphone, elle scanne le QR code et repond en ligne ;
- si elle remplit la feuille, le QR code permet de rattacher le scan a la bonne
  campagne, au bon questionnaire et a la bonne invitation lors de la saisie assistee.

Le scan papier ne doit jamais etre sauvegarde automatiquement comme reponse definitive.
L'IA/OCR produit une proposition de saisie ; un assistant humain la relit, corrige et
valide avant enregistrement en base.

## 4. Perimetre fonctionnel V1

### 4.1 Modeles de questionnaires

Un modele de questionnaire est reutilisable et independant d'une operation precise.
Il contient :

- un titre interne ;
- un titre affiche au repondant ;
- un texte d'introduction ;
- un message de remerciement affiche apres completion ;
- une liste ordonnee de questions ;
- un statut actif/inactif.

Un modele peut etre associe manuellement a une campagne. Une association automatique a
un type d'operation peut etre ajoutee dans un lot ulterieur.

### 4.2 Questions

Chaque question contient :

- un libelle ;
- une aide optionnelle ;
- un type ;
- un ordre d'affichage ;
- un statut obligatoire/facultatif ;
- une configuration optionnelle selon le type.

Types de questions V1 :

| Type | Saisie repondant | Valeur stockee |
|---|---|---|
| Texte court | champ texte mono ligne | string |
| Texte long | champ multi lignes | string |
| Satisfaction 5 niveaux | tres insatisfait a tres satisfait | entier 1-5 |
| Ressenti | curseur aveugle 0-100 sans feedback numerique | entier 0-100 |
| Case a cocher | oui/non | booleen |
| Choix unique | boutons radio ou liste deroulante | valeur d'option |

Pour le choix unique, chaque option a :

- un libelle affiche ;
- une valeur technique stable, generee automatiquement au depart ;
- un ordre.

Le rendu peut etre :

- boutons radio pour peu d'options ;
- combo box si la liste devient plus longue.

La V1 peut choisir automatiquement le rendu selon le nombre d'options, ou proposer un
parametre simple `radio` / `select`.

### 4.3 Campagnes

Une campagne est une utilisation concrete d'un modele. En V1, elle est rattachee a une
operation.

Une operation peut avoir plusieurs campagnes :

- questionnaire de fin d'operation ;
- questionnaire intermediaire ;
- questionnaire apres une seance cle ;
- suivi a froid a J+30 ou J+90.

La V1 doit au minimum permettre la creation manuelle d'une campagne depuis une
operation. Les declenchements automatiques peuvent venir plus tard.

Statuts proposes :

- `brouillon` : campagne preparee, pas encore envoyee ;
- `ouverte` : invitations actives, reponses acceptees ;
- `cloturee` : reponses bloquees, resultats conserves ;
- `archivee` : masquee des vues courantes.

### 4.4 Invitations

Chaque participant concerne recoit une invitation personnelle avec un token securise.
Le token sert a :

- ouvrir le questionnaire sans compte portail obligatoire ;
- eviter les doublons ;
- connaitre l'etat d'avancement : non ouvert, commence, soumis ;
- permettre les relances.

Le lien personnel ne doit pas rendre l'identite visible dans les resultats par defaut.
Techniquement, l'application connait l'invitation qui a soumis la reponse, mais
l'interface d'analyse masque cette relation sauf consentement explicite.

### 4.5 Parcours repondant

Le parcours repondant reste volontairement simple :

1. page d'introduction ;
2. une question par page ;
3. bloc final de consentement optionnel au contact ;
4. message de remerciement.

Les questions obligatoires bloquent le passage a la page suivante tant qu'elles ne sont
pas renseignees. Les questions facultatives peuvent etre sautees.

Le curseur de ressenti est affiche sans valeur numerique. La valeur 0-100 est tout de
meme stockee pour analyse et export.

### 4.6 Resultats

Depuis une campagne, l'administrateur voit :

- nombre d'invitations ;
- nombre de reponses soumises ;
- taux de reponse ;
- statistiques simples pour les questions structurees ;
- verbatims pour les textes courts/longs ;
- identite du repondant uniquement si celui-ci a accepte d'etre recontacte.

Les resultats doivent rester lisibles meme avec peu de reponses. Pour les petits
groupes, l'interface doit eviter toute formulation laissant croire a un anonymat absolu.

### 4.7 Export Excel

L'export Excel est un livrable V1.

Depuis l'ecran des resultats d'une campagne, l'administrateur peut exporter un `.xlsx`
avec :

- une ligne par reponse complete ;
- colonnes de contexte : association, type d'operation, operation, campagne, date de
  soumission ;
- colonnes d'anonymat : reponse confidentielle, a accepte le contact ;
- colonnes d'identite uniquement si le repondant a accepte d'etre recontacte ;
- une colonne par question ;
- pour les choix uniques : libelle choisi, et eventuellement valeur technique dans une
  colonne secondaire si utile ;
- pour le curseur de ressenti : valeur 0-100 ;
- pour la satisfaction : valeur 1-5 et libelle associe si necessaire.

Les en-tetes doivent rester stables pour faciliter l'analyse hors logiciel.

### 4.8 Impression papier avec QR code

Depuis une campagne, l'administrateur peut imprimer les questionnaires des participants
selectionnes.

Chaque questionnaire imprime contient :

- le titre affiche ;
- le texte d'introduction ;
- la liste des questions dans l'ordre ;
- les consignes de remplissage papier ;
- le bloc de consentement optionnel au contact ;
- un message de remerciement court ;
- un QR code individuel ;
- un identifiant lisible court, utile si le QR code est abime ou mal scanne.

La mise en page papier peut afficher plusieurs questions par page. La contrainte "une
question par page" concerne uniquement le parcours en ligne.

Le QR code encode une URL publique tokenisee, equivalente au lien d'invitation. Il ne
doit pas contenir de donnees personnelles en clair. Toute resolution vers le participant
se fait cote serveur apres verification du token.

### 4.9 Scan, OCR et assistant de saisie

Apres un atelier, l'administrateur peut scanner les feuilles remplies et les deposer
dans AgoraGestion.

Deux canaux d'entree sont prevus :

- **upload manuel** depuis l'ecran de suivi de la campagne, via un bouton "Ajouter une
  reponse papier" ou "Ajouter un scan" ;
- **reception par email** : l'administrateur envoie le scan en piece jointe a une
  adresse technique AgoraGestion, qui cree une entree de traitement a partir du mail.

Flux cible :

1. upload d'un scan ou lot de scans ;
2. detection du QR code sur chaque feuille ;
3. rattachement a la campagne et a l'invitation ;
4. OCR et analyse IA des zones de reponse ;
5. generation d'un brouillon de reponse ;
6. affichage dans un assistant de saisie ;
7. validation/correction humaine ;
8. sauvegarde definitive en base.

L'assistant de saisie doit montrer, question par question :

- l'image source ou l'extrait de scan correspondant ;
- la valeur proposee par l'IA ;
- un niveau de confiance si disponible ;
- le champ de correction manuel ;
- les alertes de validation pour les questions obligatoires.

Si le QR code est illisible, l'utilisateur peut rattacher manuellement le scan a une
invitation via l'identifiant court imprime ou via une recherche participant/campagne.

Si une invitation a deja une reponse soumise, l'assistant bloque la sauvegarde par
defaut et propose une action explicite : ignorer le scan, remplacer la reponse, ou
creer une nouvelle version si ce cas est retenu au plan.

## 5. Architecture proposee

### 5.1 Tables principales

Noms indicatifs, a affiner au plan d'implementation :

| Table | Role |
|---|---|
| `questionnaire_templates` | modeles reutilisables |
| `questionnaire_questions` | questions ordonnees d'un modele |
| `questionnaire_campaigns` | instance concrete d'un modele, rattachee a une operation |
| `questionnaire_invitations` | invitation personnelle d'un participant |
| `questionnaire_submissions` | reponse soumise par une invitation |
| `questionnaire_answers` | valeur d'une question pour une soumission |
| `questionnaire_paper_batches` | lots d'impression ou de scans papier |
| `questionnaire_paper_scans` | scan papier rattache a une invitation |
| `questionnaire_ocr_drafts` | brouillon IA/OCR a valider avant sauvegarde |

Tous les modeles tenant-scopes etendent `TenantModel`.

### 5.2 Relations

```
Association
  └─ QuestionnaireTemplate
       └─ QuestionnaireQuestion

Operation
  └─ QuestionnaireCampaign
       ├─ QuestionnaireInvitation → Participant
       │    └─ QuestionnairePaperScan
       └─ QuestionnaireSubmission
            └─ QuestionnaireAnswer → QuestionnaireQuestion
```

Une soumission est rattachee a une invitation. L'identite du participant est donc
techniquement recuperable, mais masquee par defaut dans les vues et exports.

### 5.3 Stockage des reponses

`questionnaire_answers` stocke une valeur normalisee selon le type :

- texte : `value_text` ;
- entier : `value_integer` ;
- booleen : `value_boolean` ;
- choix unique : `value_option`;
- meta optionnelle : JSON pour figer le libelle choisi au moment de la reponse.

Le plan d'implementation tranchera entre colonnes typees separees ou `value_json`.
La preference de conception est de conserver des valeurs exploitables directement,
surtout pour l'export Excel et les statistiques.

### 5.4 Services metier

Services attendus :

- creation d'une campagne depuis un modele et une operation ;
- generation des invitations ;
- validation et enregistrement d'une reponse page par page ou en une fois ;
- cloture de campagne ;
- agregation des resultats ;
- generation de l'export Excel.
- generation du PDF papier avec QR codes ;
- upload manuel des scans depuis le suivi de campagne ;
- ingestion de scans recus par email avec piece jointe ;
- rattachement des scans a une invitation via QR code ou identifiant court ;
- production d'un brouillon OCR/IA ;
- validation humaine d'un brouillon OCR vers une soumission definitive.

Les controllers et composants Livewire restent minces et deleguent la logique metier
aux services.

## 6. Integration communication

La V1 doit pouvoir envoyer ou preparer les invitations via le module de communication.
Les templates d'email existants pourront recevoir un nouveau placeholder de type :

- `{lien_questionnaire}` ;
- `{operation}` ;
- `{type_operation}` ;
- `{prenom}`.

Le cas minimum acceptable est un bouton d'envoi depuis la campagne, qui cree les logs
email habituels et envoie un message aux participants selectionnes.

Les relances automatiques ne sont pas obligatoires en V1, mais le modele d'invitation
doit les permettre : statut, date d'envoi, date d'ouverture, date de soumission.

Le QR code papier reutilise le meme principe que le lien email : un token long, non
devinable, resolu cote serveur. Les supports de communication peuvent donc converger
vers une meme URL de reponse.

Pour le canal papier, le module communication peut aussi fournir une adresse de
reception technique par association ou par instance. Un scan recu par email n'est pas
une reponse : c'est une piece entrante a rattacher, analyser et valider dans
l'assistant de saisie.

## 7. Hors perimetre V1

- questionnaires publics sans invitation ;
- anti-spam et limitation de formulaires ouverts sur internet ;
- logique conditionnelle entre questions ;
- branchements selon les reponses ;
- matrices, grilles complexes, scoring avance ;
- publication de resultats ;
- declenchements automatiques planifies ;
- association automatique d'un modele a un type d'operation ;
- edition collaborative ou versioning avance des modeles.
- sauvegarde automatique de reponses OCR sans validation humaine ;
- reconnaissance parfaite de l'ecriture manuscrite ;
- traitement OCR en temps reel pendant l'atelier.

Ces sujets restent compatibles avec le modele, mais ne doivent pas alourdir le premier
lot.

## 8. Lots de livraison proposes

| Lot | Contenu | Livrable |
|---|---|---|
| Lot 1 — Fondations | modeles, questions typees, ecran d'edition simple | un admin peut creer un modele |
| Lot 2 — Campagnes operation | campagne rattachee a une operation, invitations participants | une operation peut lancer un questionnaire |
| Lot 3 — Parcours repondant | lien tokenise, intro, une question par page, validation obligatoire, remerciement | un participant peut repondre |
| Lot 4 — Resultats & anonymat | stats simples, verbatims, consentement de contact | l'admin consulte les resultats sans identite par defaut |
| Lot 5 — Export Excel | export `.xlsx` structure par campagne | analyse hors logiciel possible |
| Lot 6 — Impression papier | PDF imprimable par campagne avec QR code individuel | un atelier peut distribuer des questionnaires papier |
| Lot 7 — Scan & saisie assistee | upload manuel, reception email, lecture QR, brouillon OCR/IA, assistant de validation | les reponses papier peuvent etre saisies avec controle humain |
| Lot 8 — Communication avancee | placeholders, relances manuelles, suivi d'envoi | integration plus fine avec les communications |

Les lots 1 a 5 constituent le minimum coherent pour le besoin primaire numerique. Les
lots 6 et 7 ajoutent le canal papier sans changer le modele de reponse final.

## 9. Tests & recette

Tests unitaires :

- creation d'un modele avec questions ordonnees ;
- validation des questions obligatoires par type ;
- stockage des valeurs typees ;
- soumission anonyme par defaut ;
- exposition de l'identite uniquement si consentement au contact ;
- agregation satisfaction, checkbox, choix unique et curseur ;
- export Excel avec colonnes stables.

Tests feature / Livewire :

- creation et edition d'un modele ;
- creation d'une campagne depuis une operation ;
- generation d'invitations pour les participants ;
- parcours repondant complet ;
- blocage sur question obligatoire non renseignee ;
- affichage du message de fin ;
- consultation des resultats ;
- telechargement de l'export Excel.
- generation d'un PDF papier contenant un QR code par invitation ;
- upload manuel d'un scan et rattachement via QR code ;
- creation d'une entree de traitement depuis un email avec scan en piece jointe ;
- validation d'un brouillon OCR dans l'assistant de saisie.

Recette manuelle :

1. creer un modele de satisfaction avec les 6 types de questions ;
2. creer une campagne sur une operation de demo ;
3. generer les invitations ;
4. soumettre une reponse anonyme ;
5. soumettre une reponse avec consentement au contact ;
6. verifier que seule la deuxieme expose l'identite ;
7. exporter Excel et verifier les colonnes ;
8. imprimer un questionnaire papier avec QR code ;
9. scanner une feuille remplie ;
10. valider la proposition OCR avant sauvegarde.

## 10. Risques & points d'attention

- **Anonymat relatif** : ne jamais promettre plus que ce que le contexte de petits
  groupes permet.
- **Verbatims identifiants** : meme sans nom, un texte libre peut reveler une personne.
- **Modele trop generique** : resister a la tentation de reproduire LimeSurvey en V1.
- **Export Excel** : figer les libelles de questions au moment de la campagne ou de la
  soumission pour eviter qu'une modification de modele ne rende les exports ambigus.
- **Edition apres lancement** : une campagne ouverte ne doit pas etre fragilisee par des
  modifications de modele. Le plan devra trancher entre snapshot du modele a la creation
  de campagne ou verrouillage des questions apres envoi.
- **Tokens** : les liens doivent etre longs, non devinables, expirables/cloturables.
- **QR code papier** : il identifie techniquement l'invitation. L'anonymat reste donc
  une politique d'affichage et d'export, pas une absence de lien technique.
- **OCR manuscrit** : les erreurs sont probables. L'IA ne doit produire qu'un brouillon
  relu et valide par un humain.
- **Donnees sensibles dans les scans** : les fichiers papier numerises peuvent contenir
  des verbatims identifiants. Leur stockage doit suivre les memes regles tenant et
  d'acces que les autres documents sensibles.
- **Multi-tenant** : toutes les requetes doivent rester fail-closed via `TenantModel`.

## 11. Questions a trancher au plan

- Snapshot complet du modele dans la campagne, ou verrouillage du modele des qu'une
  campagne l'utilise ?
- Les campagnes peuvent-elles cibler seulement certains participants d'une operation en
  V1 ?
- Le rendu radio/select du choix unique est-il automatique ou parametre par l'admin ?
- Les reponses partielles sont-elles conservees avant soumission finale ?
- Une invitation peut-elle etre reouverte apres soumission, ou la reponse est-elle
  definitive ?
- Le questionnaire papier doit-il etre un PDF par participant, un PDF groupe, ou les
  deux ?
- Le scan papier peut-il remplacer une reponse deja soumise en ligne ?
- Quelle duree de conservation pour les scans originaux apres validation OCR ?
- Quel fournisseur IA/OCR utiliser, et avec quelles garanties de confidentialite ?
- L'adresse email de reception des scans est-elle globale, par association, ou par
  campagne ?
