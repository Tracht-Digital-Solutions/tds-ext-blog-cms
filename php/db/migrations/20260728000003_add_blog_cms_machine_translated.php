<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * CP4: a `machine_translated` flag on blog_post for the save-time DeepL sync.
 * 1 = this row was auto-generated from its counterpart language and may be
 * overwritten by a fresh translation; 0 = manually authored, never touched by
 * the sync. Module-prefixed class name (in-process auto-migrator loads every
 * module's migrations into one process).
 */
final class AddBlogCmsMachineTranslated extends AbstractMigration
{
    public function change(): void
    {
        $this->table('blog_post')
            ->addColumn('machine_translated', 'boolean', ['default' => false, 'after' => 'draft'])
            ->update();
    }
}
