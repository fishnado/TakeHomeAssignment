-- Add optional scheduled publish time to documents.
-- NULL means publish immediately (existing behaviour preserved).
ALTER TABLE documents ADD COLUMN publish_at TEXT NULL;
