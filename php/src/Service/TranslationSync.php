<?php
declare(strict_types=1);

namespace Tds\Ext\BlogCms\Service;

use Tds\Ext\BlogCms\Domain\BlogRepository;

/**
 * Save-time DeepL sync for blog posts (a blog-cms-scoped port of tds-content-api's
 * TranslationSync). When an admin saves a post in one language, the counterpart-
 * language row is auto-created/refreshed — but only when it's absent or itself
 * machine-made:
 *
 *   counterpart absent               → create it, machine_translated=1
 *   counterpart machine_translated=1 → overwrite with a fresh translation
 *   counterpart machine_translated=0 → hands off (manually authored)
 *
 * The sync writes straight through the repository (never the route) so it can't
 * re-trigger itself. Any DeepL failure only logs; the admin's save already
 * succeeded. Drafts are skipped (nothing published to mirror).
 */
final class TranslationSync
{
    public function __construct(
        private readonly BlogRepository $posts,
        private readonly DeeplTranslator $translator,
        private readonly bool $enabled,
    ) {
    }

    public static function fromEnv(BlogRepository $posts, DeeplTranslator $translator): self
    {
        // Enabled by default when a key is present; BLOG_AUTO_TRANSLATE=0 opts out.
        $flag = getenv('BLOG_AUTO_TRANSLATE');
        $enabled = $flag === false ? true : !in_array(strtolower((string) $flag), ['0', 'false', 'no', 'off'], true);
        return new self($posts, $translator, $enabled);
    }

    public function active(): bool
    {
        return $this->enabled && $this->translator->configured();
    }

    /**
     * @param array{category:string,title:string,excerpt:string,body:string,cover_hint:?string,draft:bool,published_at:?string,author_id?:?int,meta_description?:?string,tags?:?string} $source
     * @return bool true when a counterpart row was actually written
     */
    public function afterSave(int $blogId, string $slug, string $sourceLang, array $source): bool
    {
        if (!$this->active() || $source['draft']) {
            return false;
        }
        $other = self::otherLang($sourceLang);

        $existing = $this->posts->getPost($blogId, $slug, $other);
        if ($existing !== null && (int) ($existing['machine_translated'] ?? 0) === 0) {
            return false; // manually authored translation — never touched
        }

        // The SEO meta description rides the same batched request as the core
        // fields (it must not stay in the source language on the counterpart page).
        $sourceMeta = isset($source['meta_description']) ? trim((string) $source['meta_description']) : '';
        $texts = [$source['title'], $source['excerpt'], $source['category']];
        if ($sourceMeta !== '') {
            $texts[] = $sourceMeta;
        }
        $meta = $this->translator->translateTexts($texts, $other, $sourceLang);
        $body = $this->translator->translateMarkdown($source['body'], $other, $sourceLang);
        if ($meta === null || $body === null) {
            error_log(sprintf(
                '[blog-cms] auto-translation skipped for %s/%s → %s (DeepL unavailable)',
                $sourceLang,
                $slug,
                $other,
            ));
            return false;
        }

        $this->posts->upsertPost($blogId, $slug, $other, [
            'category' => self::clampCategory($meta[2], $source['category']),
            'title' => $meta[0],
            'excerpt' => $meta[1],
            'meta_description' => $sourceMeta !== '' ? ($meta[3] ?? null) : null,
            // Tags are stable keyword tokens — kept identical across languages.
            'tags' => $source['tags'] ?? null,
            'body' => $body,
            'cover_hint' => $source['cover_hint'],
            // The byline is the same person in either language.
            'author_id' => $source['author_id'] ?? null,
            'draft' => false,
            'published_at' => $source['published_at'],
            'machine_translated' => true,
        ]);
        return true;
    }

    public function afterDelete(int $blogId, string $slug, string $sourceLang): void
    {
        if (!$this->enabled) {
            return;
        }
        $other = self::otherLang($sourceLang);
        $counterpart = $this->posts->getPost($blogId, $slug, $other);
        if ($counterpart !== null && (int) ($counterpart['machine_translated'] ?? 0) === 1) {
            $this->posts->deletePost($blogId, $slug, $other);
        }
    }

    private static function otherLang(string $lang): string
    {
        return $lang === 'de' ? 'en' : 'de';
    }

    /** A translated category must still satisfy the 2-40 char window. */
    private static function clampCategory(string $translated, string $fallback): string
    {
        $t = trim($translated);
        if (mb_strlen($t) < 2) {
            return $fallback;
        }
        return mb_substr($t, 0, 40);
    }
}
