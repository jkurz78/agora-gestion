-- anonymize-tiers.sql
-- Anonymise les données personnelles après un clone de prod → staging.
-- À exécuter dans la base de données staging UNIQUEMENT.
--
-- Principe : remplacer les données existantes par des données fictives
-- réalistes (prénoms, noms, adresses des Yvelines). Les champs NULL restent NULL.
-- Les données chiffrées (participant_donnees_medicales) sont traitées par
-- la commande artisan staging:anonymize-medical (après ce script), qui
-- corrige aussi les prénoms des participants selon leur sexe (M/F).

-- ═══════════════════════════════════════════════════════════════════════
-- 1. TABLES DE RÉFÉRENCE TEMPORAIRES
-- ═══════════════════════════════════════════════════════════════════════

DROP TEMPORARY TABLE IF EXISTS tmp_prenoms;
CREATE TEMPORARY TABLE tmp_prenoms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prenom VARCHAR(50),
    slug VARCHAR(50)
);
INSERT INTO tmp_prenoms (prenom, slug) VALUES
('Marie','marie'),('Jean','jean'),('Pierre','pierre'),('Catherine','catherine'),
('François','francois'),('Isabelle','isabelle'),('Michel','michel'),('Nathalie','nathalie'),
('Philippe','philippe'),('Sophie','sophie'),('Patrick','patrick'),('Véronique','veronique'),
('Nicolas','nicolas'),('Émilie','emilie'),('Christophe','christophe'),('Céline','celine'),
('Laurent','laurent'),('Sandrine','sandrine'),('Thierry','thierry'),('Valérie','valerie'),
('Éric','eric'),('Stéphanie','stephanie'),('Frédéric','frederic'),('Caroline','caroline'),
('Olivier','olivier'),('Julie','julie'),('David','david'),('Aurélie','aurelie'),
('Sébastien','sebastien'),('Mélanie','melanie'),('Alexandre','alexandre'),('Charlotte','charlotte'),
('Thomas','thomas'),('Camille','camille'),('Antoine','antoine'),('Léa','lea'),
('Julien','julien'),('Marine','marine'),('Maxime','maxime'),('Chloé','chloe'),
('Romain','romain'),('Clara','clara'),('Hugo','hugo'),('Alice','alice'),
('Lucas','lucas'),('Emma','emma'),('Gabriel','gabriel'),('Louise','louise'),
('Louis','louis'),('Manon','manon'),('Arthur','arthur'),('Inès','ines'),
('Raphaël','raphael'),('Jade','jade'),('Léo','leo'),('Sarah','sarah'),
('Nathan','nathan'),('Laura','laura'),('Mathis','mathis'),('Pauline','pauline'),
('Adam','adam'),('Anaïs','anais'),('Paul','paul'),('Margaux','margaux'),
('Mathieu','mathieu'),('Justine','justine'),('Théo','theo'),('Océane','oceane'),
('Clément','clement'),('Amandine','amandine'),('Benjamin','benjamin'),('Élisa','elisa'),
('Vincent','vincent'),('Marion','marion'),('Alexis','alexis'),('Agathe','agathe'),
('Simon','simon'),('Anna','anna'),('Victor','victor'),('Lucie','lucie'),
('Adrien','adrien'),('Eva','eva'),('Quentin','quentin'),('Morgane','morgane'),
('Florian','florian'),('Noémie','noemie'),('Dylan','dylan'),('Alicia','alicia'),
('Jordan','jordan'),('Romane','romane'),('Kévin','kevin'),('Maëlys','maelys'),
('Jérôme','jerome'),('Lola','lola'),('Yann','yann'),('Zoé','zoe'),
('Arnaud','arnaud'),('Léonie','leonie'),('Guillaume','guillaume'),('Lisa','lisa'),
('Damien','damien'),('Coralie','coralie'),('Aurélien','aurelien'),('Ambre','ambre'),
('Fabien','fabien'),('Mélissa','melissa'),('Xavier','xavier'),('Solène','solene'),
('Denis','denis'),('Gaëlle','gaelle'),('Cédric','cedric'),('Laure','laure'),
('Mehdi','mehdi'),('Fatima','fatima'),('Karim','karim'),('Aïcha','aicha'),
('Youssef','youssef'),('Zineb','zineb'),('Omar','omar'),('Samira','samira'),
('Ali','ali'),('Leïla','leila'),('Rachid','rachid'),('Nadia','nadia'),
('Moussa','moussa'),('Hawa','hawa'),('Ibrahim','ibrahim'),('Mariam','mariam'),
('Rémi','remi'),('Elsa','elsa'),('Bastien','bastien'),('Margot','margot'),
('Axel','axel'),('Clémence','clemence'),('Enzo','enzo'),('Lina','lina');

