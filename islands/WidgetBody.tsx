import { useEffect, useState } from "react";

/** "Blog-Beiträge" widget body — the total post count, from /blog/summary. */
export default function PostsCount() {
  const [posts, setPosts] = useState<number | null>(null);
  useEffect(() => {
    let alive = true;
    fetch("/blog/summary", { credentials: "include" })
      .then((r) => (r.ok ? r.json() : { posts: 0 }))
      .then((d) => alive && setPosts(Number(d.posts ?? 0)))
      .catch(() => alive && setPosts(0));
    return () => {
      alive = false;
    };
  }, []);
  return <p className="widget__metric">{posts === null ? "…" : posts}</p>;
}
