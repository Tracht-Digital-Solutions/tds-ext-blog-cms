<?php
declare(strict_types=1);

namespace Tds\Ext\BlogCms\Domain;

use PDO;

/**
 * Blog-CMS data access: the blog registry + posts scoped per blog. Posts are
 * upserted by (blog, slug, lang). Ported from tds-content-api's blog repository,
 * extended for the multi-blog model.
 */
final class BlogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    // --- blogs ----------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function blogs(): array
    {
        return $this->pdo->query(
            'SELECT id, blog_key, name, rebuild_repo, rebuild_workflow, updated_at FROM blog ORDER BY name, id'
        )->fetchAll();
    }

    public function postCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM blog_post')->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function findBlog(string $blogKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, blog_key, name, rebuild_repo, rebuild_workflow FROM blog WHERE blog_key = :k LIMIT 1'
        );
        $stmt->execute([':k' => $blogKey]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function updateBlogRebuild(int $blogId, ?string $repo, ?string $workflow): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE blog SET rebuild_repo = :r, rebuild_workflow = :w WHERE id = :id'
        );
        $stmt->execute([':r' => $repo, ':w' => $workflow, ':id' => $blogId]);
    }

    public function blogKeyExists(string $blogKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM blog WHERE blog_key = :k LIMIT 1');
        $stmt->execute([':k' => $blogKey]);
        return $stmt->fetchColumn() !== false;
    }

    public function createBlog(string $blogKey, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO blog (blog_key, name) VALUES (:k, :n)');
        $stmt->execute([':k' => $blogKey, ':n' => $name]);
        return (int) $this->pdo->lastInsertId();
    }

    // --- authors --------------------------------------------------------------

    /** All authors (byline registry). @return list<array<string,mixed>> */
    public function authors(): array
    {
        return $this->pdo->query(
            'SELECT id, name, bio, avatar_url FROM blog_author ORDER BY name, id'
        )->fetchAll();
    }

    public function createAuthor(string $name, ?string $bio, ?string $avatarUrl): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO blog_author (name, bio, avatar_url) VALUES (:n, :b, :a)'
        );
        $stmt->execute([':n' => $name, ':b' => $bio, ':a' => $avatarUrl]);
        return (int) $this->pdo->lastInsertId();
    }

    public function authorExists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM blog_author WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchColumn() !== false;
    }

    /** Deleting an author detaches its posts (FK ON DELETE SET NULL). */
    public function deleteAuthor(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM blog_author WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    // --- posts ----------------------------------------------------------------

    /** Post metadata for a blog (not the body). @return list<array<string,mixed>> */
    public function posts(int $blogId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.slug, p.lang, p.category, p.title, p.draft, p.machine_translated,
                    p.published_at, p.updated_at, p.author_id, a.name AS author_name
             FROM blog_post p LEFT JOIN blog_author a ON a.id = p.author_id
             WHERE p.blog_id = :b ORDER BY COALESCE(p.published_at, p.updated_at) DESC'
        );
        $stmt->execute([':b' => $blogId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function getPost(int $blogId, string $slug, string $lang): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.slug, p.lang, p.category, p.title, p.excerpt, p.body, p.cover_hint,
                    p.published_at, p.draft, p.machine_translated, p.author_id,
                    a.name AS author_name, a.bio AS author_bio, a.avatar_url AS author_avatar_url
             FROM blog_post p LEFT JOIN blog_author a ON a.id = p.author_id
             WHERE p.blog_id = :b AND p.slug = :s AND p.lang = :l LIMIT 1'
        );
        $stmt->execute([':b' => $blogId, ':s' => $slug, ':l' => $lang]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        // Fold the joined author columns into a nested `author` object for the byline.
        $row['author'] = $row['author_id'] !== null ? [
            'id' => (int) $row['author_id'],
            'name' => (string) $row['author_name'],
            'bio' => $row['author_bio'] !== null ? (string) $row['author_bio'] : null,
            'avatar_url' => $row['author_avatar_url'] !== null ? (string) $row['author_avatar_url'] : null,
        ] : null;
        unset($row['author_name'], $row['author_bio'], $row['author_avatar_url']);
        return $row;
    }

    /**
     * Insert or update a post by (blog, slug, lang).
     *
     * @param array{category:string,title:string,excerpt:string,body:string,cover_hint:?string,draft:bool,published_at:?string,machine_translated?:bool,author_id?:?int} $d
     */
    public function upsertPost(int $blogId, string $slug, string $lang, array $d): void
    {
        $machine = !empty($d['machine_translated']) ? 1 : 0;
        $authorId = isset($d['author_id']) && (int) $d['author_id'] > 0 ? (int) $d['author_id'] : null;
        $stmt = $this->pdo->prepare(
            'INSERT INTO blog_post (blog_id, slug, lang, category, title, excerpt, body, cover_hint, author_id, draft, machine_translated, published_at)
             VALUES (:b, :s, :l, :cat, :title, :excerpt, :body, :cover, :author, :draft, :mt, :pub)
             ON DUPLICATE KEY UPDATE
                category = :cat2, title = :title2, excerpt = :excerpt2, body = :body2,
                cover_hint = :cover2, author_id = :author2, draft = :draft2, machine_translated = :mt2, published_at = :pub2'
        );
        $stmt->execute([
            ':b' => $blogId, ':s' => $slug, ':l' => $lang,
            ':cat' => $d['category'], ':title' => $d['title'], ':excerpt' => $d['excerpt'],
            ':body' => $d['body'], ':cover' => $d['cover_hint'], ':author' => $authorId, ':draft' => $d['draft'] ? 1 : 0, ':mt' => $machine, ':pub' => $d['published_at'],
            ':cat2' => $d['category'], ':title2' => $d['title'], ':excerpt2' => $d['excerpt'],
            ':body2' => $d['body'], ':cover2' => $d['cover_hint'], ':author2' => $authorId, ':draft2' => $d['draft'] ? 1 : 0, ':mt2' => $machine, ':pub2' => $d['published_at'],
        ]);
    }

    public function deletePost(int $blogId, string $slug, string $lang): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM blog_post WHERE blog_id = :b AND slug = :s AND lang = :l');
        $stmt->execute([':b' => $blogId, ':s' => $slug, ':l' => $lang]);
    }
}