DROP TEMPORARY TABLE IF EXISTS tmp_noms;
CREATE TEMPORARY TABLE tmp_noms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(50),
    slug VARCHAR(50)
);
INSERT INTO tmp_noms (nom, slug) VALUES
('Martin','martin'),('Bernard','bernard'),('Dubois','dubois'),('Thomas','thomas'),
('Robert','robert'),('Richard','richard'),('Petit','petit'),('Durand','durand'),
('Leroy','leroy'),('Moreau','moreau'),('Simon','simon'),('Laurent','laurent'),
('Lefebvre','lefebvre'),('Michel','michel'),('Garcia','garcia'),('David','david'),
('Bertrand','bertrand'),('Roux','roux'),('Vincent','vincent'),('Fournier','fournier'),
('Morel','morel'),('Girard','girard'),('André','andre'),('Lefèvre','lefevre'),
('Mercier','mercier'),('Dupont','dupont'),('Lambert','lambert'),('Bonnet','bonnet'),
('François','francois'),('Martinez','martinez'),('Legrand','legrand'),('Garnier','garnier'),
('Faure','faure'),('Rousseau','rousseau'),('Blanc','blanc'),('Guérin','guerin'),
('Muller','muller'),('Henry','henry'),('Roussel','roussel'),('Nicolas','nicolas'),
('Perrin','perrin'),('Morin','morin'),('Mathieu','mathieu'),('Clément','clement'),
('Gauthier','gauthier'),('Dumont','dumont'),('Lopez','lopez'),('Fontaine','fontaine'),
('Chevalier','chevalier'),('Robin','robin'),('Masson','masson'),('Sanchez','sanchez'),
('Gérard','gerard'),('Nguyen','nguyen'),('Boyer','boyer'),('Denis','denis'),
('Lemaire','lemaire'),('Duval','duval'),('Joly','joly'),('Gautier','gautier'),
('Roger','roger'),('Renault','renault'),('Collet','collet'),('Aubert','aubert'),
('Pires','pires'),('Brun','brun'),('Da Silva','da-silva'),('Diallo','diallo'),
('Marques','marques'),('Benali','benali'),('Boucher','boucher'),('Fleury','fleury'),
('Leclercq','leclercq'),('Royer','royer'),('Picard','picard'),('Marchand','marchand'),
('Barbier','barbier'),('Gilles','gilles'),('Perez','perez'),('Dufour','dufour'),
('Fernandez','fernandez'),('Baron','baron'),('Lemoine','lemoine'),('Caron','caron'),
('Berger','berger'),('Renard','renard'),('Giraud','giraud'),('Maillard','maillard'),
('Rivière','riviere'),('Arnaud','arnaud'),('Guillot','guillot'),('Costa','costa'),
('Lacroix','lacroix'),('Adam','adam'),('Breton','breton'),('Hamon','hamon'),
('Prévost','prevost'),('Baudry','baudry'),('Rémy','remy'),('Charpentier','charpentier'),
('Blanchard','blanchard'),('Moulin','moulin'),('Vidal','vidal'),('Leblanc','leblanc'),
('Tessier','tessier'),('Pichon','pichon'),('Seguin','seguin'),('Gaillard','gaillard'),
('Hardy','hardy'),('Perrot','perrot'),('Jacquet','jacquet'),('Didier','didier'),
('Meunier','meunier'),('Bouchet','bouchet'),('Chauvin','chauvin'),('Delmas','delmas'),
('Collin','collin'),('Pasquier','pasquier'),('Regnier','regnier'),('Leclerc','leclerc');

DROP TEMPORARY TABLE IF EXISTS tmp_rues;
CREATE TEMPORARY TABLE tmp_rues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rue VARCHAR(100)
);
INSERT INTO tmp_rues (rue) VALUES
('rue de la République'),('avenue de Paris'),('rue du Général de Gaulle'),
('rue Victor Hugo'),('boulevard de la Libération'),('rue des Écoles'),
('rue de la Mairie'),('avenue des Champs'),('rue Saint-Martin'),
('rue de Verdun'),('rue Jean Jaurès'),('rue Pasteur'),
('rue de la Gare'),('rue du Commerce'),('place du Marché'),
('rue des Lilas'),('rue du Parc'),('avenue Foch'),
('rue de la Fontaine'),('rue des Vignes'),('boulevard Voltaire'),
('rue Molière'),('rue Pierre Curie'),('rue de la Croix'),
('rue des Rosiers'),('allée des Tilleuls'),('rue du Château'),
('avenue Jean Moulin'),('rue de la Liberté'),('rue des Merisiers'),
('chemin des Bois'),('rue du Moulin'),('rue de Provence'),
('impasse des Acacias'),('rue du Pont'),('rue des Peupliers'),
('rue Henri Barbusse'),('rue de l''Église'),('avenue de la Résistance'),
('rue Émile Zola'),('rue de la Paix'),('avenue du Président Wilson'),
('rue des Chênes'),('allée des Marronniers'),('rue Gambetta'),
('rue Paul Doumer'),('boulevard Carnot'),('rue de la Tour'),
('rue des Iris'),('rue du Clos');

