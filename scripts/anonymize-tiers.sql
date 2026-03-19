-- anonymize-tiers.sql
-- Anonymise les données personnelles de la table tiers après un clone de prod.
-- À exécuter dans la base de données staging UNIQUEMENT.

UPDATE tiers SET
    nom       = CONCAT('Tiers-', id),
    prenom    = CASE WHEN prenom IS NOT NULL THEN CONCAT('Prenom-', id) ELSE NULL END,
    email     = CASE WHEN email IS NOT NULL THEN CONCAT('tiers', id, '@example.com') ELSE NULL END,
    telephone = CASE WHEN telephone IS NOT NULL THEN '0600000000' ELSE NULL END,
    adresse   = CASE WHEN adresse IS NOT NULL THEN '1 rue de l''Exemple, 75001 Paris' ELSE NULL END;

-- Anonymise aussi les utilisateurs (sauf l'admin de staging créé manuellement)
UPDATE users SET
    nom   = CONCAT('User-', id),
    email = CONCAT('user', id, '@example.com')
WHERE email NOT LIKE '%@svs.fr';
