-- ============================================================
--  IKARATA KARANGAMUNTU — Script de configuration de la BD
--  Republika y'Uburundi — Système National d'Identification
--  v2 : ajout colonne telephone, contrainte date > 1920
-- ============================================================

CREATE DATABASE IF NOT EXISTS identite_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE identite_db;

CREATE TABLE IF NOT EXISTS personne (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    izina           VARCHAR(100)  NOT NULL         COMMENT 'Nom de famille',
    amatazirano     VARCHAR(100)  NOT NULL         COMMENT 'Prénom',
    se              VARCHAR(150)                   COMMENT 'Nom du père',
    nyina           VARCHAR(150)                   COMMENT 'Nom de la mère',
    provensi        VARCHAR(100)                   COMMENT 'Province d\'origine',
    komine          VARCHAR(100)                   COMMENT 'Commune',
    yavukiye        VARCHAR(100)                   COMMENT 'Lieu de naissance',
    italiki         DATE                           COMMENT 'Date de naissance (>= 1920-01-01)',
    genre           CHAR(1)                        COMMENT 'M = Masculin, F = Féminin',
    arubatse        VARCHAR(50)                    COMMENT 'État civil',
    ntarubaka       VARCHAR(150)                   COMMENT 'Profession',
    akazi_akora     VARCHAR(150)                   COMMENT 'Employeur',
    num_mifp        VARCHAR(50)   UNIQUE           COMMENT 'Numéro MIFP (unique)',
    itangiwe_i      VARCHAR(100)                   COMMENT 'Lieu de délivrance',
    date_delivrance DATE                           COMMENT 'Date délivrance (<= CURDATE)',
    uwuyitanze      VARCHAR(150)                   COMMENT 'Signataire',
    telephone       VARCHAR(30)                    COMMENT 'Téléphone au format +257 XX XXX XXX',
    photo           VARCHAR(255)                   COMMENT 'Chemin relatif vers la photo',
    created_at      DATETIME      DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Contrainte : date de naissance >= 1920-01-01 et <= CURDATE()
    CONSTRAINT chk_italiki_min  CHECK (italiki IS NULL OR italiki >= '1920-01-01'),
    CONSTRAINT chk_italiki_max  CHECK (italiki IS NULL OR italiki <= CURDATE()),

    -- Contrainte : date de délivrance <= CURDATE()
    CONSTRAINT chk_delivrance   CHECK (date_delivrance IS NULL OR date_delivrance <= CURDATE()),

    INDEX idx_nom      (izina),
    INDEX idx_prenom   (amatazirano),
    INDEX idx_province (provensi),
    INDEX idx_created  (created_at),
    INDEX idx_tel      (telephone)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ── Migration : ajouter telephone si la table existait déjà ────────────────
ALTER TABLE personne ADD COLUMN IF NOT EXISTS telephone VARCHAR(30)
  COMMENT 'Téléphone au format +257 XX XXX XXX' AFTER uwuyitanze;

-- ── Vérification ────────────────────────────────────────────────────────────
DESCRIBE personne;
SHOW INDEX FROM personne;

/*
═══════════════════════════════════════════════════════
  DONNÉES DE TEST (décommenter si nécessaire)
═══════════════════════════════════════════════════════
INSERT INTO personne
  (izina, amatazirano, se, nyina, provensi, komine, yavukiye,
   italiki, genre, arubatse, ntarubaka, num_mifp, itangiwe_i,
   date_delivrance, uwuyitanze, telephone)
VALUES
  ('NINKUNDA', 'Josiane', 'NGENDANGENZWA', 'HABONIMANA',
   'Bujumbura', 'Muhuta', 'Nkuba',
   '1999-01-01', 'F', 'célibataire', 'Élève', '1504/111.147',
   'Muhuta', '2015-02-15', 'Diomède', '+257 79 123 456'),

  ('HAKIZIMANA', 'Pierre', 'NKURUNZIZA', 'UWIMANA',
   'Gitega', 'Gitega', 'Gitega',
   '1985-06-20', 'M', 'marié(e)', 'Enseignant', '0892/045.221',
   'Gitega', '2020-03-10', 'Administrateur', '+257 61 987 654');
*/