DROP TEMPORARY TABLE IF EXISTS tmp_villes;
CREATE TEMPORARY TABLE tmp_villes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ville VARCHAR(60),
    code_postal VARCHAR(5)
);
INSERT INTO tmp_villes (ville, code_postal) VALUES
('Versailles','78000'),('Saint-Germain-en-Laye','78100'),('Le Vésinet','78110'),
('Rambouillet','78120'),('Les Mureaux','78130'),('Vélizy-Villacoublay','78140'),
('Le Chesnay-Rocquencourt','78150'),('Marly-le-Roi','78160'),
('Montigny-le-Bretonneux','78180'),('Trappes','78190'),('Mantes-la-Jolie','78200'),
('Viroflay','78220'),('Le Pecq','78230'),('Croissy-sur-Seine','78290'),
('Poissy','78300'),('Fontenay-le-Fleury','78330'),('Jouy-en-Josas','78350'),
('Plaisir','78370'),('Bois-d''Arcy','78390'),('Chatou','78400'),
('Louveciennes','78430'),('Sartrouville','78500'),('Maisons-Laffitte','78600'),
('Conflans-Sainte-Honorine','78700'),('Houilles','78800'),
('Feucherolles','78810'),('Élancourt','78990'),('Guyancourt','78280'),
('Magny-les-Hameaux','78114'),('Carrières-sous-Poissy','78955'),
('Beynes','78650'),('Orgeval','78630'),('Villennes-sur-Seine','78670'),
('Bougival','78380'),('La Celle-Saint-Cloud','78170'),('Fourqueux','78112'),
('Mareil-Marly','78750'),('L''Étang-la-Ville','78620'),('Bailly','78870'),
('Noisy-le-Roi','78590');

DROP TEMPORARY TABLE IF EXISTS tmp_providers;
CREATE TEMPORARY TABLE tmp_providers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domaine VARCHAR(30)
);
INSERT INTO tmp_providers (domaine) VALUES
('gmail.com'),('orange.fr'),('free.fr'),('sfr.fr'),('outlook.fr'),
('yahoo.fr'),('laposte.net'),('hotmail.fr');

DROP TEMPORARY TABLE IF EXISTS tmp_entreprises;
CREATE TEMPORARY TABLE tmp_entreprises (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100)
);
INSERT INTO tmp_entreprises (nom) VALUES
('Solutions Informatiques'),('Cabinet Médical du Parc'),('Boulangerie Artisanale'),
('Auto-École de la Gare'),('Pharmacie Centrale'),('Agence Immobilière du Centre'),
('Restaurant Le Provençal'),('Garage Automobile Martin'),('Cabinet d''Avocats Associés'),
('Fleuriste Les Quatre Saisons'),('Librairie du Centre'),('Menuiserie Générale'),
('Plomberie Services'),('Électricité Durand'),('Pressing du Parc'),
('Optique Vision Plus'),('Imprimerie Moderne'),('Boucherie Charcuterie'),
('Salon de Coiffure L''Éclat'),('Pâtisserie Delacroix');

DROP TEMPORARY TABLE IF EXISTS tmp_etablissements;
CREATE TEMPORARY TABLE tmp_etablissements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100)
);
INSERT INTO tmp_etablissements (nom) VALUES
('Centre Hospitalier'),('Clinique Saint-Louis'),('Maison de Retraite Les Tilleuls'),
('Foyer d''Accueil'),('EHPAD Les Jardins'),('Centre Médical'),
('Maison de Santé'),('Institut Médico-Éducatif'),('Centre de Rééducation'),
('Résidence Les Chênes');


-- ═══════════════════════════════════════════════════════════════════════
-- 2. ANONYMISATION TABLE TIERS
-- ═══════════════════════════════════════════════════════════════════════

