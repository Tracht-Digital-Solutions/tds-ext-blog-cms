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
            'SELECT id, blog_key, name, updated_at FROM blog ORDER BY name, id'
        )->fetchAll();
    }

    public function postCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM blog_post')->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function findBlog(string $blogKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, blog_key, name FROM blog WHERE blog_key = :k LIMIT 1');
        $stmt->execute([':k' => $blogKey]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
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

    // --- posts ----------------------------------------------------------------

    /** Post metadata for a blog (not the body). @return list<array<string,mixed>> */
    public function posts(int $blogId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT slug, lang, category, title, draft, published_at, updated_at
             FROM blog_post WHERE blog_id = :b ORDER BY COALESCE(published_at, updated_at) DESC'
        );
        $stmt->execute([':b' => $blogId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function getPost(int $blogId, string $slug, string $lang): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT slug, lang, category, title, excerpt, body, cover_hint, published_at, draft
             FROM blog_post WHERE blog_id = :b AND slug = :s AND lang = :l LIMIT 1'
        );
        $stmt->execute([':b' => $blogId, ':s' => $slug, ':l' => $lang]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Insert or update a post by (blog, slug, lang).
     *
     * @param array{category:string,title:string,excerpt:string,body:string,cover_hint:?string,draft:bool,published_at:?string} $d
     */
    public function upsertPost(int $blogId, string $slug, string $lang, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO blog_post (blog_id, slug, lang, category, title, excerpt, body, cover_hint, draft, published_at)
             VALUES (:b, :s, :l, :cat, :title, :excerpt, :body, :cover, :draft, :pub)
             ON DUPLICATE KEY UPDATE
                category = :cat2, title = :title2, excerpt = :excerpt2, body = :body2,
                cover_hint = :cover2, draft = :draft2, published_at = :pub2'
        );
        $stmt->execute([
            ':b' => $blogId, ':s' => $slug, ':l' => $lang,
            ':cat' => $d['category'], ':title' => $d['title'], ':excerpt' => $d['excerpt'],
            ':body' => $d['body'], ':cover' => $d['cover_hint'], ':draft' => $d['draft'] ? 1 : 0, ':pub' => $d['published_at'],
            ':cat2' => $d['category'], ':title2' => $d['title'], ':excerpt2' => $d['excerpt'],
            ':body2' => $d['body'], ':cover2' => $d['cover_hint'], ':draft2' => $d['draft'] ? 1 : 0, ':pub2' => $d['published_at'],
        ]);
    }

    public function deletePost(int $blogId, string $slug, string $lang): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM blog_post WHERE blog_id = :b AND slug = :s AND lang = :l');
        $stmt->execute([':b' => $blogId, ':s' => $slug, ':l' => $lang]);
    }
}
