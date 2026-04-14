CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS projects (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT NULL,
    external_provider TEXT NOT NULL DEFAULT '',
    external_id TEXT NOT NULL DEFAULT '',
    sync_meta JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS sources (
    id BIGSERIAL PRIMARY KEY,
    type TEXT NOT NULL DEFAULT 'other',
    title TEXT NOT NULL DEFAULT '',
    authors JSONB NOT NULL DEFAULT '[]'::jsonb,
    year TEXT NOT NULL DEFAULT '',
    publisher TEXT NOT NULL DEFAULT '',
    journal TEXT NOT NULL DEFAULT '',
    volume TEXT NOT NULL DEFAULT '',
    issue TEXT NOT NULL DEFAULT '',
    pages TEXT NOT NULL DEFAULT '',
    doi TEXT NOT NULL DEFAULT '',
    isbn TEXT NOT NULL DEFAULT '',
    url TEXT NOT NULL DEFAULT '',
    accessed_at TEXT NOT NULL DEFAULT '',
    raw_input TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    citation_cache JSONB NOT NULL DEFAULT '{}'::jsonb,
    quality_score DOUBLE PRECISION NULL,
    quality_reason TEXT NOT NULL DEFAULT '',
    ai_summary TEXT NOT NULL DEFAULT '',
    ai_claims JSONB NOT NULL DEFAULT '[]'::jsonb,
    ai_methods JSONB NOT NULL DEFAULT '[]'::jsonb,
    ai_limitations JSONB NOT NULL DEFAULT '[]'::jsonb,
    theme_labels JSONB NOT NULL DEFAULT '[]'::jsonb,
    origin_provider TEXT NOT NULL DEFAULT '',
    origin_external_id TEXT NOT NULL DEFAULT '',
    origin_updated_at TIMESTAMPTZ NULL,
    zotero_item_key TEXT NOT NULL DEFAULT '',
    zotero_version BIGINT NULL,
    zotero_synced_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS source_project (
    source_id BIGINT NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    PRIMARY KEY (source_id, project_id)
);

CREATE TABLE IF NOT EXISTS source_embeddings (
    source_id BIGINT PRIMARY KEY REFERENCES sources(id) ON DELETE CASCADE,
    model TEXT NOT NULL,
    embedding VECTOR(1536),
    embedding_json JSONB NOT NULL DEFAULT '[]'::jsonb,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_source_embeddings_hnsw
ON source_embeddings USING hnsw (embedding vector_cosine_ops);

CREATE TABLE IF NOT EXISTS assistant_runs (
    id BIGSERIAL PRIMARY KEY,
    run_type TEXT NOT NULL,
    source_id BIGINT NULL REFERENCES sources(id) ON DELETE CASCADE,
    input_text TEXT NOT NULL DEFAULT '',
    output_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS draft_claim_links (
    id BIGSERIAL PRIMARY KEY,
    claim_text TEXT NOT NULL,
    source_id BIGINT NULL REFERENCES sources(id) ON DELETE CASCADE,
    confidence DOUBLE PRECISION NULL,
    rationale TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS digest_runs (
    id BIGSERIAL PRIMARY KEY,
    digest_text TEXT NOT NULL,
    digest_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
