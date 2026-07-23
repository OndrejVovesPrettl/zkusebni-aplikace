CREATE TABLE IF NOT EXISTS rework_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    label VARCHAR(150) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rework_reasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    label VARCHAR(190) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rework_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    label VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rework_entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    dmc VARCHAR(80) NOT NULL,
    reason_id INT NOT NULL,
    action_id INT NOT NULL,
    replacement_dmc VARCHAR(80) NULL,
    note TEXT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_by_user_id TINYINT UNSIGNED NULL,
    recorded_by_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rework_entries_recorded_at (recorded_at),
    INDEX idx_rework_entries_dmc (dmc),
    INDEX idx_rework_entries_project (project_id),
    INDEX idx_rework_entries_reason (reason_id),
    INDEX idx_rework_entries_action (action_id),
    INDEX idx_rework_entries_user (recorded_by_user_id),
    CONSTRAINT fk_rework_entries_project FOREIGN KEY (project_id) REFERENCES rework_projects(id),
    CONSTRAINT fk_rework_entries_reason FOREIGN KEY (reason_id) REFERENCES rework_reasons(id),
    CONSTRAINT fk_rework_entries_action FOREIGN KEY (action_id) REFERENCES rework_actions(id),
    CONSTRAINT fk_rework_entries_user FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO rework_projects (code, label, sort_order, active) VALUES
('48W', '48W', 10, 1),
('Gen3', 'Gen3', 20, 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order), active = VALUES(active);

INSERT INTO rework_actions (code, label, sort_order, active) VALUES
('1', 'Oprava', 10, 1),
('2', 'Šrot', 20, 1),
('3', 'OK', 30, 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order), active = VALUES(active);

INSERT INTO rework_reasons (code, label, sort_order, active) VALUES
('01', 'demontáž PCB', 10, 1),
('02', 'Výměna krytu (než zaschne lepidlo)', 20, 1),
('03', 'Nedošroubovaný', 30, 1),
('04', 'Kontaminace lepidlo', 40, 1),
('05', 'Rozstřik', 50, 1),
('06', 'Přelepení QR', 60, 1),
('07', 'Výměna komponentu', 70, 1),
('08', 'Hot staking', 80, 1),
('09', 'Ground plate pozice', 90, 1),
('10', 'Lepidlo na kondenzátoru', 100, 1),
('11', 'Termoploška', 110, 1),
('12', 'Vrypy', 120, 1),
('13', 'Pozice závitu NOK', 130, 1),
('14', 'Perlička na sváru', 140, 1),
('15', 'Díra ve sváru', 150, 1),
('16', 'DMC kód chybí/dvojitý', 160, 1),
('17', 'Poškozený 4pin', 170, 1),
('18', 'Poškozený díl', 180, 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order), active = VALUES(active);
