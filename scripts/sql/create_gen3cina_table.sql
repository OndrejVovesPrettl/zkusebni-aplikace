-- Create gen3cina traceability project table (copy of gen3manual structure)
USE traceability2;

CREATE TABLE IF NOT EXISTS `gen3cina` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sn` varchar(255) NOT NULL,
  `timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sn` (`sn`),
  KEY `timestamp` (`timestamp`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add gen3cina to limits table if not exists
INSERT IGNORE INTO `limits` (`project`, `limit`, `with_break`, `without_break`)
VALUES ('gen3cina', 280, 0, 0);
