<?php
declare(strict_types=1);

namespace Tds\Ext\BlogCms\Tests;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\Ext\BlogCms\BlogCmsModule;
use Tds\Panel\Contract\UserContext;

/** Configurable UserContext double. */
final class FakeUser implements UserContext
{
    /** @param string[] $perms */
    public function __construct(
        private bool $auth = true,
        private bool $admin = false,
        private array $perms = [],
    ) {
    }

    public function isAuthenticated(): bool
    {
        return $this->auth;
    }

    public function userId(): ?int
    {
        return 1;
    }

    public function email(): ?string
    {
        return null;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    /** @return string[] */
    public function permissions(): array
    {
        return $this->perms;
    }

    public function has(string $permission): bool
    {
        return $this->admin || in_array($permission, $this->perms, true);
    }

    public function activeCompanyId(): ?int
    {
        return null;
    }
}

/** Route + RBAC coverage without a DB (auth + validation short-circuit before the repo). */
final class BlogCmsModuleTest extends TestCase
{
    private function appWith(UserContext $user): \Slim\App
    {
        $container = new Container();
        $container->set(UserContext::class, $user);
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        (new BlogCmsModule())->register($app);
        return $app;
    }

    private function get(\Slim\App $app, string $path): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }

    /** @param array<string,mixed> $body */
    private function post(\Slim\App $app, string $path, array $body): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', $path)->withParsedBody($body)
        );
    }

    public function testMetadata(): void
    {
        $module = new BlogCmsModule();
        self::assertSame('blog-cms', $module->id());
        $ids = array_map(static fn ($p): string => $p->id, $module->permissions());
        self::assertSame(['blog:read', 'blog:write'], $ids);
        self::assertDirectoryExists($module->migrations()[0]);
    }

    public function testSummaryRequiresAuth(): void
    {
        self::assertSame(401, $this->get($this->appWith(new FakeUser(auth: false)), '/blog/summary')->getStatusCode());
    }

    public function testSummaryForbiddenWithoutPermission(): void
    {
        self::assertSame(403, $this->get($this->appWith(new FakeUser(perms: [])), '/blog/summary')->getStatusCode());
    }

    public function testCreateBlogRequiresWrite(): void
    {
        $res = $this->post($this->appWith(new FakeUser(perms: ['blog:read'])), '/blogs', ['blog_key' => 'x', 'name' => 'X']);
        self::assertSame(403, $res->getStatusCode());
    }

    public function testCreateBlogValidatesKey(): void
    {
        $res = $this->post($this->appWith(new FakeUser(perms: ['blog:write'])), '/blogs', ['blog_key' => 'Bad Key', 'name' => 'X']);
        self::assertSame(422, $res->getStatusCode());
    }
}
