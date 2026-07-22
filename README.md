# tds-ext-blog-cms-pkg

The **Blog-CMS** as a frontend extension, ported from `tds-content-api`'s blog-post
model. Edits blog posts (title / excerpt / body / draft / publish per **blog ×
slug × language**); the static blogs fetch published posts at build time.

**1:n blogs:** a `blog` registry lets one frontend manage several blogs; posts are
scoped to a blog.

## Surface (checkpoint-1)

- **Blogs:** `GET /blogs`, `POST /blogs` (`{blog_key, name}`), `GET /blog/summary`
  (the "Blog-Beiträge" widget count).
- **Posts:** `GET /blogs/{blog}/posts` (metadata list),
  `GET /blogs/{blog}/posts/{slug}?lang=de`, `PUT /blogs/{blog}/posts/{slug}`
  (upsert `{title, body, excerpt?, category?, draft?, lang?}`), `DELETE …`.
- **Frontend:** nav "Blog-CMS" → `/blog`, the blogs list + add-blog form + a
  blog's post list, the posts dashboard widget, DE/EN i18n.
- **Public read (UNAUTHENTICATED)** — the successor to tds-content-api's open read
  the public blog/landingpage SSG builds fetch: `GET /content/blog?lang=&limit=&cursor=`
  (published list, `{posts, nextCursor}`), `GET /content/blog/popular`, `GET
  /content/blog/{slug}?lang=`, `GET /content/topics` (null), `GET /content/snippets`
  (`[]`). Published posts only (`draft=0`, `published_at NOT NULL`), camelCase `BlogPost`
  shape, single public site → the default blog. Degrades to an empty payload on a DB
  error (build-fetch fail-safe).

Auth: the admin routes need `blog:read`/`blog:write` from the core `UserContext` (admins
bypass); the `/content/*` public read is ungated. Data via the core `PDO`.

## Still to port (later checkpoints)

The markdown post editor UI (create/edit + publish/draft + cover), a
save-triggered static-blog rebuild (workflow_dispatch, per-blog config), blog
authors, and DeepL auto-translation (as content-api's TranslationSync does).

## Develop

```bash
npm install        # pulls tds-frontend-contract from GitHub Packages (needs NPM_TOKEN)
npm run build && npm run type-check
composer install   # resolves tds-frontend-contract from its public VCS repo
composer test      # phpunit — route/RBAC coverage; DB-backed tests skip without TDS_TEST_DB_DSN
```

## Enable it

Host `astro.config.mjs`: add the manifest to `frontendHost({ extensions: [...] })`.
Base API: add `new BlogCmsModule()` to `Modules::enabled()`.
