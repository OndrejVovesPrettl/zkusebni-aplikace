CREATE TABLE IF NOT EXISTS nok_afo_alias (
  source_afo VARCHAR(50) NOT NULL,
  target_afo VARCHAR(50) NOT NULL,
  PRIMARY KEY (source_afo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nok_afo_alias (source_afo, target_afo)
VALUES ('20', '22')
ON DUPLICATE KEY UPDATE target_afo = VALUES(target_afo);
