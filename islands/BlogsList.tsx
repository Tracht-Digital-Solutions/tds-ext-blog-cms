import { useEffect, useState } from "react";

/**
 * Minimal, safe-by-construction markdown → HTML for the editor PREVIEW only (the
 * public blog uses the full build-time pipeline). All text is HTML-escaped BEFORE
 * any transform, so raw HTML / scripts in the source become inert text — no
 * sanitizer needed and no XSS surface (this is why we don't pull in marked +
 * dompurify). Covers the common subset: fenced/inline code, headings, bold,
 * italic, links (http/https/mailto/relative only), unordered lists, paragraphs.
 */
function escapeHtml(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function safeHref(url: string): string | null {
  const u = url.trim();
  return /^(https?:\/\/|mailto:|\/|#)/i.test(u) ? escapeHtml(u) : null;
}

function inlineMd(escaped: string): string {
  // Operates on already-escaped text — only our own tags are ever emitted.
  return escaped
    .replace(/`([^`]+)`/g, (_m, c) => `<code>${c}</code>`)
    .replace(/\*\*([^*]+)\*\*/g, (_m, c) => `<strong>${c}</strong>`)
    .replace(/(^|[^*])\*([^*]+)\*/g, (_m, pre, c) => `${pre}<em>${c}</em>`)
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, (m, text, url) => {
      const href = safeHref(url);
      return href ? `<a href="${href}" rel="noopener" target="_blank">${text}</a>` : m;
    });
}

function renderMarkdown(src: string): string {
  const lines = src.replace(/\r\n/g, "\n").split("\n");
  const out: string[] = [];
  let inFence = false;
  let fenceBuf: string[] = [];
  let listBuf: string[] = [];
  let paraBuf: string[] = [];

  const flushList = () => {
    if (listBuf.length) {
      out.push(`<ul>${listBuf.map((li) => `<li>${inlineMd(escapeHtml(li))}</li>`).join("")}</ul>`);
      listBuf = [];
    }
  };
  const flushPara = () => {
    if (paraBuf.length) {
      out.push(`<p>${inlineMd(escapeHtml(paraBuf.join(" ")))}</p>`);
      paraBuf = [];
    }
  };

  for (const line of lines) {
    if (line.trim().startsWith("```")) {
      if (inFence) {
        out.push(`<pre><code>${escapeHtml(fenceBuf.join("\n"))}</code></pre>`);
        fenceBuf = [];
        inFence = false;
      } else {
        flushPara();
        flushList();
        inFence = true;
      }
      continue;
    }
    if (inFence) {
      fenceBuf.push(line);
      continue;
    }
    const heading = /^(#{1,4})\s+(.*)$/.exec(line);
    if (heading) {
      flushPara();
      flushList();
      const level = heading[1].length;
      out.push(`<h${level}>${inlineMd(escapeHtml(heading[2]))}</h${level}>`);
      continue;
    }
    const li = /^[-*]\s+(.*)$/.exec(line);
    if (li) {
      flushPara();
      listBuf.push(li[1]);
      continue;
    }
    if (line.trim() === "") {
      flushPara();
      flushList();
      continue;
    }
    flushList();
    paraBuf.push(line.trim());
  }
  if (inFence) {
    out.push(`<pre><code>${escapeHtml(fenceBuf.join("\n"))}</code></pre>`);
  }
  flushPara();
  flushList();
  return out.join("\n");
}

interface Blog {
  id: number;
  blog_key: string;
  name: string;
  rebuild_repo?: string | null;
  rebuild_workflow?: string | null;
}

interface PostMeta {
  slug: string;
  lang: string;
  title: string;
  draft: number | boolean;
  machine_translated?: number | boolean;
  author_name?: string | null;
  published_at: string | null;
}

interface Author {
  id: number;
  user_id?: number | null;
  name: string;
  bio?: string | null;
  avatar_url?: string | null;
}

