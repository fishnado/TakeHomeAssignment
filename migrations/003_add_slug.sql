-- Add human-readable slug to documents.
-- Format: {title-kebab-case}-{2-char-base36-suffix}  e.g. welcome-packet-3k
-- NULL allowed so existing rows are unaffected; application always sets it on insert.
ALTER TABLE documents ADD COLUMN slug TEXT NULL;

CREATE UNIQUE INDEX idx_documents_slug ON documents(slug);
