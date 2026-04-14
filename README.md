# Ex Libris

Ex Libris is a bibliography manager plus AI research assistant for creative research workflows.  
It supports mixed-source ingestion, citation formatting, semantic retrieval, source analysis, and digest generation.

## What The Site Currently Does

### Core Bibliography Workflow

- Add sources from `dump.php` (URL, DOI, ISBN, raw text, BibTeX, RIS).
- Review/edit source metadata before saving.
- Manage saved entries on `index.php` (search, load, cite copy, visit link, delete).
- Assign sources to collections (tag-style, comma-separated, autocomplete from existing names).
- New collection names are created on save and attached to the source immediately.
- View/edit single source details on `source.php`.
- Export formatted citations via `api/export.php`.

### Ingestion + Metadata Connectors

- CrossRef DOI lookup.
- Open Library ISBN lookup.
- Primo permalink metadata extraction.
- YouTube oEmbed and HTML metadata extraction for URL inputs.
- OpenAI-assisted fallback extraction when primary providers fail.
- Lookup trace/provenance feedback returned from processing.

### Citation + Formatting

- Citation formats: APA, MLA, Chicago.
- Runtime format switching in Settings.
- Toggle to include/exclude page numbers in formatted output.
- Citation cache regeneration on source/settings changes.
- Per-source `[ Cite ]` copy action and full bibliography copy block.
- Bibliography block can be filtered by collection and mirrors visible/filtered sources.

### AI Assistant + Semantic Features

- Embeddings generated for saved sources.
- Semantic search endpoint (`api/semantic.php`) and UI panel on `index.php`.
- Assistant endpoint (`api/assistant.php`) includes:
  - Source quality scoring
  - Smart annotation copilot (summary, claims, methods, limitations)
  - Theme clustering
  - Similar source retrieval
  - Claim-to-source linking (draft matching)
  - Citation QA/style checks
  - Research question builder
  - Compare-and-contrast briefs
  - Weekly digest generation + run storage
  - Zotero sync status support

### Zotero Integration

- Zotero credentials stored in Settings.
- Preview import, collection sync, full import, and push-back via `api/zotero.php`.
- Item transformation into internal source schema.
- De-duplication by Zotero key + DOI/ISBN/title-author-year fingerprint.
- Imported items are visually marked and can be pushed back to Zotero from UI.
- Zotero collections are mapped to local projects (`projects` + `source_project`).
- Pushes can auto-create/use a Zotero collection (default: `Ex Libris`) and assign pushed items into it.
- Local collections attached to a source are also synced to Zotero collections during push (created remotely if missing).
- `cleanup.php` adds an AI-assisted duplicate detection/review/apply flow for post-sync deduping.

### UI/UX

- Theme toggle (auto/light/dark).
- Full-screen drag-and-drop for `.bib`, `.ris`, `.txt`, and dropped links.
- Terminal-inspired visual style with action-button labels.
- Dropdown controls use square corners and bordered style.

## Storage + Data Model

Primary storage is PostgreSQL.

- Core tables: `sources`, `settings`, `projects`, `source_project`
- Assistant/semantic tables: `source_embeddings`, `assistant_runs`, `draft_claim_links`, `digest_runs`
- Optional markdown mirrors: `data/sources-md/source-<id>.md`

Schema reference: `sql/schema.sql`  
Runtime schema bootstrap occurs in `lib/db.php`.

## Local Setup (Valet / Apache / PHP)

1. Ensure PostgreSQL is running and create DB/user.
2. Set environment variables:
   - `EXLIBRIS_DB_HOST`
   - `EXLIBRIS_DB_PORT`
   - `EXLIBRIS_DB_NAME`
   - `EXLIBRIS_DB_USER`
   - `EXLIBRIS_DB_PASS`
3. Optional environment variables:
   - `EXLIBRIS_OPENAI_API_KEY`
   - `EXLIBRIS_ADMIN_TOKEN`
   - `EXLIBRIS_OPENAI_CHAT_MODEL`
   - `EXLIBRIS_OPENAI_EMBED_MODEL`
4. Restart your web runtime (Valet/PHP-FPM) so env vars are available to requests.
5. Open `settings.php` to configure assistant and Zotero options.

## Security + Ops Notes

- Mutating APIs can be protected with `EXLIBRIS_ADMIN_TOKEN` (`X-Admin-Token` header).
- Write/compute POST APIs now fail closed unless `EXLIBRIS_ADMIN_TOKEN` is configured and sent by client.
- OpenAI key is env-first; settings-based key fallback is still supported.
- Structured app events are logged through `app_log()` in `lib/common.php`.
- If migrating from old JSON-only mode, import `data/sources.json` into Postgres to restore legacy refs.

## Before Public Push Checklist

- Rotate any previously exposed API keys (OpenAI, Zotero, etc).
- Keep secrets in environment variables only (`EXLIBRIS_OPENAI_API_KEY`, `EXLIBRIS_ADMIN_TOKEN`, DB vars).
- Ensure `.gitignore` excludes runtime/sensitive files (`data/`, `.env*`).
- Confirm `data/settings.json` has no plaintext keys before commit.
- Run a secret scan before pushing (for example `gitleaks detect --source .`).