import { useEffect, useState } from "react";

interface Blog {
  id: number;
  blog_key: string;
  name: string;
}

interface PostMeta {
  slug: string;
  lang: string;
  title: string;
  draft: number | boolean;
  published_at: string | null;
}

interface PostDraft {
  slug: string;
  lang: string;
  category: string;
  title: string;
  excerpt: string;
  cover_hint: string;
  body: string;
  draft: boolean;
}

const api = (path: string, init?: RequestInit) => fetch(path, { credentials: "include", ...init });

const EMPTY_POST: PostDraft = {
  slug: "",
  lang: "de",
  category: "allgemein",
  title: "",
  excerpt: "",
  cover_hint: "",
  body: "",
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

  const loadPosts = () =>
    api(`/blogs/${blog.blog_key}/posts`)
      .then((r) => (r.ok ? r.json() : { posts: [] }))
      .then((d) => setPosts(d.posts ?? []))
      .catch(() => setPosts([]));

  useEffect(() => {
    loadPosts();
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
      cover_hint: (d.cover_hint as string) ?? "",
      body: (d.body as string) ?? "",
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
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function PostEditor({
  blogKey,
  post,
  isExisting,
  onDone,
  onCancel,
}: {
  blogKey: string;
  post: PostDraft;
  isExisting: boolean;
  onDone: () => void;
  onCancel: () => void;
}) {
  const [form, setForm] = useState<PostDraft>(post);
  const [status, setStatus] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

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
        cover_hint: form.cover_hint.trim(),
        body: form.body,
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
        Inhalt (Markdown)
        <textarea
          className="blog-editor__body"
          value={form.body}
          onChange={(e) => set("body", e.target.value)}
          rows={18}
          spellCheck={false}
          placeholder="# Überschrift&#10;&#10;Text in Markdown …"
        />
      </label>

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
