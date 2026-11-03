# Blogs MD for Winter CMS

Publish [Winter.Blog](https://github.com/wintercms/wn-blog-plugin) posts and categories from
Markdown files in a Git repository.

- Posts and categories are written as Markdown files in a Git repository, not in the CMS.
- Authors come from Git history: the commit that first added a post.
- Winter.Blog keeps doing everything else: Markdown rendering, HTML cleaning, the `blogPost`,
  `blogPosts`, `blogCategories` and `blogRssFeed` components, and the backend lists.
- Winter.Blog's backend editors become read-only, so the repository is the only source of truth.
- The site updates from a GitHub push webhook, with a scheduled sync every 15 minutes as a
  fallback.

## Requirements

- Winter CMS 1.2 and PHP 8.1 or newer.
- The Winter.Blog plugin.
- A `git` binary on the web server (git 2.31 or newer when an access token is used).
- Outbound HTTPS from the server to the Git host.
- Optional: the Winter.Redirect plugin, to redirect moved posts.

## Installation

```sh
composer require josephcrowell/wn-blogs-md-plugin
php artisan winter:up
```

Composer installs the plugin to `plugins/josephcrowell/blogsmd`. Keep that directory name: Winter
derives the plugin's PHP namespace (`JosephCrowell\BlogsMd`) from it.

## Configuration

Configure the plugin in the backend under **Settings → Blogs MD** (permission "Manage the blog
repository settings"). The token and webhook secret are masked fields.

A setting left empty there falls back to the plugin's `config/config.php`, where some keys read
`.env`. A server can therefore be set up entirely through `.env` or a config override, and the
backend page takes precedence once a value is saved:

| Key | `.env` variable | Default | Meaning |
| --- | --- | --- | --- |
| `repository` | `BLOGSMD_REPOSITORY` | (empty) | HTTPS URL of the blog repository, e.g. `https://github.com/Sonic-DE/sonic-blogs`. A local path also works. Syncing refuses to run while it's empty |
| `branch` | `BLOGSMD_BRANCH` | `master` | The branch that is published |
| `posts_path` | | `posts` | Folder holding posts. Subfolders become part of the post URL |
| `categories_path` | | `categories` | Folder holding category files |
| `author_field` | | `author` | Which identity of the first commit is credited: `author` or `committer` |
| `on_removed` | | `delete` | What happens to a post whose file is removed: `delete` or `unpublish` |
| `redirect_on_move` | | `true` | Create a 301 redirect when a post's URL changes (needs Winter.Redirect) |
| `post_url_prefix` | | `/blog` | URL path of your blog post page, without the slug. Used for redirects |
| `webhook_secret` | `BLOGSMD_WEBHOOK_SECRET` | (empty) | Secret shared with the GitHub webhook. The webhook refuses every request while it's empty |
| `token` | `BLOGSMD_TOKEN` | (empty) | Access token for a private repository |

To change a fallback without editing the plugin, create `config/josephcrowell/blogsmd/config.php`
in your application and return only the keys you want to override:

```php
<?php

return [
    'on_removed' => 'unpublish',
    'post_url_prefix' => '/news',
];
```

**Private repositories.** Enter a token with read access to the repository (for
GitHub, a fine-grained token with "Contents: read"). The token is passed to git only as an HTTP
header through the process environment. It never appears in the remote URL, the command line or
the logs.

**Keep Winter.Blog's rich editor off.** Posts are Markdown. If Winter.Blog's `use_rich_editor`
setting were on, post content would be treated as HTML, so a sync refuses to run while it's on.
Turn it off under Settings → Blog. The plugin hides that switch from Winter.Blog's settings form
once it's off.

## Blog repository layout

```text
categories/
  news.md
  release-notes.md
posts/
  2026/
    2026-11-02-first-post.md
    images/
      screenshot.png
```

- Every `.md` file below `posts/`, at any depth, is a post.
- Every `.md` file directly in `categories/` is a category.
- Images can live anywhere in the repository. Posts refer to them with relative paths.

## Writing a post

A post file has three parts: INI settings, a line containing only `;;`, then the Markdown body.
The settings are only read as data; nothing in them is executed. Only the first `;;` line splits
the file, so Markdown headings underlined with `===` or `---` work normally in the body.

```ini
title = "First post"
date = "2026-11-02 09:00"
excerpt = "A short summary for lists and feeds."
published = 1
categories[] = "release-notes"
categories[] = "news"
;;
Sonic-DE 6.8 is out
===================

Here's what changed.

![The new panel](images/screenshot.png)

<!-- more -->

The rest of the post.
```

| Setting | Required | Default | Meaning |
| --- | --- | --- | --- |
| `title` | Yes | | Post title |
| `slug` | No | File name without `.md` and without a leading `YYYY-MM-DD-` | Replaces the file part of the URL. Folders are always kept |
| `date` | No | The date in the file name (00:00 UTC), else the first commit's author date | Publication date and time, in UTC |
| `excerpt` | No | None | Summary text. Without it, Winter.Blog uses the text before `<!-- more -->`, or the first 600 characters |
| `published` | No | `1` | `0` makes the post a draft |
| `categories[]` | No | None | Category slugs, one line each |
| `teaser` | No | None | Relative path to the post's teaser image, e.g. `images/teaser.png` |

There is no author setting: the author always comes from Git (see [Authorship](#authorship)).

Unknown settings are ignored with a warning in the log.

**Images.** Use relative paths from the post's file (`images/screenshot.png`,
`../shared/logo.svg`). On sync, each referenced image is stored with Winter's file attachments
as a public image of the post, and its URL in the post is rewritten to the stored file. Changing
only an image updates the post. Images that no longer appear in a post are deleted. Only
Markdown image syntax (`![alt](path)`) is handled; a raw HTML `<img>` keeps its URL as written.

**Teaser image.** `teaser = "images/teaser.png"` (relative to the post's file) is stored as the
post's Winter.Blog featured image (`featured_images`), not in the post content. It follows the same
rules as other images: kept while unchanged, replaced when the file changes, deleted when the setting
is removed. A missing file, a file that isn't an image, or a URL instead of a relative path logs a
warning and the post has no teaser. Themes read it as `post.featured_images|first`; see
[Theme setup](#theme-setup).
Missing files and files that aren't images keep their original URL and log a warning.

**Drafts and scheduled posts.** `published = 0` keeps a post as a draft. Drafts are visible only
to logged-in backend users. A post with a future `date` is published but hidden until that time,
so a future date schedules it.

**Summaries.** Put `<!-- more -->` on its own line. Lists and feeds show the text above it.

## Categories

A category file is `categories/<slug>.md`. The file name is the slug.

```ini
name = "Release Notes"
parent = "news"
;;
Notes for every Sonic-DE release.
```

- `name` is required. `parent` is the slug of another category and is optional. A parent that
  doesn't exist is logged as an error and the category stays at the top level.
- The Markdown body becomes the category description, rendered and cleaned like post content.
  Category descriptions can't contain images, because Winter.Blog categories have no attachments.
- A post may use a category slug that has no file. The category is created automatically with a
  name made from the slug (`how-to_guides` becomes "How To Guides").
- Category slugs must be 3 to 64 characters long (Winter.Blog's rule), using a-z, 0-9, `-` and `_`.
- When a category file is removed, the category is deleted if no post uses it and it has no child
  categories. Otherwise it's kept, under its generated name.

## URLs

A post's URL is the lower-cased folder path below `posts/`, then its slug:

| File | URL |
| --- | --- |
| `posts/hello.md` | `/blog/hello` |
| `posts/2026/2026-11-02-first-post.md` | `/blog/2026/first-post` |
| `posts/News/2026/x.md` with `slug = "launch"` | `/blog/news/2026/launch` |

- Every segment must use only a-z, 0-9, `-` and `_` after lower-casing. A folder or file name
  with spaces is rejected.
- The first segment can't be `page`, `category` or `rss.xml`, because those URLs belong to the
  blog's list, category and feed pages.
- Moving or renaming a file keeps the same post (Git's rename detection), with its author and
  history. If the URL changes and `redirect_on_move` is on, a 301 redirect from the old URL to
  the new one is created through Winter.Redirect.

## Authorship

The author of a post is taken from the commit that first added its file, following renames, and
stored. Later edits by other people don't change it.

- `author_field = author` (the default) credits the commit's author. Use it: squash merges and
  edits made on GitHub's website record the committer as `GitHub <noreply@github.com>`.
- `author_field = committer` credits the committer instead.
- Only real renames are followed: the old file must be removed in the same commit that adds the
  new one. A new file copied from an existing post is a separate post, credited to whoever added
  it.
- The author's GitHub login (for their profile image, `https://github.com/<login>.png`) comes from
  a GitHub no-reply email (`12345+login@users.noreply.github.com`) when the commit has one.
  Otherwise, for repositories on GitHub, it's looked up once per post through GitHub's API ("Get a
  commit"), which matches the commit's email to an account, and stored. The access token is used
  for the request when set; without one, GitHub allows 60 requests an hour, plenty for this. If
  the email isn't linked to a GitHub account, or the request fails (logged as a warning), the
  login stays empty and themes can fall back to initials.
- When a backend user has the same email address as the author, the post is linked to that user.

The author details are stored in the post's `metadata`: `author_name`, `author_email`,
`author_github`, plus `source_path`, `source_sha` and `edit_url` (the file on the Git host).

## Keeping the site in sync

**Command.**

```sh
php artisan blogsmd:sync          # sync when the branch has new commits
php artisan blogsmd:sync --force  # re-sync every file
```

It exits with 1 when the sync failed or any file failed, and 0 otherwise (including "Up to date"
and "already running").

**Schedule.** The plugin schedules `blogsmd:sync` every 15 minutes. Winter's scheduler needs this
cron entry on the server:

```text
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

**GitHub webhook.** For updates as soon as a change is merged:

1. Enter a long random string as the webhook secret in Settings → Blogs MD.
2. In the repository on GitHub, open Settings → Webhooks → Add webhook.
3. Payload URL: `https://<your site>/webhooks/blogsmd/github`.
4. Content type: `application/json`.
5. Secret: the same value.
6. Choose "Just the push event" and save.

GitHub's first delivery is a ping, which should get a 200 response. A push to the configured
branch gets 202 and the sync runs after the response is sent. Requests with a missing or wrong
signature get 401, and every request gets 500 while no secret is configured.

**Queues.** The webhook runs the sync after the response, in the same PHP process, so no queue
worker is needed. Syncs never overlap: a second one returns "already running".

## Theme setup

Create these CMS pages. The URLs matter: a `/blog/:page?` page would capture single-segment post
URLs, so the first page and the numbered pages are separate pages.

`pages/blog.htm`:

```ini
title = "Blog"
url = "/blog"
layout = "default"

[blogPosts]
postsPerPage = 10
categoryPage = "blog-category"
postPage = "blog-post"
==
{% component 'blogPosts' %}
```

`pages/blog-page.htm`: the same, with `url = "/blog/page/:page"` and
`pageNumber = "{{ :page }}"` in `[blogPosts]`.

`pages/blog-post.htm`:

```ini
title = "Blog post"
url = "/blog/:slug*"
layout = "default"

[blogPost]
slug = "{{ :slug }}"
categoryPage = "blog-category"
==
<?php
function onEnd()
{
    if ($post = $this['post']) {
        $this->page->title = $post->title;
    }
}
?>
==
{% component 'blogPost' %}
```

`:slug*` matches slugs containing `/`, so posts in folders work.

`pages/blog-category.htm`:

```ini
title = "Blog category"
url = "/blog/category/:slug/:page?"
layout = "default"

[blogPosts]
categoryFilter = "{{ :slug }}"
pageNumber = "{{ :page }}"
postsPerPage = 10
categoryPage = "blog-category"
postPage = "blog-post"
==
{% component 'blogPosts' %}
```

`pages/blog-rss.htm` (no layout; the component returns the XML itself):

```ini
title = "Blog RSS"
url = "/blog/rss.xml"

[blogRssFeed]
blogPage = "blog"
postPage = "blog-post"
==
{% component 'blogRssFeed' %}
```

Winter.Blog's default `blogPosts` pagination links to the current page with a `page` parameter,
which doesn't exist on `/blog`. Override `partials/blogPosts/default.htm` in your theme and link
page 1 to `'blog'|page` and page n to `'blog-page'|page({ page: n })`.

Author details in Twig:

```twig
{% set meta = post.metadata %}
{{ meta.author_name }}
{% if meta.author_github %}
    <img src="https://github.com/{{ meta.author_github }}.png?size=64" alt="">
{% endif %}
<a href="{{ meta.edit_url }}">View source</a>
```

Teaser image in a post list, as a cropped thumbnail:

```twig
{% set teaser = post.featured_images|first %}
{% if teaser %}
    <img src="{{ teaser.thumb(320, 200, { mode: 'crop' }) }}" width="160" height="100" alt="">
{% endif %}
```

And as the social image on the post page, in its PHP section (`ogImage` is then output by the
layout's `og:image` and `twitter:image` tags, with the site default as fallback):

```php
function onEnd()
{
    if (($post = $this['post']) && ($teaser = $post->featured_images->first())) {
        $this['ogImage'] = $teaser->getThumb(1200, 630, ['mode' => 'crop']);
    }
}
```

## Backend behaviour

- The Blog menu has no "New post". The create, import, update and reorder pages redirect to the
  lists with a warning, and post and category records open in Winter.Blog's read-only preview.
- Save, delete, import and reorder requests are refused with 403.
- The post preview shows a "Managed in Git" notice with a link to the source file and the commit
  it was synced from.
- Lists have no checkboxes, but search, filters, sorting and export still work.
- Syncs aren't affected: they save the models directly.

## Removal behaviour

- `on_removed = delete` (the default): removing a post's file deletes the post and its images.
- `on_removed = unpublish`: the post is kept, unpublished. Restoring the file publishes it again.
- Category files: see [Categories](#categories).

## Troubleshooting

- **Where errors go.** Every failure is logged (search the log for "Blogs MD"). The last sync's
  errors are also stored in the `josephcrowell_blogsmd_sync_state` table (`last_error`).
- **One file fails.** The other files still sync. The run isn't marked as done, so the next run
  retries. Fix the file and push.
- **Common errors:**
  - `no ";;" line separating the settings from the Markdown`: add the `;;` line.
  - `is not a valid URL segment`: rename the folder or file, or set `slug`.
  - `must be 3 to 64 characters long`: use a longer category slug.
  - `rich editor setting is on`: turn off Winter.Blog's rich editor (see Configuration).
  - `git ... failed`: check the repository URL, the token, and that the server can reach the
    Git host. The mirror is kept in `storage/temp/blogsmd/repo.git` and is re-cloned when it's
    missing, broken, or points at a different repository than the one configured.
- **Full re-sync.** `php artisan blogsmd:sync --force`.

## Development

```sh
php artisan winter:test --plugin=JosephCrowell.BlogsMd
vendor/bin/phpcs --standard=phpcs.xml plugins/josephcrowell/blogsmd
```

The tests build temporary Git repositories, so they need `git`. They also need Winter.Redirect
and Winter.Translate installed.

## Licence

GPL-2.0. See [LICENSE](LICENSE).