-- Particuliers : nom, prenom, email, telephone, adresse, helloasso
UPDATE tiers t
JOIN (
    SELECT id,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_noms)) AS rn,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_prenoms)) AS rp,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_rues)) AS rr,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_villes)) AS rv,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_providers)) AS rd
    FROM tiers
    WHERE type = 'particulier'
) r ON t.id = r.id
JOIN tmp_noms n ON n.id = r.rn
JOIN tmp_prenoms p ON p.id = r.rp
JOIN tmp_rues ru ON ru.id = r.rr
JOIN tmp_villes v ON v.id = r.rv
JOIN tmp_providers d ON d.id = r.rd
SET
    t.nom            = n.nom,
    t.prenom         = CASE WHEN t.prenom IS NOT NULL THEN p.prenom ELSE NULL END,
    t.email          = CASE WHEN t.email IS NOT NULL THEN CONCAT(p.slug, '.', n.slug, '@', d.domaine) ELSE NULL END,
    t.telephone      = CASE WHEN t.telephone IS NOT NULL THEN CONCAT('06', LPAD(FLOOR(RAND() * 100000000), 8, '0')) ELSE NULL END,
    t.adresse_ligne1 = CASE WHEN t.adresse_ligne1 IS NOT NULL THEN CONCAT(FLOOR(1 + RAND() * 120), ' ', ru.rue) ELSE NULL END,
    t.code_postal    = CASE WHEN t.code_postal IS NOT NULL THEN v.code_postal ELSE NULL END,
    t.ville          = CASE WHEN t.ville IS NOT NULL THEN v.ville ELSE NULL END,
    t.date_naissance = CASE WHEN t.date_naissance IS NOT NULL THEN DATE_ADD('1950-01-01', INTERVAL FLOOR(RAND() * 20000) DAY) ELSE NULL END,
    t.helloasso_nom    = CASE WHEN t.helloasso_nom IS NOT NULL THEN n.nom ELSE NULL END,
    t.helloasso_prenom = CASE WHEN t.helloasso_prenom IS NOT NULL THEN p.prenom ELSE NULL END;

-- Entreprises : nom d'entreprise, email, telephone, adresse
UPDATE tiers t
JOIN (
    SELECT id,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_entreprises)) AS re,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_rues)) AS rr,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_villes)) AS rv,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_providers)) AS rd
    FROM tiers
    WHERE type = 'entreprise'
) r ON t.id = r.id
JOIN tmp_entreprises e ON e.id = r.re
JOIN tmp_rues ru ON ru.id = r.rr
JOIN tmp_villes v ON v.id = r.rv
JOIN tmp_providers d ON d.id = r.rd
SET
    t.nom            = e.nom,
    t.entreprise     = CASE WHEN t.entreprise IS NOT NULL THEN e.nom ELSE NULL END,
    t.email          = CASE WHEN t.email IS NOT NULL THEN CONCAT('contact', t.id, '@', d.domaine) ELSE NULL END,
    t.telephone      = CASE WHEN t.telephone IS NOT NULL THEN CONCAT('01', LPAD(FLOOR(RAND() * 100000000), 8, '0')) ELSE NULL END,
    t.adresse_ligne1 = CASE WHEN t.adresse_ligne1 IS NOT NULL THEN CONCAT(FLOOR(1 + RAND() * 120), ' ', ru.rue) ELSE NULL END,
    t.code_postal    = CASE WHEN t.code_postal IS NOT NULL THEN v.code_postal ELSE NULL END,
    t.ville          = CASE WHEN t.ville IS NOT NULL THEN v.ville ELSE NULL END;


-- ═══════════════════════════════════════════════════════════════════════
-- 3. ANONYMISATION TABLE PARTICIPANTS
-- ═══════════════════════════════════════════════════════════════════════

