-- Add writer role to users.role enum
ALTER TABLE users
  MODIFY COLUMN role ENUM('superadmin','admin','write','read','traceability','writer') DEFAULT 'read';
