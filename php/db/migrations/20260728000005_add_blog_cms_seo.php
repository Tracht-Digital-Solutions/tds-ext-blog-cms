<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * CP6: SEO fields on blog_post — `meta_description` (the `<meta name=description>`
 * / OG description the static blog bakes) and `tags` (comma-separated keyword
 * tokens for keyword meta + tag pages). Both nullable; a post without them falls
 * back to its excerpt/category. Module-prefixed class name.
 */
final class AddBlogCmsSeo extends AbstractMigration
{
    public function change(): void
    {
        $this->table('blog_post')
            ->addColumn('meta_description', 'string', ['limit' => 300, 'null' => true, 'after' => 'excerpt'])
            ->addColumn('tags', 'string', ['limit' => 200, 'null' => true, 'after' => 'meta_description'])
            ->update();
    }
}
