<?php
declare(strict_types=1);

namespace Tds\Ext\BlogCms;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tds\Ext\BlogCms\Domain\BlogRepository;
use Tds\Ext\BlogCms\Service\DeeplTranslator;
use Tds\Ext\BlogCms\Service\RebuildTrigger;
use Tds\Ext\BlogCms\Service\TranslationSync;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\PermissionDef;
use Tds\Frontend\Contract\SettingsStore;
use Tds\Frontend\Contract\UserContext;

/**
 * Backend Module for the Blog-CMS (checkpoint-1: blog registry + per-(blog, slug,
 * lang) post CRUD + the posts widget summary). Auth via the core UserContext
 * (`blog:read`/`blog:write`, admins bypass); data via the core PDO. A save
 * triggering a static-blog rebuild lands in a later checkpoint.
 */
final class BlogCmsModule extends AbstractModule
{
    private const LANGS = ['de', 'en'];

    public function id(): string
    {
        return 'blog-cms';
    }

    /** @return PermissionDef[] */
    public function permissions(): array
    {
        return [
            new PermissionDef('blog:read', 'Blog-Beiträge ansehen', 'blog-cms'),
            new PermissionDef('blog:write', 'Blog-Beiträge bearbeiten', 'blog-cms'),
        ];
    }

