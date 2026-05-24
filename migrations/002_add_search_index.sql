-- Inverted index for edge n-gram title search.
-- Each row maps one token (a title word prefix) to the document that contains it.
-- The index on token makes lookups O(log n) regardless of corpus size.
CREATE TABLE document_search_tokens (
    token       TEXT    NOT NULL,
    document_id INTEGER NOT NULL,
    FOREIGN KEY (document_id) REFERENCES documents(id)
);

CREATE INDEX idx_search_tokens ON document_search_tokens(token);
