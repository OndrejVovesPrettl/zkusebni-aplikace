-- Post-drop setup pro tabulku zapisy_prostoje.
-- Skript je bezpecny pro opakovane spusteni: zajisti existenci tabulky.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS zapisy_prostoje (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    zapis_id INT NOT NULL,
    kategorie VARCHAR(16) NOT NULL,
    typ_kod VARCHAR(20) NOT NULL,
    minuty INT UNSIGNED NOT NULL DEFAULT 0,
    cas_od TIME NULL,
    cas_do TIME NULL,
    pocet INT UNSIGNED NULL,
    poznamka VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_zapisy_prostoje_zapis_id (zapis_id),
    KEY idx_zapisy_prostoje_kategorie (kategorie),
    KEY idx_zapisy_prostoje_typ_kod (typ_kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
