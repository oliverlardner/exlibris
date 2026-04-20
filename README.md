# Ex Libris

Ex Libris is a bibliography manager plus AI research assistant for creative research workflows.  
It supports mixed-source ingestion, citation formatting, semantic retrieval, source analysis, and digest generation.

## What The Site Currently Does

### Core Bibliography Workflow

- Add sources from `dump.php` — accepts any of the input formats listed below.
- Review/edit source metadata before saving.
- Manage saved entries on `index.php` (search, load, cite copy, visit link, delete).
- Assign sources to collections (tag-style, comma-separated, autocomplete from existing names).
- New collection names are created on save and attached to the source immediately.
- View/edit single source details on `source.php`.
- Export formatted citations via `api/export.php`.

### Ingestion + Metadata Connectors

Input is detected automatically and routed through the appropriate pipeline, in this order:

| Input | Detection | Pipeline |
|---|---|---|
| **BibTeX** | Starts with `@Type{` and contains `title` | Parsed directly — no network call |
| **RIS** | Line starting with `TY  -` | Parsed directly — no network call |
| **DOI** | Pattern `10.XXXX/...` anywhere in text | CrossRef → OpenAlex → Semantic Scholar → OpenAI fallback |
| **ISBN** | 10- or 13-digit ISBN (hyphens optional) | Open Library `/api/books` → OpenAI fallback |
| **Anna's Archive URL** | `annas-archive.*/md5/{hash}` | Scrapes page for ISBN-13 → Open Library |
| **Primo permalink** | `*.exlibrisgroup.com/discovery/fulldisplay` | Primo REST API by `recordid` → search fallback |
| **YouTube URL** | `youtube.com` / `youtu.be` | oEmbed API |
| **Any other URL** | `filter_var FILTER_VALIDATE_URL` | Zotero translation server → OpenAlex/Semantic Scholar by URL → HTML meta tags (incl. Google Scholar `citation_*`) + OpenAI extraction with anti-hallucination verification → CrossRef/OpenAlex/Semantic Scholar by DOI → OpenAlex title search → Open Library enrichment |
| **Free text** | Everything else | OpenAI recall → Open Library enrichment + suggestions |