interface PanelUser {
  id: number;
  email: string;
  name?: string | null;
  isAdmin?: boolean;
  isBlogAuthor?: boolean;
}

interface PostDraft {
  slug: string;
  lang: string;
  category: string;
  title: string;
  excerpt: string;
  meta_description: string;
  tags: string;
  cover_hint: string;
  body: string;
  author_id: number;
  draft: boolean;
}

const api = (path: string, init?: RequestInit) => fetch(path, { credentials: "include", ...init });

const EMPTY_POST: PostDraft = {
  slug: "",
  lang: "de",
  category: "allgemein",
  title: "",
  excerpt: "",
  meta_description: "",
  tags: "",
  cover_hint: "",
  body: "",
  author_id: 0,
  draft: true,
};

/**
 * Blog-CMS managed-blogs list + add-blog form + a selected blog's post list
 * (CP1) and the per-post markdown editor (CP2) — create/edit a post (slug + lang,
 * title/category/excerpt/cover + markdown body), toggle draft/publish, and delete.
 * A save-triggered static-blog rebuild lands in a later checkpoint.
 */
export default function BlogsList() {
  const [blogs, setBlogs] = useState<Blog[] | null>(null);
  const [key, setKey] = useState("");
  const [name, setName] = useState("");
  const [selected, setSelected] = useState<Blog | null>(null);

  const loadBlogs = () =>
    api("/blogs")
      .then((r) => (r.ok ? r.json() : { blogs: [] }))
      .then((d) => setBlogs(d.blogs ?? []))
      .catch(() => setBlogs([]));

  useEffect(() => {
    loadBlogs();
  }, []);

  const create = async () => {
    if (!/^[a-z0-9-]{2,64}$/.test(key) || name.trim() === "") return;
    const res = await api("/blogs", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ blog_key: key, name }),
    });
    if (res.ok) {
      setKey("");
      setName("");
      loadBlogs();
    }
  };

  if (selected) {
    return <BlogPosts blog={selected} onBack={() => setSelected(null)} />;
  }

  return (
    <div className="blog-list">
      <form
        className="blog-list__form"
        onSubmit={(e) => {
          e.preventDefault();
          create();
        }}
      >
        <input value={key} onChange={(e) => setKey(e.target.value)} placeholder="blog-key (kebab)" required />
        <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Name" required />
        <button type="submit">Blog hinzufügen</button>
      </form>

      {blogs === null ? (
        <p>Wird geladen …</p>
      ) : blogs.length === 0 ? (
        <p>Noch keine Blogs angelegt.</p>
      ) : (
        <ul className="blog-list__list">
          {blogs.map((b) => (
            <li key={b.id}>
              <button type="button" onClick={() => setSelected(b)}>
                <strong>{b.name}</strong> <code>{b.blog_key}</code>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function BlogPosts({ blog, onBack }: { blog: Blog; onBack: () => void }) {
  const [posts, setPosts] = useState<PostMeta[] | null>(null);
  const [editing, setEditing] = useState<PostDraft | null>(null);
  /** True when the editor targets an existing (blog, slug, lang) — locks slug/lang. */
  const [isExisting, setIsExisting] = useState(false);
  const [rebuildRepo, setRebuildRepo] = useState(blog.rebuild_repo ?? "");
  const [rebuildWorkflow, setRebuildWorkflow] = useState(blog.rebuild_workflow ?? "dev.yml");
  const [rebuildStatus, setRebuildStatus] = useState<string | null>(null);
  const [backfillStatus, setBackfillStatus] = useState<string | null>(null);
  const [authors, setAuthors] = useState<Author[]>([]);

  const loadAuthors = () =>
    api("/blog/authors")
      .then((r) => (r.ok ? r.json() : { authors: [] }))
      .then((d) => setAuthors(d.authors ?? []))
      .catch(() => setAuthors([]));

  const backfill = async () => {
    setBackfillStatus("Übersetzungen werden erzeugt …");
    const res = await api(`/blogs/${blog.blog_key}/translations/backfill`, { method: "POST" });
    if (res.ok) {
      const d = await res.json().catch(() => ({}));
      setBackfillStatus(`Fertig: ${d.created ?? 0} erstellt, ${d.skipped ?? 0} übersprungen.`);
      loadPosts();
    } else if (res.status === 503) {
      setBackfillStatus("Automatische Übersetzung ist nicht konfiguriert (BLOG_DEEPL_API_KEY).");
    } else {
      setBackfillStatus(`Fehler (HTTP ${res.status}).`);
    }
  };

  const saveRebuildConfig = async () => {
    const res = await api(`/blogs/${blog.blog_key}/rebuild-config`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ rebuild_repo: rebuildRepo.trim(), rebuild_workflow: rebuildWorkflow.trim() }),
    });
    setRebuildStatus(res.ok ? "Rebuild-Konfiguration gespeichert." : `Fehler (HTTP ${res.status}).`);
  };

  const rebuildNow = async () => {
    setRebuildStatus("Rebuild wird ausgelöst …");
    const res = await api(`/blogs/${blog.blog_key}/rebuild`, { method: "POST" });
    if (res.ok) {
      setRebuildStatus("Rebuild ausgelöst.");
    } else if (res.status === 503) {
      setRebuildStatus("Kein Rebuild-Token konfiguriert (BLOG_REBUILD_TOKEN).");
    } else if (res.status === 422) {
      setRebuildStatus("Für diesen Blog ist kein Repository hinterlegt.");
    } else {
      setRebuildStatus(`Fehler (HTTP ${res.status}).`);
    }
  };

  const loadPosts = () =>
    api(`/blogs/${blog.blog_key}/posts`)
      .then((r) => (r.ok ? r.json() : { posts: [] }))
      .then((d) => setPosts(d.posts ?? []))
      .catch(() => setPosts([]));

  useEffect(() => {
    loadPosts();
    loadAuthors();
  }, []);

  const openPost = async (p: PostMeta) => {
    const res = await api(`/blogs/${blog.blog_key}/posts/${p.slug}?lang=${p.lang}`);
    const d = res.ok ? await res.json() : {};
    setIsExisting(true);
    setEditing({
      slug: p.slug,
      lang: p.lang,
      category: (d.category as string) ?? "allgemein",
      title: (d.title as string) ?? p.title,
      excerpt: (d.excerpt as string) ?? "",
      meta_description: (d.meta_description as string) ?? "",
      tags: (d.tags as string) ?? "",
      cover_hint: (d.cover_hint as string) ?? "",
      body: (d.body as string) ?? "",
      author_id: (d.author_id as number) ?? 0,
      draft: Boolean(d.draft ?? p.draft),
    });
  };

  const newPost = () => {
    setIsExisting(false);
    setEditing({ ...EMPTY_POST });
  };

  if (editing) {
    return (
      <PostEditor
        blogKey={blog.blog_key}
        post={editing}
        isExisting={isExisting}
        authors={authors}
        onDone={() => {
          setEditing(null);
          loadPosts();
        }}
        onCancel={() => setEditing(null)}
      />
    );
  }

  return (
    <div className="blog-posts">
      <button type="button" onClick={onBack}>← Blogs</button>
      <div className="blog-posts__head">
        <h2>{blog.name}</h2>
        <button type="button" onClick={newPost}>Neuer Beitrag</button>
      </div>
      {posts === null ? (
        <p>Wird geladen …</p>
      ) : posts.length === 0 ? (
        <p>Noch keine Beiträge.</p>
      ) : (
        <ul className="blog-posts__list">
          {posts.map((p) => (
            <li key={`${p.slug}-${p.lang}`}>
              <button type="button" onClick={() => openPost(p)}>
                <strong>{p.title}</strong> <code>{p.slug}</code>
                <span className="chip chip--neutral">{p.lang}</span>
                <span className={`chip chip--${p.draft ? "warning" : "success"}`}>
                  {p.draft ? "Entwurf" : "Veröffentlicht"}
                </span>
                {p.machine_translated ? (
                  <span className="chip chip--info" title="Automatisch übersetzt">Auto-Übersetzung</span>
                ) : null}
                {p.author_name ? <span className="text-xs opacity-60"> · {p.author_name}</span> : null}
              </button>
            </li>
          ))}
        </ul>
      )}

      <AuthorManager authors={authors} onChange={loadAuthors} />

      <div className="blog-translate">
        <h3>Automatische Übersetzung</h3>
        <p className="blog-translate__hint">
          Beim Speichern eines veröffentlichten Beitrags wird die Gegensprache per DeepL
          erzeugt (Schlüssel serverseitig via <code>BLOG_DEEPL_API_KEY</code>). Vorhandene
          Beiträge lassen sich hier nachziehen.
        </p>
        {backfillStatus ? <p className="status-pill status-pill--info">{backfillStatus}</p> : null}
        <button type="button" onClick={backfill}>Übersetzungen nachziehen</button>
      </div>

      <div className="blog-rebuild">
        <h3>Rebuild-Konfiguration</h3>
        <p className="blog-rebuild__hint">
          Repository (<code>owner/name</code>) und Workflow-Datei, die ein veröffentlichter
          Beitrag neu baut. Der Token wird serverseitig über <code>BLOG_REBUILD_TOKEN</code> bereitgestellt.
        </p>
        <div className="flex gap-2">
          <input
            value={rebuildRepo}
            onChange={(e) => setRebuildRepo(e.target.value)}
            placeholder="Tracht-Digital-Solutions/tds-blog"
          />
          <input
            value={rebuildWorkflow}
            onChange={(e) => setRebuildWorkflow(e.target.value)}
            placeholder="dev.yml"
          />
        </div>
        {rebuildStatus ? <p className="status-pill status-pill--info">{rebuildStatus}</p> : null}
        <div className="flex gap-2">
          <button type="button" onClick={saveRebuildConfig}>Konfiguration speichern</button>
          <button type="button" onClick={rebuildNow}>Jetzt neu bauen</button>
        </div>
      </div>
    </div>
  );
}

function PostEditor({
  blogKey,
  post,
  isExisting,
  authors,
  onDone,
  onCancel,
}: {
  blogKey: string;
  post: PostDraft;
  isExisting: boolean;
  authors: Author[];
  onDone: () => void;
  onCancel: () => void;
}) {
  const [form, setForm] = useState<PostDraft>(post);
  const [status, setStatus] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [preview, setPreview] = useState(false);

  const set = <K extends keyof PostDraft>(field: K, value: PostDraft[K]) =>
    setForm((f) => ({ ...f, [field]: value }));

  const save = async () => {
    if (!/^[a-z0-9-]{2,64}$/.test(form.slug)) {
      setStatus("Slug muss kebab-case sein (a-z, 0-9, -).");
      return;
    }
    if (form.title.trim() === "" || form.body.trim() === "") {
      setStatus("Titel und Inhalt sind erforderlich.");
      return;
    }
    setBusy(true);
    setStatus(null);
    const res = await api(`/blogs/${blogKey}/posts/${form.slug}`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        lang: form.lang,
        category: form.category.trim() || "allgemein",
        title: form.title.trim(),
        excerpt: form.excerpt.trim(),
        meta_description: form.meta_description.trim(),
        tags: form.tags.trim(),
        cover_hint: form.cover_hint.trim(),
        body: form.body,
        author_id: form.author_id,
        draft: form.draft,
      }),
    });
    setBusy(false);
    if (res.ok) {
      onDone();
    } else {
      setStatus(`Fehler beim Speichern (HTTP ${res.status}).`);
    }
  };

  const remove = async () => {
    setBusy(true);
    const res = await api(`/blogs/${blogKey}/posts/${form.slug}?lang=${form.lang}`, { method: "DELETE" });
    setBusy(false);
    if (res.ok) {
      onDone();
    } else {
      setStatus(`Fehler beim Löschen (HTTP ${res.status}).`);
    }
  };

  return (
    <div className="blog-editor">
      <button type="button" onClick={onCancel}>← Beiträge</button>
      <h2>{isExisting ? "Beitrag bearbeiten" : "Neuer Beitrag"}</h2>

      <div className="blog-editor__meta">
        <label>
          Slug
          <input
            value={form.slug}
            onChange={(e) => set("slug", e.target.value)}
            placeholder="mein-beitrag"
            disabled={isExisting}
          />
        </label>
        <label>
          Sprache
          <select value={form.lang} onChange={(e) => set("lang", e.target.value)} disabled={isExisting}>
            <option value="de">de</option>
            <option value="en">en</option>
          </select>
        </label>
        <label>
          Kategorie
          <input value={form.category} onChange={(e) => set("category", e.target.value)} placeholder="allgemein" />
        </label>
        <label>
          Autor
          <select value={String(form.author_id)} onChange={(e) => set("author_id", Number(e.target.value))}>
            <option value="0">— kein Autor —</option>
            {authors.map((a) => (
              <option key={a.id} value={a.id}>{a.name}</option>
            ))}
          </select>
        </label>
      </div>

      <label className="blog-editor__field">
        Titel
        <input value={form.title} onChange={(e) => set("title", e.target.value)} placeholder="Titel des Beitrags" />
      </label>

      <label className="blog-editor__field">
        Auszug
        <input value={form.excerpt} onChange={(e) => set("excerpt", e.target.value)} placeholder="Kurzbeschreibung (optional)" />
      </label>

      <label className="blog-editor__field">
        Cover-Hinweis
        <input value={form.cover_hint} onChange={(e) => set("cover_hint", e.target.value)} placeholder="Bild-Hinweis (optional)" />
      </label>

      <label className="blog-editor__field">
        Meta-Description (SEO)
        <input
          value={form.meta_description}
          onChange={(e) => set("meta_description", e.target.value)}
          maxLength={300}
          placeholder="Suchmaschinen-Beschreibung (≤160 Zeichen ideal)"
        />
      </label>

      <label className="blog-editor__field">
        Tags / Keywords
        <input
          value={form.tags}
          onChange={(e) => set("tags", e.target.value)}
          maxLength={200}
          placeholder="komma, getrennt, keywords"
        />
      </label>

      <div className="blog-editor__field">
        <div className="flex items-center gap-3">
          <span>Inhalt (Markdown)</span>
          <button type="button" className="text-xs ml-auto" onClick={() => setPreview((v) => !v)}>
            {preview ? "Bearbeiten" : "Vorschau"}
          </button>
        </div>
        {preview ? (
          <div
            className="blog-editor__preview prose"
            dangerouslySetInnerHTML={{ __html: renderMarkdown(form.body) }}
          />
        ) : (
          <textarea
            className="blog-editor__body"
            value={form.body}
            onChange={(e) => set("body", e.target.value)}
            rows={18}
            spellCheck={false}
            placeholder="# Überschrift&#10;&#10;Text in Markdown …"
          />
        )}
      </div>

      <label className="blog-editor__publish">
        <input type="checkbox" checked={!form.draft} onChange={(e) => set("draft", !e.target.checked)} />
        Veröffentlichen (sonst Entwurf)
      </label>

      {status ? <p className="status-pill status-pill--info">{status}</p> : null}

      <div className="blog-editor__actions">
        <button type="button" onClick={save} disabled={busy}>Speichern</button>
        {isExisting ? (
          <button type="button" className="danger" onClick={remove} disabled={busy}>Löschen</button>
        ) : null}
      </div>
    </div>
  );
}

