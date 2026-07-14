import { defineExtension } from "@tracht-digital-solutions/tds-panel-contract";

/**
 * Blog-CMS extension — multi-blog post management, ported from tds-content-api's
 * blog_post model. Edits blog posts (title/excerpt/body/draft/publish per slug ×
 * language); the static blogs fetch published posts at build time. "1:n blogs":
 * a `blog` registry lets one panel manage several blogs; posts are scoped to a
 * blog. `blog:read`/`blog:write` gate it (admins bypass).
 */
export default defineExtension({
  id: "blog-cms",
  name: "Blog-CMS",
  version: "0.1.0",
  permissions: [
    { id: "blog:read", label: "Blog-Beiträge ansehen", group: "blog-cms" },
    { id: "blog:write", label: "Blog-Beiträge bearbeiten", group: "blog-cms" },
  ],
  nav: [
    {
      id: "blog-cms",
      label: "Blog-CMS",
      href: "/blog",
      icon: "book-open",
      group: "content",
      order: 10,
      permission: "blog:read",
    },
  ],
  widgets: [
    {
      id: "blog-cms-posts",
      title: "Blog-Beiträge",
      island: "@tracht-digital-solutions/tds-ext-blog-cms/widgets/Widget.astro",
      size: "sm",
      permission: "blog:read",
      dataEndpoint: "/blog/summary",
      order: 40,
    },
  ],
  settings: [
    {
      id: "blog-cms",
      label: "Blog-CMS",
      island: "@tracht-digital-solutions/tds-ext-blog-cms/islands/Settings.astro",
      order: 50,
    },
  ],
  routes: [
    {
      pattern: "/blog",
      entrypoint: "@tracht-digital-solutions/tds-ext-blog-cms/pages/Index.astro",
      permission: "blog:read",
    },
  ],
  i18n: {
    de: { "blog.title": "Blog-CMS", "blog.posts": "Beiträge" },
    en: { "blog.title": "Blog CMS", "blog.posts": "Posts" },
  },
});