**URL enrichment chain** (for any URL that isn't a DOI/ISBN/AA/Primo/YouTube):
1. A Zotero translation server is tried first — it uses site-specific translators that correctly identify articles on publisher platforms (APA PsycNet, PubMed, arXiv, Springer, JSTOR, SSRN, news sites, …), including pages that are JS-rendered SPAs with no useful static HTML. When it resolves the URL, its metadata is authoritative and no AI call is made.
2. If Zotero has no translator, OpenAlex and Semantic Scholar are queried by landing URL (covers arXiv, Semantic Scholar's corpus, and a subset of publisher URLs).
3. Otherwise the page HTML is scraped for scholarly `citation_*` meta tags (Google Scholar convention), `og:*` tags, and Primo/YouTube API data.
4. OpenAI then extracts metadata from the page text. Its output is cross-verified against the raw HTML/text: if the model's DOI/ISBN/title words/author surnames do not appear anywhere in the page, the result is discarded as a hallucination rather than passed downstream.
5. If a DOI is present (from meta tags or verified AI output), it's resolved through the CrossRef → OpenAlex → Semantic Scholar cascade.
6. If we still have no title, OpenAlex's full-text title search is tried as a last structured backup.
7. For book-like results with an ISBN, Open Library enriches further.

**Free-text enrichment chain** (for raw titles, author names, rough notes, misspellings):
1. OpenAI identifies the work and corrects spelling.
2. If an ISBN is returned, Open Library confirms it; if the ISBN lookup misses, falls through to title search.
3. Open Library title search tries `"quoted title" author` → unquoted → author-only fallback.
4. If confidence is still low (no canonical identifier, or the extracted title doesn't match the input), up to 5 candidate works from Open Library are returned as selectable suggestions in the UI.

- Lookup trace/provenance returned from every processing step.
- Drag-and-drop on any page: `.bib`, `.ris`, `.txt` files or dropped links populate the input automatically.

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
  - L1 Reader synthesis (`reader_synthesis`): context-aware, multi-source compression with verdict (`read`/`skim`/`ignore`), evidence links, cautions, and external candidate suggestions
  - Weekly digest generation + run storage
  - Zotero sync status support
- Reader UI (`reader.php`) supports:
  - Selecting 0+ local sources and adding free-text research context
  - Hybrid context expansion (semantic shortlist + scholarly API lookups + OpenAI hosted web search)
  - Cached page-body extraction (`sources.body_text`) so repeated reader runs stay fast while periodically refreshing stale source text
  - Reader run history loaded from `assistant_runs` so prior syntheses can be revisited and reloaded

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
- Low-confidence suggestions panel: when free-text input is ambiguous, up to 5 clickable Open Library candidates appear below the form.

## Storage + Data Model

Primary storage is PostgreSQL.

- Core tables: `sources`, `settings`, `projects`, `source_project`
- Assistant/semantic tables: `source_embeddings`, `assistant_runs`, `draft_claim_links`, `digest_runs`
- Optional markdown mirrors: `data/sources-md/source-<id>.md`

Schema reference: `sql/schema.sql`  
Runtime schema bootstrap occurs in `lib/db.php`.

## Local Setup (Valet / Apache / PHP)

1. Ensure PostgreSQL is running and create DB/user.
2. Copy `.env.example` to `.env` (or create `.env` manually) and fill in values — the app loads it automatically via `lib/common.php`:
   ```
   EXLIBRIS_DB_HOST=127.0.0.1
   EXLIBRIS_DB_PORT=5432
   EXLIBRIS_DB_NAME=exlibris
   EXLIBRIS_DB_USER=exlibris
   EXLIBRIS_DB_PASS=
   EXLIBRIS_ADMIN_TOKEN=<generate with: openssl rand -hex 32>
   EXLIBRIS_OPENAI_API_KEY=
   EXLIBRIS_OPENAI_CHAT_MODEL=gpt-4o-mini
   EXLIBRIS_OPENAI_READER_MODEL=gpt-4o-mini
   EXLIBRIS_OPENAI_EMBED_MODEL=text-embedding-3-small
   ```
3. The `.env` file is loaded at runtime — no server restart needed for Valet/PHP-FPM.
4. Open `settings.php` to configure the OpenAI API key (if not set via env), assistant model, and Zotero credentials.
5. The `EXLIBRIS_ADMIN_TOKEN` is automatically synced into `localStorage` by the layout, so all browser write actions are authorised without any manual setup.

### Environment Variables Reference

| Variable | Required | Default | Notes |
|---|---|---|---|
| `EXLIBRIS_DB_HOST` | Yes | `127.0.0.1` | PostgreSQL host |
| `EXLIBRIS_DB_PORT` | Yes | `5432` | PostgreSQL port |
| `EXLIBRIS_DB_NAME` | Yes | `exlibris` | Database name |
| `EXLIBRIS_DB_USER` | Yes | `postgres` | Database user |
| `EXLIBRIS_DB_PASS` | Yes | *(empty)* | Blank works with `trust` auth |
| `EXLIBRIS_ADMIN_TOKEN` | Yes | *(none)* | Required — write APIs fail closed without it |
| `EXLIBRIS_OPENAI_API_KEY` | No | *(settings DB)* | Env takes priority over Settings UI |
| `EXLIBRIS_OPENAI_CHAT_MODEL` | No | `gpt-4o-mini` | Chat model for extraction + assistant |
| `EXLIBRIS_OPENAI_READER_MODEL` | No | `gpt-4o-mini` | Model used for L1 Reader synthesis (`/v1/responses` with hosted web search). If unset, falls back to chat model. |
| `EXLIBRIS_OPENAI_EMBED_MODEL` | No | `text-embedding-3-small` | Embeddings model |
| `EXLIBRIS_ZOTERO_TRANSLATION_URL` | No | `https://translate.manubot.org/web` | Zotero translation-server endpoint for URL → bibliographic metadata. Point at a self-hosted instance for production: `docker run -d -p 1969:1969 zotero/translation-server` then set to `http://localhost:1969/web`. |
| `EXLIBRIS_SEMANTIC_SCHOLAR_KEY` | No | *(empty)* | Optional Semantic Scholar Graph API key. Raises rate limits; without a key, shared-pool 429s are treated as "no result" and the pipeline continues to other backups. |

## Security + Ops Notes

- Mutating APIs require `X-Admin-Token: <value>` header matching `EXLIBRIS_ADMIN_TOKEN`.
- Write/compute POST APIs fail closed if the token is not configured.
- OpenAI key is env-first; settings-based key fallback is still supported.
- Reader runs that enable hosted web search are slower and costlier than local-only assistant actions; expect roughly 20-60s end-to-end for multi-source synthesis.
- `.env` values are only loaded if the environment variable is not already set — OS/server env always wins.
- Structured app events are logged through `app_log()` in `lib/common.php`.
- If migrating from old JSON-only mode, import `data/sources.json` into Postgres to restore legacy refs.

## Before Public Push Checklist

- Rotate any previously exposed API keys (OpenAI, Zotero, etc).
- Keep secrets in `.env` or OS environment only — never commit them.
- Ensure `.gitignore` excludes runtime/sensitive files (`data/`, `.env*`).
- Confirm `data/settings.json` has no plaintext keys before commit.
- Run a secret scan before pushing (for example `gitleaks detect --source .`).
