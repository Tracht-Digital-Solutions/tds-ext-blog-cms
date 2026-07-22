<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * CP5: a `blog_author` registry (byline shown on the public post) + a nullable
 * `blog_post.author_id` FK. Authors are reusable across posts and independent of
 * panel users (self-contained in blog-cms). Deleting an author detaches its posts
 * (`ON DELETE SET NULL`) rather than cascading. `author_id` is `signed => false`
 * to match the unsigned Phinx PK (MySQL-8 FK type match). Module-prefixed class.
 */
final class AddBlogCmsAuthor extends AbstractMigration
{
    public function change(): void
    {
        $this->table('blog_author', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('bio', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('avatar_url', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->create();

        $this->table('blog_post')
            ->addColumn('author_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'cover_hint'])
            ->addForeignKey('author_id', 'blog_author', 'id', ['delete' => 'SET_NULL'])
            ->update();
    }
}
