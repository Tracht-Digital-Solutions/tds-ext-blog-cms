<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The registry of managed blogs (the "1:n" of Blog-CMS). Each blog has a stable
 * `blog_key` (used in URLs + post scoping) and an optional rebuild hook. Module-
 * prefixed class name (in-process auto-migrator loads all modules into one process).
 */
final class CreateBlogCmsBlog extends AbstractMigration
{
    public function change(): void
    {
        $this->table('blog', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('blog_key', 'string', ['limit' => 64])
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('rebuild_repo', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('rebuild_workflow', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['blog_key'], ['unique' => true, 'name' => 'uniq_blog_key'])
            ->create();
    }
}
