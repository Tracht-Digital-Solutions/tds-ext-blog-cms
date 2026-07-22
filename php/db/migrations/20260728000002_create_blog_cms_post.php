<?php
declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Blog posts, ported from tds-content-api's blog_post, extended with blog_id for
 * the multi-blog model. One row per (blog, slug, language). Published posts
 * (draft=0, published_at set) are fetched by the static blogs at build time.
 * Module-prefixed class name.
 */
final class CreateBlogCmsPost extends AbstractMigration
{
    public function change(): void
    {
        $this->table('blog_post', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('blog_id', 'integer', ['signed' => false])
            ->addColumn('slug', 'string', ['limit' => 120])
            ->addColumn('lang', 'string', ['limit' => 2, 'default' => 'de'])
            ->addColumn('category', 'string', ['limit' => 40, 'default' => 'allgemein'])
            ->addColumn('title', 'string', ['limit' => 200])
            ->addColumn('excerpt', 'text')
            ->addColumn('body', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM])
            ->addColumn('cover_hint', 'string', ['limit' => 400, 'null' => true])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('draft', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['blog_id', 'slug', 'lang'], ['unique' => true, 'name' => 'uniq_blog_post_slug'])
            ->addIndex(['blog_id', 'published_at'], ['name' => 'idx_blog_post_published'])
            ->addForeignKey('blog_id', 'blog', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