    /** @return string[] */
    public function migrations(): array
    {
        return [__DIR__ . '/../db/migrations'];
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();
        if ($c !== null && !$c->has(BlogRepository::class)) {
            $c->set(BlogRepository::class, static fn ($c) => new BlogRepository($c->get(PDO::class)));
        }
        if ($c !== null && !$c->has(RebuildTrigger::class)) {
            $c->set(RebuildTrigger::class, static function ($c): RebuildTrigger {
                // DB-first (settings store), env fallback for the rebuild PAT.
                $token = self::setting($c)?->getSecret('blog-cms', 'rebuild_token');
                if ($token === null || $token === '') {
                    $token = (string) (getenv('BLOG_REBUILD_TOKEN') ?: '');
                }
                $ref = (string) (getenv('BLOG_REBUILD_REF') ?: 'main');
                return new RebuildTrigger($token, $ref !== '' ? $ref : 'main');
            });
        }
        if ($c !== null && !$c->has(TranslationSync::class)) {
            $c->set(TranslationSync::class, static function ($c): TranslationSync {
                $store = self::setting($c);
                // DeepL key: settings store → BLOG_DEEPL_API_KEY → DEEPL_API_KEY.
                $key = $store?->getSecret('blog-cms', 'deepl_api_key');
                if ($key === null || $key === '') {
                    $key = (string) (getenv('BLOG_DEEPL_API_KEY') ?: getenv('DEEPL_API_KEY') ?: '');
                }
                // Auto-translate flag: settings store ("0" disables) → env → default on.
                $flag = $store?->get('blog-cms', 'auto_translate');
                if ($flag === null) {
                    $envFlag = getenv('BLOG_AUTO_TRANSLATE');
                    $flag = $envFlag === false ? '1' : (string) $envFlag;
                }
                $enabled = !in_array(strtolower($flag), ['0', 'false', 'no', 'off'], true);
                return new TranslationSync($c->get(BlogRepository::class), new DeeplTranslator($key), $enabled);
            });
        }

        // --- Public read surface (UNAUTHENTICATED) --------------------------
        // The successor to tds-content-api's open `/content/blog*` read that the
        // public blog + landingpage SSG builds fetch at build time. Serves only
        // PUBLISHED posts of the single default blog, in the camelCase BlogPost
        // shape tds-shared defines (markdown body → the frontend renders it).
        // These are the ONLY ungated routes in this module; keep them read-only.
        $app->get('/content/blog', function (Request $req, Response $res) use ($c): Response {
            // Graceful for a build-fetch: any DB hiccup returns an empty page so
            // the public build falls back to its baked defaults, never a 500.
            try {
                $repo = $c->get(BlogRepository::class);
                $blog = $repo->defaultBlog();
                if ($blog === null) {
                    return self::json($res, ['posts' => [], 'nextCursor' => null]);
                }
                $q = $req->getQueryParams();
                $lang = self::publicLang($q['lang'] ?? null);
                $limit = max(1, min((int) ($q['limit'] ?? 50), 100));
                $cursor = isset($q['cursor']) && $q['cursor'] !== '' ? (int) $q['cursor'] : null;
                $rows = $repo->publicPosts((int) $blog['id'], $lang, $limit, $cursor);
                $nextCursor = null;
                if (count($rows) > $limit) {
                    $rows = array_slice($rows, 0, $limit);
                    $nextCursor = (int) $rows[count($rows) - 1]['id'];
                }
                return self::json($res, [
                    'posts' => array_map([self::class, 'publicShape'], $rows),
                    'nextCursor' => $nextCursor,
                ]);
            } catch (\Throwable) {
                return self::json($res, ['posts' => [], 'nextCursor' => null]);
            }
        });

        // Popularity: this module has no per-post view counter, so "popular"
        // degrades to newest-first (the frontend just needs a populated tab).
        $app->get('/content/blog/popular', function (Request $req, Response $res) use ($c): Response {
            try {
                $repo = $c->get(BlogRepository::class);
                $blog = $repo->defaultBlog();
                if ($blog === null) {
                    return self::json($res, ['posts' => []]);
                }
                $q = $req->getQueryParams();
                $lang = self::publicLang($q['lang'] ?? null);
                $limit = max(1, min((int) ($q['limit'] ?? 6), 100));
                $rows = array_slice($repo->publicPosts((int) $blog['id'], $lang, $limit, null), 0, $limit);
                return self::json($res, ['posts' => array_map([self::class, 'publicShape'], $rows)]);
            } catch (\Throwable) {
                return self::json($res, ['posts' => []]);
            }
        });

        $app->get('/content/blog/{slug:[a-z0-9-]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            try {
                $repo = $c->get(BlogRepository::class);
                $blog = $repo->defaultBlog();
                $lang = self::publicLang(($req->getQueryParams()['lang'] ?? null)) ?? 'de';
                $row = $blog === null ? null : $repo->publicPost((int) $blog['id'], (string) $args['slug'], $lang);
                if ($row === null) {
                    return self::json($res, ['error' => 'Post not found'], 404);
                }
                return self::json($res, ['post' => self::publicShape($row)]);
            } catch (\Throwable) {
                return self::json($res, ['error' => 'Post not found'], 404);
            }
        });

        // Curated topics + custom snippets were tds-content-api features with no
        // equivalent in this module — answer with the "nothing maintained" shape
        // the frontends already treat as a fallback (null topics / empty snippets)
        // so their build stays green.
        $app->get('/content/topics', function (Request $req, Response $res): Response {
            $lang = self::publicLang($req->getQueryParams()['lang'] ?? null) ?? 'de';
            return self::json($res, ['lang' => $lang, 'topics' => null]);
        });
        $app->get('/content/snippets', function (Request $req, Response $res): Response {
            return self::json($res, ['snippets' => []]);
        });

        $app->get('/blog/summary', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'blog:read', $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['posts' => $c->get(BlogRepository::class)->postCount()]);
        });

        $app->get('/blogs', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'blog:read', $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['blogs' => $c->get(BlogRepository::class)->blogs()]);
        });

        $app->post('/blogs', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'blog:write', $res)) !== null) {
                return $deny;
            }
            $body = (array) $req->getParsedBody();
            $key = strtolower(trim((string) ($body['blog_key'] ?? '')));
            $name = trim((string) ($body['name'] ?? ''));
            if (preg_match('/^[a-z0-9-]{2,64}$/', $key) !== 1 || $name === '') {
                return self::json($res, ['error' => 'blog_key (kebab) and name are required'], 422);
            }
            $repo = $c->get(BlogRepository::class);
            if ($repo->blogKeyExists($key)) {
                return self::json($res, ['error' => 'blog_key already exists'], 409);
            }
            return self::json($res, ['id' => $repo->createBlog($key, $name)], 201);
        });

        // --- authors (byline registry, blog-agnostic) -------------------------
        $app->get('/blog/authors', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'blog:read', $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['authors' => $c->get(BlogRepository::class)->authors()]);
        });

        $app->post('/blog/authors', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'blog:write', $res)) !== null) {
                return $deny;
            }
            $body = (array) $req->getParsedBody();
            $name = trim((string) ($body['name'] ?? ''));
            if (mb_strlen($name) < 2) {
                return self::json($res, ['error' => 'name is required'], 422);
            }
            $bio = self::optional($body['bio'] ?? null, 500);
            $avatar = self::optional($body['avatar_url'] ?? null, 500);
            $repo = $c->get(BlogRepository::class);
            // A user_id ties the byline to a panel user (one snapshot per user);
            // absent ⇒ a free-form / guest author.
            $userId = (int) ($body['user_id'] ?? 0);
            $id = $userId > 0
                ? $repo->upsertAuthorFromUser($userId, $name, $bio, $avatar)
                : $repo->createAuthor($name, $bio, $avatar);
            return self::json($res, ['id' => $id], 201);
        });

        $app->delete('/blog/authors/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'blog:write', $res)) !== null) {
                return $deny;
            }
            $c->get(BlogRepository::class)->deleteAuthor((int) $args['id']);
            return self::json($res, ['ok' => true]);
        });

        $app->get('/blogs/{blog:[a-z0-9-]+}/posts', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'blog:read', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(BlogRepository::class);
            $blog = $repo->findBlog((string) $args['blog']);
            if ($blog === null) {
                return self::json($res, ['error' => 'Blog not found'], 404);
            }
            return self::json($res, ['posts' => $repo->posts((int) $blog['id'])]);
        });

        $app->get('/blogs/{blog:[a-z0-9-]+}/posts/{slug:[a-z0-9-]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'blog:read', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(BlogRepository::class);
            $blog = $repo->findBlog((string) $args['blog']);
            if ($blog === null) {
                return self::json($res, ['error' => 'Blog not found'], 404);
            }
            $post = $repo->getPost((int) $blog['id'], (string) $args['slug'], self::lang($req->getQueryParams()['lang'] ?? 'de'));
            if ($post === null) {
                return self::json($res, ['error' => 'Post not found'], 404);
            }
            return self::json($res, $post);
        });

        $app->put('/blogs/{blog:[a-z0-9-]+}/posts/{slug:[a-z0-9-]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'blog:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(BlogRepository::class);
            $blog = $repo->findBlog((string) $args['blog']);
            if ($blog === null) {
                return self::json($res, ['error' => 'Blog not found'], 404);
            }
            $body = (array) $req->getParsedBody();
            $title = trim((string) ($body['title'] ?? ''));
            $content = trim((string) ($body['body'] ?? ''));
            if ($title === '' || $content === '') {
                return self::json($res, ['error' => 'title and body are required'], 422);
            }
            $draft = (bool) ($body['draft'] ?? true);
            $lang = self::lang($body['lang'] ?? 'de');
            // Author is optional; an unknown id is dropped rather than rejected.
            $authorId = (int) ($body['author_id'] ?? 0);
            if ($authorId > 0 && !$repo->authorExists($authorId)) {
                $authorId = 0;
            }
            $data = [
                'category' => trim((string) ($body['category'] ?? 'allgemein')) ?: 'allgemein',
                'title' => $title,
                'excerpt' => (string) ($body['excerpt'] ?? ''),
                'meta_description' => self::optional($body['meta_description'] ?? null, 300),
                'tags' => self::optional($body['tags'] ?? null, 200),
                'body' => $content,
                'cover_hint' => isset($body['cover_hint']) && $body['cover_hint'] !== '' ? (string) $body['cover_hint'] : null,
                'author_id' => $authorId > 0 ? $authorId : null,
                'draft' => $draft,
                // Publishing sets published_at when it's a non-draft with none yet.
                'published_at' => $draft ? null : ($body['published_at'] ?? date('Y-m-d H:i:s')),
                // A manual save is authored content — clears any machine-translated flag.
                'machine_translated' => false,
            ];
            $repo->upsertPost((int) $blog['id'], (string) $args['slug'], $lang, $data);
            // Auto-translate the counterpart language (best-effort, published only).
            $translated = $c->get(TranslationSync::class)->afterSave((int) $blog['id'], (string) $args['slug'], $lang, $data);
            // A published (non-draft) save re-bakes the static blog; drafts don't.
            if (!$draft) {
                self::fireRebuild($c->get(RebuildTrigger::class), $blog, 'post ' . (string) $args['slug'] . ' saved');
            }
            return self::json($res, ['ok' => true, 'translated' => $translated]);
        });

        // Set a blog's rebuild target (repo/workflow); blank clears it.
        $app->put('/blogs/{blog:[a-z0-9-]+}/rebuild-config', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'blog:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(BlogRepository::class);
            $blog = $repo->findBlog((string) $args['blog']);
            if ($blog === null) {
                return self::json($res, ['error' => 'Blog not found'], 404);
            }
            $body = (array) $req->getParsedBody();
            $repoName = trim((string) ($body['rebuild_repo'] ?? ''));
            $workflow = trim((string) ($body['rebuild_workflow'] ?? ''));
            if ($repoName !== '' && preg_match('#^[\w.-]+/[\w.-]+$#', $repoName) !== 1) {
                return self::json($res, ['error' => 'rebuild_repo must be "owner/name"'], 422);
            }
            $repo->updateBlogRebuild((int) $blog['id'], $repoName !== '' ? $repoName : null, $workflow !== '' ? $workflow : null);
            return self::json($res, ['ok' => true]);
        });

        // Manually fire a blog's rebuild ("Jetzt neu bauen").
        $app->post('/blogs/{blog:[a-z0-9-]+}/rebuild', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'blog:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(BlogRepository::class);
            $blog = $repo->findBlog((string) $args['blog']);
            if ($blog === null) {
                return self::json($res, ['error' => 'Blog not found'], 404);
            }
            $trigger = $c->get(RebuildTrigger::class);
            if (!$trigger->isConfigured()) {
                return self::json($res, ['error' => 'Rebuild token not configured'], 503);
            }
            if (trim((string) ($blog['rebuild_repo'] ?? '')) === '') {
                return self::json($res, ['error' => 'No rebuild repo configured for this blog'], 422);
            }
            self::fireRebuild($trigger, $blog, 'manual rebuild');
            return self::json($res, ['ok' => true], 202);
        });

        // Catch up translations for pre-existing posts of a blog (button in tds-admin).
        $app->post('/blogs/{blog:[a-z0-9-]+}/translations/backfill', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'blog:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(BlogRepository::class);
            $blog = $repo->findBlog((string) $args['blog']);
            if ($blog === null) {
                return self::json($res, ['error' => 'Blog not found'], 404);
            }
            $sync = $c->get(TranslationSync::class);
            if (!$sync->active()) {
                return self::json($res, ['error' => 'Auto-translation is not configured'], 503);
            }
            $created = 0;
            $skipped = 0;
            foreach ($repo->posts((int) $blog['id']) as $meta) {
                // Machine rows are targets, not sources; drafts have nothing to mirror.
                if ((int) ($meta['machine_translated'] ?? 0) === 1 || (int) ($meta['draft'] ?? 1) === 1) {
                    $skipped++;
                    continue;
                }
                $full = $repo->getPost((int) $blog['id'], (string) $meta['slug'], (string) $meta['lang']);
                if ($full === null) {
                    $skipped++;
                    continue;
                }
                $wrote = $sync->afterSave((int) $blog['id'], (string) $meta['slug'], (string) $meta['lang'], [
                    'category' => (string) ($full['category'] ?? 'allgemein'),
                    'title' => (string) ($full['title'] ?? ''),
                    'excerpt' => (string) ($full['excerpt'] ?? ''),
                    'meta_description' => isset($full['meta_description']) ? (string) $full['meta_description'] : null,
                    'tags' => isset($full['tags']) ? (string) $full['tags'] : null,
                    'body' => (string) ($full['body'] ?? ''),
                    'cover_hint' => isset($full['cover_hint']) ? (string) $full['cover_hint'] : null,
                    'author_id' => isset($full['author_id']) ? (int) $full['author_id'] : null,
                    'draft' => false,
                    'published_at' => $full['published_at'] ?? null,
                ]);
                $wrote ? $created++ : $skipped++;
            }
            if ($created > 0) {
                self::fireRebuild($c->get(RebuildTrigger::class), $blog, 'translation backfill');
            }
            return self::json($res, ['created' => $created, 'skipped' => $skipped]);
        });

        $app->delete('/blogs/{blog:[a-z0-9-]+}/posts/{slug:[a-z0-9-]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'blog:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(BlogRepository::class);
            $blog = $repo->findBlog((string) $args['blog']);
            if ($blog === null) {
                return self::json($res, ['error' => 'Blog not found'], 404);
            }
            $lang = self::lang($req->getQueryParams()['lang'] ?? 'de');
            $repo->deletePost((int) $blog['id'], (string) $args['slug'], $lang);
            // A machine-translated counterpart was derived from this row — drop it too.
            $c->get(TranslationSync::class)->afterDelete((int) $blog['id'], (string) $args['slug'], $lang);
            self::fireRebuild($c->get(RebuildTrigger::class), $blog, 'post ' . (string) $args['slug'] . ' deleted');
            return self::json($res, ['ok' => true]);
        });
    }

    // --- helpers ---------------------------------------------------------------

    /** @param array<string,mixed> $blog */
    private static function fireRebuild(RebuildTrigger $trigger, array $blog, string $reason): void
    {
        $trigger->trigger(
            isset($blog['rebuild_repo']) ? (string) $blog['rebuild_repo'] : null,
            isset($blog['rebuild_workflow']) ? (string) $blog['rebuild_workflow'] : null,
            $reason,
        );
    }

    private static function require(UserContext $user, string $permission, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->has($permission)) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }
        return null;
    }

    private static function lang(mixed $value): string
    {
        $v = is_string($value) ? strtolower($value) : '';
        return in_array($v, self::LANGS, true) ? $v : 'de';
    }

    /** Nullable lang for the public read routes (absent/invalid → null = both langs). */
    private static function publicLang(mixed $value): ?string
    {
        $v = is_string($value) ? strtolower($value) : '';
        return in_array($v, self::LANGS, true) ? $v : null;
    }

    /**
     * Map a DB row to the camelCase `BlogPost` shape tds-shared defines (the
     * contract the public blog/landingpage consume). `body` (+ created/updated)
     * is present only on the single-post read. Fields this module has no column
     * for (viewCount, adsMode, block bodyFormat) are omitted — all optional in
     * `BlogPost`, so the frontend applies its defaults (view 0, ads "default",
     * markdown body).
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function publicShape(array $r): array
    {
        $author = ($r['author_id'] ?? null) !== null ? [
            'id' => (int) $r['author_id'],
            'name' => (string) ($r['author_name'] ?? ''),
            'slug' => self::slugify((string) ($r['author_name'] ?? '')),
            'avatarUrl' => ($r['author_avatar_url'] ?? null) !== null ? (string) $r['author_avatar_url'] : null,
            'bio' => ($r['author_bio'] ?? null) !== null ? (string) $r['author_bio'] : null,
        ] : null;

        $out = [
            'id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'lang' => (string) $r['lang'],
            'category' => (string) ($r['category'] ?? ''),
            'title' => (string) $r['title'],
            'excerpt' => (string) ($r['excerpt'] ?? ''),
            'tags' => ($r['tags'] ?? null) !== null ? (string) $r['tags'] : '',
            'coverHint' => ($r['cover_hint'] ?? null) !== null ? (string) $r['cover_hint'] : null,
            'publishedAt' => ($r['published_at'] ?? null) !== null ? (string) $r['published_at'] : null,
            'draft' => false,
            'machineTranslated' => (bool) ($r['machine_translated'] ?? false),
            'authorId' => ($r['author_id'] ?? null) !== null ? (int) $r['author_id'] : null,
            'author' => $author,
        ];
        // Full-post read carries the body + timestamps + SEO meta.
        if (array_key_exists('body', $r)) {
            $out['body'] = (string) $r['body'];
            $out['bodyFormat'] = 'markdown';
            $out['metaDescription'] = ($r['meta_description'] ?? null) !== null ? (string) $r['meta_description'] : null;
            $out['createdAt'] = (string) ($r['created_at'] ?? $r['published_at'] ?? '');
            $out['updatedAt'] = (string) ($r['updated_at'] ?? $r['published_at'] ?? '');
        }
        return $out;
    }

    /** Best-effort URL slug from an author name (this module stores no slug column). */
    private static function slugify(string $name): string
    {
        $s = strtolower(trim($name));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    private static function optional(mixed $value, int $limit): ?string
    {
        $v = trim((string) ($value ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $limit);
    }

    /**
     * The core's settings store if the base bound it (it resolves the contract
     * interface), else null — so an isolated unit test (no core) falls back to env.
     */
    private static function setting(\Psr\Container\ContainerInterface $c): ?SettingsStore
    {
        return $c->has(SettingsStore::class) ? $c->get(SettingsStore::class) : null;
    }

    private static function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
