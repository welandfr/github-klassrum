-- Each teacher has their own assignments. Existing ones go to the first main admin in ADMINS;
-- migrate() in lib/db.php does that, since it can read the config.
ALTER TABLE assignments ADD COLUMN owner TEXT NOT NULL DEFAULT '';
CREATE INDEX assignments_owner ON assignments (owner);
