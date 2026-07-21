# AGENTS.md — tds-ext-blog-cms-pkg

Blog-CMS extension, ported from `tds-content-api`'s blog-post model. Read
`tds-panel-contract-pkg` + `tds-core-panel-api` AGENTS first.

## Model

- **Build-time content:** `blog_post` rows (one per blog × slug × language) are
  read by the static blogs at build time (published, non-draft). Never fetch this
  from the client at runtime (same rule as content-api).
- **1:n blogs:** the `blog` registry scopes posts. `blog_post.blog_id` FK →
  `blog` (CASCADE). Unique `(blog_id, slug, lang)`.
- **Auth via the core `UserContext`** — `blog:read`/`blog:write` (admins bypass).
  Posts are upserted (PUT, `ON DUPLICATE KEY`). A non-draft save stamps
  `published_at` when none is set.
- **`set:html` on the body stays unsanitised only while admin-authored + baked at
  build** — add `isomorphic-dompurify` the day a non-admin can write a body or a
  client preview ships (carried from content-api/tds-admin).

## Gotchas

- Migration class names are **module-prefixed** (`BlogCms*`).
- Routes are closures resolving `UserContext`/`BlogRepository` from the container
  at request time (rebound per request by the core AuthMiddleware).
- DB-backed tests skip without `TDS_TEST_DB_DSN`; the committed test covers
  routes + RBAC + payload validation without a DB.

## Checkpoint status

- **CP1:** `blog` + `blog_post` schema, `Domain\BlogRepository`, blog + post CRUD
  (`/blogs`, `/blog/summary`) with RBAC, the posts widget + blogs/posts list UI.
- **CP2:** the per-post **markdown editor UI** (`PostEditor` in `islands/BlogsList.tsx`)
  — "Neuer Beitrag" / open a post (slug + lang → GET), edit title/category/excerpt/
  cover-hint + a markdown body textarea, toggle draft↔publish, save via PUT, delete via
  DELETE. Slug + lang lock when editing an existing post (they're the row identity).
- **CP3:** save-triggered **static-blog rebuild**. `Service\RebuildTrigger` (plain
  ext-curl, best-effort, never throws) fires a GitHub `workflow_dispatch` after a
  **published** post is saved (drafts don't rebuild) or a post is deleted. Per-blog
  target on `blog` (`rebuild_repo` "owner/name" + `rebuild_workflow`, default
  `dev.yml`), edited via `PUT /blogs/{blog}/rebuild-config`; the shared PAT is
  `BLOG_REBUILD_TOKEN` (one PAT dispatches every blog repo; unset ⇒ no-op).
  `POST /blogs/{blog}/rebuild` is a manual "Jetzt neu bauen" (503 no token / 422 no
  repo). Sends `ref` only. UI: a Rebuild-Konfiguration block under the post list.
- **CP4:** **DeepL auto-translation** (save-time sync, ported from tds-content-api).
  `blog_post.machine_translated` flags auto-generated rows. On a **published** post
  save, `Service\TranslationSync` translates title/excerpt/category (plain) + body
  (markdown, code shielded) into the counterpart language and upserts it
  (`machine_translated=1`) — but only when that counterpart is absent or itself
  machine-made; a manually authored counterpart is never touched, and a manual save
  clears the row's own flag. Delete cascades onto a machine counterpart. `Service\
  DeeplTranslator` is a curl port (no Guzzle; `:fx` key ⇒ free endpoint; `en`→`EN-GB`).
  Config: `BLOG_DEEPL_API_KEY` (+ `BLOG_AUTO_TRANSLATE=0` to opt out); unset ⇒ no-op.
  `POST /blogs/{blog}/translations/backfill` (blog:write, 503 when inactive) catches up
  pre-existing posts. UI: an "Auto-Übersetzung" badge on machine rows + a backfill button.
  Writes go through the repo (never the route) so the sync can't ping-pong; drafts skip.
- **CP5:** **author bylines**. A self-contained `blog_author` registry (name/bio/
  avatar_url), independent of panel users, with a nullable `blog_post.author_id` FK
  (`ON DELETE SET NULL` — removing an author detaches its posts, never cascades).
  Routes: `GET`/`POST /blog/authors` + `DELETE /blog/authors/{id}` (read/write RBAC).
  The post upsert takes an optional `author_id` (an unknown id is dropped, not
  rejected); `getPost` returns a nested `author` object for the public byline and
  `posts` includes `author_name`. TranslationSync carries the same `author_id` onto
  the machine-translated counterpart (one byline across languages). UI: an author
  dropdown in the editor + an "Autoren" manager (add/remove) under the post list.
- **CP6:** **SEO fields** — `blog_post.meta_description` (≤300) + `tags` (≤200,
  comma-separated keyword tokens), both nullable (a post without them falls back to
  excerpt/category on the public page). Surfaced in the editor, returned by
  `getPost` for the static blog to bake `<meta name=description>`/keywords.
  TranslationSync translates the meta description onto the counterpart (batched with
  the core fields) but keeps `tags` identical across languages (stable keyword
  tokens). Migration `AddBlogCmsSeo`.
- **CP7:** a **markdown preview pane** in the editor (Vorschau/Bearbeiten toggle).
  Uses a tiny **escape-first** renderer (`renderMarkdown` in `islands/BlogsList.tsx`):
  every text run is HTML-escaped *before* any markdown transform, so raw HTML /
  `<script>` in the body become inert text and link hrefs are allowlisted
  (http/https/mailto/relative only) — **safe by construction, no marked/dompurify
  dependency** (this is why the `set:html` sanitiser note doesn't apply to the
  preview). Covers fenced/inline code, headings, bold, italic, links, unordered
  lists, paragraphs; the public blog still uses the full build-time pipeline.
- **CP8:** **runtime settings store adoption.** The DeepL key + auto-translate flag
  + rebuild token are now read **DB-first with env fallback** via the core's
  `SettingsStore` (contract interface, resolved from the container; null in isolated
  tests ⇒ env-only). Namespace `blog-cms`, keys `deepl_api_key`/`rebuild_token`
  (secret, AES-GCM-encrypted by the core) + `auto_translate` (flag). The settings
  slot (`islands/Settings.astro` → `BlogSettings` island) reads/writes the core admin
  API `/admin/settings/blog-cms` (masked: `configured`+`last4`; blank secret = keep).
  Env vars (`BLOG_DEEPL_API_KEY`/`DEEPL_API_KEY`, `BLOG_AUTO_TRANSLATE`,
  `BLOG_REBUILD_TOKEN`) remain the fallback, so existing deployments keep working.
- **CP9:** **authors tied to panel users.** `blog_author.user_id` (nullable,
  unsigned, unique — NOT a DB FK; app_user lives in another service, same rule as
  the ticket refs) links a byline to a tds-auth-api user; the row stays a SNAPSHOT
  (name/bio/avatar) so the byline survives a user removal. `POST /blog/authors` now
  takes an optional `user_id` → `upsertAuthorFromUser` (one snapshot per user);
  absent ⇒ a free-form/guest author. The AuthorManager fetches `/auth/admin/users`
  (relative, same-origin gateway convention), filters to blog authors
  (`isBlogAuthor || isAdmin`), and imports them as authors (a "Panel-Nutzer" chip
  marks linked ones); free-form add stays for guests. Falls back gracefully when
  `/auth/admin/users` is unreachable.
- **TODO (next):** the website-cms equivalent has no author concept (blocks, not
  posts); a markdown preview is done. Larger: per-section structured CMS forms.

## After a change

Bump `version` in `package.json` + `composer.json` (lockstep), update docs,
commit together.