UPDATE participants p
JOIN (
    SELECT id,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_noms)) AS rn,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_prenoms)) AS rp,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_rues)) AS rr,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_villes)) AS rv,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_providers)) AS rd,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_etablissements)) AS ret
    FROM participants
) r ON p.id = r.id
JOIN tmp_noms n ON n.id = r.rn
JOIN tmp_prenoms pr ON pr.id = r.rp
JOIN tmp_rues ru ON ru.id = r.rr
JOIN tmp_villes v ON v.id = r.rv
JOIN tmp_providers d ON d.id = r.rd
JOIN tmp_etablissements et ON et.id = r.ret
SET
    p.nom_jeune_fille       = CASE WHEN p.nom_jeune_fille IS NOT NULL THEN n.nom ELSE NULL END,
    p.nationalite           = CASE WHEN p.nationalite IS NOT NULL THEN 'Française' ELSE NULL END,
    p.adresse_par_nom       = CASE WHEN p.adresse_par_nom IS NOT NULL THEN n.nom ELSE NULL END,
    p.adresse_par_prenom    = CASE WHEN p.adresse_par_prenom IS NOT NULL THEN pr.prenom ELSE NULL END,
    p.adresse_par_etablissement = CASE WHEN p.adresse_par_etablissement IS NOT NULL THEN et.nom ELSE NULL END,
    p.adresse_par_telephone = CASE WHEN p.adresse_par_telephone IS NOT NULL THEN CONCAT('06', LPAD(FLOOR(RAND() * 100000000), 8, '0')) ELSE NULL END,
    p.adresse_par_email     = CASE WHEN p.adresse_par_email IS NOT NULL THEN CONCAT(pr.slug, '.', n.slug, '@', d.domaine) ELSE NULL END,
    p.adresse_par_adresse   = CASE WHEN p.adresse_par_adresse IS NOT NULL THEN CONCAT(FLOOR(1 + RAND() * 120), ' ', ru.rue) ELSE NULL END,
    p.adresse_par_code_postal = CASE WHEN p.adresse_par_code_postal IS NOT NULL THEN v.code_postal ELSE NULL END,
    p.adresse_par_ville     = CASE WHEN p.adresse_par_ville IS NOT NULL THEN v.ville ELSE NULL END,
    p.notes                 = CASE WHEN p.notes IS NOT NULL THEN NULL ELSE NULL END;


-- ═══════════════════════════════════════════════════════════════════════
-- 4. DONNÉES MÉDICALES CHIFFRÉES
--    Traitées entièrement par artisan staging:anonymize-medical
--    (besoin de déchiffrer le sexe pour attribuer des prénoms cohérents)
-- ═══════════════════════════════════════════════════════════════════════


-- ═══════════════════════════════════════════════════════════════════════
-- 5. ANONYMISATION TABLE EMAIL_LOGS
-- ═══════════════════════════════════════════════════════════════════════

UPDATE email_logs el
JOIN (
    SELECT id,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_prenoms)) AS rp,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_noms)) AS rn,
        FLOOR(1 + RAND() * (SELECT COUNT(*) FROM tmp_providers)) AS rd
    FROM email_logs
) r ON el.id = r.id
JOIN tmp_prenoms p ON p.id = r.rp
JOIN tmp_noms n ON n.id = r.rn
JOIN tmp_providers d ON d.id = r.rd
SET
    el.destinataire_email = CONCAT(p.slug, '.', n.slug, '@', d.domaine),
    el.destinataire_nom   = CASE WHEN el.destinataire_nom IS NOT NULL THEN CONCAT(p.prenom, ' ', n.nom) ELSE NULL END;


-- ═══════════════════════════════════════════════════════════════════════
-- 6. NETTOYAGE FORMULAIRE_TOKENS
-- ═══════════════════════════════════════════════════════════════════════

UPDATE formulaire_tokens SET
    token     = UPPER(SUBSTRING(MD5(CONCAT(RAND(), id)), 1, 9)),
    rempli_ip = CASE WHEN rempli_ip IS NOT NULL THEN '192.168.1.1' ELSE NULL END;


-- ═══════════════════════════════════════════════════════════════════════
-- 7. NETTOYAGE FACTURES (notes libres pouvant contenir des infos perso)
-- ═══════════════════════════════════════════════════════════════════════

UPDATE factures SET notes = NULL WHERE notes IS NOT NULL;


-- ═══════════════════════════════════════════════════════════════════════
-- 8. PURGE DES TABLES DE SESSION / TOKENS
-- ═══════════════════════════════════════════════════════════════════════

TRUNCATE TABLE sessions;
TRUNCATE TABLE password_reset_tokens;


-- ═══════════════════════════════════════════════════════════════════════
-- 9. NETTOYAGE
-- ═══════════════════════════════════════════════════════════════════════

DROP TEMPORARY TABLE IF EXISTS tmp_prenoms;
DROP TEMPORARY TABLE IF EXISTS tmp_noms;
DROP TEMPORARY TABLE IF EXISTS tmp_rues;
DROP TEMPORARY TABLE IF EXISTS tmp_villes;
DROP TEMPORARY TABLE IF EXISTS tmp_providers;
DROP TEMPORARY TABLE IF EXISTS tmp_entreprises;
DROP TEMPORARY TABLE IF EXISTS tmp_etablissements;
