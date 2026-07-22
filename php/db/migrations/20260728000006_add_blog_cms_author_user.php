<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * CP9: link a `blog_author` byline to a panel user (tds-auth-api `app_user`).
 * `user_id` is a plain (unsigned) reference — NOT a DB FK, since app_user lives
 * in a different service's schema (same rule as the ticket customer_id refs). The
 * author row stays a SNAPSHOT (name/bio/avatar) so the public byline survives a
 * later user removal. A partial unique index keeps at most one snapshot per user
 * (multiple NULLs allowed for free-form/guest authors). Module-prefixed class.
 */
final class AddBlogCmsAuthorUser extends AbstractMigration
{
    public function change(): void
    {
        $this->table('blog_author')
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'id'])
            ->addIndex(['user_id'], ['unique' => true, 'name' => 'uniq_blog_author_user'])
            ->update();
    }
}
