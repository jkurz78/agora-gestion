SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nom VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  reset_token VARCHAR(64) NULL,
  reset_expires_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE comptes_bancaires (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nom VARCHAR(150) NOT NULL,
  iban VARCHAR(34) NULL,
  solde_initial DECIMAL(10,2) NOT NULL DEFAULT 0,
  date_solde_initial DATE NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE categories (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nom VARCHAR(100) NOT NULL,
  type ENUM('depense','recette') NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sous_categories (
  id INT PRIMARY KEY AUTO_INCREMENT,
  categorie_id INT NOT NULL,
  nom VARCHAR(100) NOT NULL,
  code_cerfa VARCHAR(10) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE operations (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nom VARCHAR(150) NOT NULL,
  description TEXT NULL,
  date_debut DATE NULL,
  date_fin DATE NULL,
  nombre_seances INT NULL,
  statut ENUM('en_cours','cloturee') NOT NULL DEFAULT 'en_cours',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE depenses (
  id INT PRIMARY KEY AUTO_INCREMENT,
  date DATE NOT NULL,
  libelle VARCHAR(255) NOT NULL,
  montant_total DECIMAL(10,2) NOT NULL,
  mode_paiement ENUM('virement','cheque','especes','cb','prelevement') NOT NULL,
  beneficiaire VARCHAR(150) NULL,
  reference VARCHAR(100) NULL,
  compte_id INT NULL,
  pointe TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  saisi_par INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (compte_id) REFERENCES comptes_bancaires(id) ON DELETE SET NULL,
  FOREIGN KEY (saisi_par) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE depense_lignes (
  id INT PRIMARY KEY AUTO_INCREMENT,
  depense_id INT NOT NULL,
  sous_categorie_id INT NOT NULL,
  operation_id INT NULL,
  seance INT NULL,
  montant DECIMAL(10,2) NOT NULL,
  notes TEXT NULL,
  FOREIGN KEY (depense_id) REFERENCES depenses(id) ON DELETE CASCADE,
  FOREIGN KEY (sous_categorie_id) REFERENCES sous_categories(id),
  FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recettes (
  id INT PRIMARY KEY AUTO_INCREMENT,
  date DATE NOT NULL,
  libelle VARCHAR(255) NOT NULL,
  montant_total DECIMAL(10,2) NOT NULL,
  mode_paiement ENUM('virement','cheque','especes','cb','prelevement') NOT NULL,
  payeur VARCHAR(150) NULL,
  reference VARCHAR(100) NULL,
  compte_id INT NULL,
  pointe TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  saisi_par INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (compte_id) REFERENCES comptes_bancaires(id) ON DELETE SET NULL,
  FOREIGN KEY (saisi_par) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recette_lignes (
  id INT PRIMARY KEY AUTO_INCREMENT,
  recette_id INT NOT NULL,
  sous_categorie_id INT NOT NULL,
  operation_id INT NULL,
  seance INT NULL,
  montant DECIMAL(10,2) NOT NULL,
  notes TEXT NULL,
  FOREIGN KEY (recette_id) REFERENCES recettes(id) ON DELETE CASCADE,
  FOREIGN KEY (sous_categorie_id) REFERENCES sous_categories(id),
  FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE budget_lines (
  id INT PRIMARY KEY AUTO_INCREMENT,
  sous_categorie_id INT NOT NULL,
  exercice INT NOT NULL,
  montant_prevu DECIMAL(10,2) NOT NULL,
  notes TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sous_categorie_id) REFERENCES sous_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE membres (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nom VARCHAR(100) NOT NULL,
  prenom VARCHAR(100) NOT NULL,
  email VARCHAR(150) NULL,
  telephone VARCHAR(20) NULL,
  adresse TEXT NULL,
  date_adhesion DATE NULL,
  statut ENUM('actif','inactif') NOT NULL DEFAULT 'actif',
  notes TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cotisations (
  id INT PRIMARY KEY AUTO_INCREMENT,
  membre_id INT NOT NULL,
  exercice INT NOT NULL,
  montant DECIMAL(10,2) NOT NULL,
  date_paiement DATE NOT NULL,
  mode_paiement ENUM('virement','cheque','especes','cb','prelevement') NOT NULL,
  compte_id INT NULL,
  pointe TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (membre_id) REFERENCES membres(id) ON DELETE CASCADE,
  FOREIGN KEY (compte_id) REFERENCES comptes_bancaires(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE donateurs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nom VARCHAR(100) NOT NULL,
  prenom VARCHAR(100) NOT NULL,
  email VARCHAR(150) NULL,
  adresse TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dons (
  id INT PRIMARY KEY AUTO_INCREMENT,
  donateur_id INT NULL,
  date DATE NOT NULL,
  montant DECIMAL(10,2) NOT NULL,
  mode_paiement ENUM('virement','cheque','especes','cb','prelevement') NOT NULL,
  objet VARCHAR(255) NULL,
  operation_id INT NULL,
  seance INT NULL,
  compte_id INT NULL,
  pointe TINYINT(1) NOT NULL DEFAULT 0,
  recu_emis TINYINT(1) NOT NULL DEFAULT 0,
  saisi_par INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (donateur_id) REFERENCES donateurs(id) ON DELETE SET NULL,
  FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE SET NULL,
  FOREIGN KEY (compte_id) REFERENCES comptes_bancaires(id) ON DELETE SET NULL,
  FOREIGN KEY (saisi_par) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
