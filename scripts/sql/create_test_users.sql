-- ============================================================
-- Testovací uživatelé pro ověření oprávnění
-- Password: InitAdmin123+
-- Hash: $2y$12$KfzTTvxqOjldwiEZaxH6ueMaAWXcRsPwDDyLvam6cIlV90ZwQHIPC
-- password_changed_at je nastaven na NOW(), aby se heslo nemuselo měnit
-- ============================================================

INSERT IGNORE INTO `users` (
  `username`,
  `skutecneJmeno`,
  `pass_hash`,
  `role`,
  `active`,
  `password_changed_at`
) VALUES
  -- superadmin
  ('superadmin_test', 'Test superadmin', '$2y$12$KfzTTvxqOjldwiEZaxH6ueMaAWXcRsPwDDyLvam6cIlV90ZwQHIPC', 'superadmin', 1, NOW()),
  
  -- admin
  ('admin_test', 'Test admin', '$2y$12$KfzTTvxqOjldwiEZaxH6ueMaAWXcRsPwDDyLvam6cIlV90ZwQHIPC', 'admin', 1, NOW()),
  
  -- write (operátor hlavního formuláře)
  ('write_test', 'Test write (operátor)', '$2y$12$KfzTTvxqOjldwiEZaxH6ueMaAWXcRsPwDDyLvam6cIlV90ZwQHIPC', 'write', 1, NOW()),
  
  -- read (čtení)
  ('read_test', 'Test read (čtení)', '$2y$12$KfzTTvxqOjldwiEZaxH6ueMaAWXcRsPwDDyLvam6cIlV90ZwQHIPC', 'read', 1, NOW()),
  
  -- traceability (čtení traceability)
  ('traceability_test', 'Test traceability (čtení)', '$2y$12$KfzTTvxqOjldwiEZaxH6ueMaAWXcRsPwDDyLvam6cIlV90ZwQHIPC', 'traceability', 1, NOW()),
  
  -- writer (editace traceability bez formuláře)
  ('writer_test', 'Test writer (editace)', '$2y$12$KfzTTvxqOjldwiEZaxH6ueMaAWXcRsPwDDyLvam6cIlV90ZwQHIPC', 'writer', 1, NOW());
