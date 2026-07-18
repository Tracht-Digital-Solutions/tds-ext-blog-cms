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
use Tds\Panel\Contract\AbstractModule;
use Tds\Panel\Contract\PermissionDef;
use Tds\Panel\Contract\UserContext;

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
            $c->set(RebuildTrigger::class, static fn () => RebuildTrigger::fromEnv());
        }
        if ($c !== null && !$c->has(TranslationSync::class)) {
            $c->set(TranslationSync::class, static fn ($c) => TranslationSync::fromEnv(
                $c->get(BlogRepository::class),
                DeeplTranslator::fromEnv(),
            ));
        }

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
            return self::json($res, ['id' => $c->get(BlogRepository::class)->createAuthor($name, $bio, $avatar)], 201);
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
                    'body' => (string) ($full['body'] ?? ''),
                    'cover_hint' => isset($full['cover_hint']) ? (string) $full['cover_hint'] : null,
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

    private static function optional(mixed $value, int $limit): ?string
    {
        $v = trim((string) ($value ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $limit);
    }

    private static function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
