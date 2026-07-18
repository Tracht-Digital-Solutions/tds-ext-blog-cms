# AGENTS.md — tds-ext-blog-cms

Blog-CMS extension, ported from `tds-content-api`'s blog-post model. Read
`tds-panel-contract` + `tds-core-panel-api` AGENTS first.

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
- **TODO (next):** blog authors; DeepL auto-translation; SEO fields; a markdown
  preview pane; move the rebuild token into the runtime settings store.

## After a change

Bump `version` in `package.json` + `composer.json` (lockstep), update docs,
commit together.
