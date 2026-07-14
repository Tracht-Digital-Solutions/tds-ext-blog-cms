<?php
declare(strict_types=1);

namespace Tds\Ext\BlogCms;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tds\Ext\BlogCms\Domain\BlogRepository;
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
            $repo->upsertPost((int) $blog['id'], (string) $args['slug'], self::lang($body['lang'] ?? 'de'), [
                'category' => trim((string) ($body['category'] ?? 'allgemein')) ?: 'allgemein',
                'title' => $title,
                'excerpt' => (string) ($body['excerpt'] ?? ''),
                'body' => $content,
                'cover_hint' => isset($body['cover_hint']) && $body['cover_hint'] !== '' ? (string) $body['cover_hint'] : null,
                'draft' => $draft,
                // Publishing sets published_at when it's a non-draft with none yet.
                'published_at' => $draft ? null : ($body['published_at'] ?? date('Y-m-d H:i:s')),
            ]);
            return self::json($res, ['ok' => true]);
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
            $repo->deletePost((int) $blog['id'], (string) $args['slug'], self::lang($req->getQueryParams()['lang'] ?? 'de'));
            return self::json($res, ['ok' => true]);
        });
    }

    // --- helpers ---------------------------------------------------------------

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

    private static function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
