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

const api = (path: string, init?: RequestInit) => fetch(path, { credentials: "include", ...init });

/**
 * Blog-CMS managed-blogs list + add-blog form + a selected blog's post list
 * (checkpoint-1). The markdown post editor (create/edit + publish/draft) lands
 * in the next frontend checkpoint.
 */
export default function BlogsList() {
  const [blogs, setBlogs] = useState<Blog[] | null>(null);
  const [key, setKey] = useState("");
  const [name, setName] = useState("");
  const [selected, setSelected] = useState<Blog | null>(null);
  const [posts, setPosts] = useState<PostMeta[] | null>(null);

  const loadBlogs = () =>
    api("/blogs")
      .then((r) => (r.ok ? r.json() : { blogs: [] }))
      .then((d) => setBlogs(d.blogs ?? []))
      .catch(() => setBlogs([]));

  useEffect(() => {
    loadBlogs();
  }, []);

  const openBlog = (b: Blog) => {
    setSelected(b);
    setPosts(null);
    api(`/blogs/${b.blog_key}/posts`)
      .then((r) => (r.ok ? r.json() : { posts: [] }))
      .then((d) => setPosts(d.posts ?? []))
      .catch(() => setPosts([]));
  };

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
    return (
      <div className="blog-posts">
        <button type="button" onClick={() => setSelected(null)}>← Blogs</button>
        <h2>{selected.name}</h2>
        {posts === null ? (
          <p>Wird geladen …</p>
        ) : posts.length === 0 ? (
          <p>Noch keine Beiträge.</p>
        ) : (
          <ul className="blog-posts__list">
            {posts.map((p) => (
              <li key={`${p.slug}-${p.lang}`}>
                <strong>{p.title}</strong> <code>{p.slug}</code>
                <span className={`chip chip--${p.draft ? "warning" : "success"}`}>
                  {p.draft ? "Entwurf" : "Veröffentlicht"}
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    );
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
              <button type="button" onClick={() => openBlog(b)}>
                <strong>{b.name}</strong> <code>{b.blog_key}</code>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