/** Manage the byline registry: list authors, add one, remove one. */
function AuthorManager({ authors, onChange }: { authors: Author[]; onChange: () => void }) {
  const [name, setName] = useState("");
  const [bio, setBio] = useState("");
  const [avatar, setAvatar] = useState("");
  const [status, setStatus] = useState<string | null>(null);
  const [panelUsers, setPanelUsers] = useState<PanelUser[]>([]);
  const [pickedUser, setPickedUser] = useState("");

  // Panel users eligible to be a byline: blog authors (admins are implicit).
  useEffect(() => {
    fetch("/auth/admin/users", { credentials: "include" })
      .then((r) => (r.ok ? r.json() : { users: [] }))
      .then((d: { users?: PanelUser[] }) =>
        setPanelUsers((d.users ?? []).filter((u) => u.isBlogAuthor || u.isAdmin)))
      .catch(() => setPanelUsers([]));
  }, []);

  // user_ids already imported as a snapshot, so we don't offer them twice.
  const linkedUserIds = new Set(authors.map((a) => a.user_id).filter((v): v is number => typeof v === "number"));
  const importable = panelUsers.filter((u) => !linkedUserIds.has(u.id));

  const post = async (payload: Record<string, unknown>, reset?: () => void) => {
    const res = await api("/blog/authors", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    if (res.ok) {
      reset?.();
      setStatus(null);
      onChange();
    } else {
      setStatus(`Fehler (HTTP ${res.status}).`);
    }
  };

  const add = () => {
    if (name.trim().length < 2) {
      setStatus("Name ist erforderlich.");
      return;
    }
    void post({ name: name.trim(), bio: bio.trim(), avatar_url: avatar.trim() }, () => {
      setName("");
      setBio("");
      setAvatar("");
    });
  };

  const importUser = () => {
    const u = panelUsers.find((x) => String(x.id) === pickedUser);
    if (!u) return;
    void post({ user_id: u.id, name: (u.name ?? u.email).trim() }, () => setPickedUser(""));
  };

  const remove = async (a: Author) => {
    if (!window.confirm(`Autor „${a.name}“ entfernen? Beiträge behalten die Byline nicht.`)) return;
    const res = await api(`/blog/authors/${a.id}`, { method: "DELETE" });
    if (res.ok) onChange();
  };

  return (
    <div className="blog-authors">
      <h3>Autoren</h3>
      {authors.length === 0 ? (
        <p className="text-xs opacity-60">Noch keine Autoren.</p>
      ) : (
        <ul className="blog-authors__list">
          {authors.map((a) => (
            <li key={a.id} className="flex items-center gap-2">
              <strong>{a.name}</strong>
              {a.user_id ? <span className="chip chip--violet">Panel-Nutzer</span> : null}
              {a.bio ? <span className="text-xs opacity-60">{a.bio}</span> : null}
              <button type="button" className="danger text-xs ml-auto" onClick={() => remove(a)}>Entfernen</button>
            </li>
          ))}
        </ul>
      )}

      {importable.length > 0 ? (
        <div className="flex flex-wrap items-center gap-2 mt-3">
          <span className="text-sm">Aus Panel-Nutzer:</span>
          <select value={pickedUser} onChange={(e) => setPickedUser(e.target.value)}>
            <option value="">— Nutzer wählen —</option>
            {importable.map((u) => (
              <option key={u.id} value={u.id}>{u.name ?? u.email}</option>
            ))}
          </select>
          <button type="button" onClick={importUser} disabled={pickedUser === ""}>Als Autor übernehmen</button>
        </div>
      ) : null}

      <div className="flex flex-wrap gap-2 mt-2">
        <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Name (Gast-Autor)" />
        <input value={bio} onChange={(e) => setBio(e.target.value)} placeholder="Kurzbio (optional)" />
        <input value={avatar} onChange={(e) => setAvatar(e.target.value)} placeholder="Avatar-URL (optional)" />
        <button type="button" onClick={add}>Autor hinzufügen</button>
      </div>
      {status ? <p className="status-pill status-pill--info">{status}</p> : null}
    </div>
  );
}
