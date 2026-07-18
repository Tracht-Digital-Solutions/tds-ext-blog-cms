<?php
declare(strict_types=1);

namespace Tds\Ext\BlogCms\Service;

/**
 * Best-effort GitHub `workflow_dispatch` trigger — fires a static-blog rebuild
 * after a post is saved or deleted, so the blog re-bakes with the edit.
 *
 * Per-blog config lives on `blog` (rebuild_repo / rebuild_workflow); one
 * org-scoped PAT is shared via `BLOG_REBUILD_TOKEN` (a single PAT can dispatch
 * every blog repo). MUST NOT throw — a flaky GitHub API or a missing token must
 * never roll back a successful post save; failures are logged.
 *
 * Uses plain ext-curl (no Guzzle dependency), mirroring the platform's lean-
 * dependency approach. Ported in spirit from tds-content-api's
 * GithubBlogRebuildTrigger.
 *
 * @see https://docs.github.com/en/rest/actions/workflows#create-a-workflow-dispatch-event
 */
final class RebuildTrigger
{
    public function __construct(
        /** GitHub PAT with `repo`/`workflow` scope; empty ⇒ no-op. */
        private readonly string $token,
        private readonly string $ref = 'main',
    ) {
    }

    /** Read the shared rebuild PAT from the environment (env-first, DB later). */
    public static function fromEnv(): self
    {
        $token = (string) (getenv('BLOG_REBUILD_TOKEN') ?: '');
        $ref = (string) (getenv('BLOG_REBUILD_REF') ?: 'main');
        return new self($token, $ref !== '' ? $ref : 'main');
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    /**
     * Dispatch `repo`'s `workflowFile`. `repo` is "owner/name"; `workflowFile`
     * is the workflow file name (e.g. "dev.yml"). No-op when unconfigured or the
     * blog carries no rebuild target.
     */
    public function trigger(?string $repo, ?string $workflowFile, string $reason): void
    {
        $repo = trim((string) $repo);
        $workflowFile = trim((string) ($workflowFile ?: 'dev.yml'));
        if ($this->token === '' || $repo === '') {
            return;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/actions/workflows/%s/dispatches',
            $repo,
            rawurlencode($workflowFile),
        );
        // Send `ref` only: the dispatches endpoint 422s on any input the workflow
        // doesn't declare (dev.yml/release.yml declare none). Reason is logged only.
        $payload = json_encode(['ref' => $this->ref], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'Content-Type: application/json',
                'User-Agent: tds-ext-blog-cms',
            ],
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        // GitHub returns 204 No Content on success.
        if ($ok === false || $code >= 300) {
            error_log(sprintf(
                '[blog-cms] rebuild trigger failed (%s → %s, HTTP %d): %s',
                $reason,
                $repo,
                $code,
                $err,
            ));
        }
    }
}
