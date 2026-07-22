-- Owner security: password changed tracking + session invalidation
-- psql -U neotalab -d neotalab -f database/migrations/003_owner_security.sql

ALTER TABLE owners ADD COLUMN IF NOT EXISTS password_changed_at TIMESTAMPTZ;
ALTER TABLE owners ADD COLUMN IF NOT EXISTS tokens_valid_after TIMESTAMPTZ NOT NULL DEFAULT '1970-01-01';
