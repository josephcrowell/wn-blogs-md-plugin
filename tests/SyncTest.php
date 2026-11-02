<?php

namespace JosephCrowell\BlogsMd\Tests;

use JosephCrowell\BlogsMd\Classes\SyncResult;
use JosephCrowell\BlogsMd\Models\Settings;
use JosephCrowell\BlogsMd\Models\Source;
use JosephCrowell\BlogsMd\Models\SyncState;
use RuntimeException;
use System\Models\File;
use Winter\Blog\Models\Category;
use Winter\Blog\Models\Post;
use Winter\Blog\Models\Settings as BlogSettings;
use Winter\Storm\Support\Facades\Config;
use Winter\Storm\Support\Facades\File as Filesystem;

class SyncTest extends BlogsMdTestCase
{
    //
    // Posts and runs
    //

    public function testCreatesPostWithSlugDatesAndMetadata()
    {
        $this->write('posts/2026/2026-11-02-first-post.md', $this->postFile('First post', "Hello **world**.\n"));
        $sha = $this->commit('Add first post', 'Ada Author', '12345+ada@users.noreply.github.com');

        $result = $this->sync();

        $this->assertSame(SyncResult::STATUS_SYNCED, $result->status);
        $this->assertSame(1, $result->created);
        $post = Post::where('slug', '2026/first-post')->firstOrFail();
        $this->assertSame('First post', $post->title);
        $this->assertTrue((bool) $post->published);
        $this->assertSame('2026-11-02 00:00:00', $post->published_at->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('<strong>world</strong>', $post->content_html);
        $this->assertSame('Ada Author', $post->metadata['author_name']);
        $this->assertSame('ada', $post->metadata['author_github']);
        $this->assertSame('posts/2026/2026-11-02-first-post.md', $post->metadata['source_path']);
        $this->assertSame($sha, $post->metadata['source_sha']);
        $this->assertSame($sha, SyncState::current()->processed_sha);
    }

    public function testSecondRunAtSameShaWritesNothingAndForceResyncs()
    {
        $this->write('posts/a.md', $this->postFile('A'));
        $this->commit();
        $this->sync();
        $updatedAt = Post::first()->updated_at;

        $this->assertSame(SyncResult::STATUS_UP_TO_DATE, $this->sync()->status);
        $this->assertEquals($updatedAt, Post::first()->updated_at);

        $forced = $this->sync(true);
        $this->assertSame(SyncResult::STATUS_SYNCED, $forced->status);
        $this->assertSame(1, $forced->updated);
    }

    public function testOneFailingFileKeepsOldShaAndOthersStillSync()
    {
        $this->write('posts/good.md', $this->postFile('Good'));
        $first = $this->commit();
        $this->sync();

        $this->write('posts/bad.md', "title = \"Bad\"\nno separator here\n");
        $this->write('posts/also-good.md', $this->postFile('Also good'));
        $this->commit();

        $result = $this->sync();

        $this->assertArrayHasKey('posts/bad.md', $result->failed);
        $this->assertNotNull(Post::where('slug', 'also-good')->first());
        $state = SyncState::current();
        $this->assertSame($first, $state->processed_sha);
        $this->assertStringContainsString('posts/bad.md', $state->last_error);
    }

    public function testRichEditorSettingRefusesTheSync()
    {
        $this->write('posts/a.md', $this->postFile('A'));
        $this->commit();
        BlogSettings::set('use_rich_editor', true);

        try {
            $this->sync();
            $this->fail('The sync should have been refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('rich editor', $e->getMessage());
        }

        $this->assertSame(0, Post::count());
    }

    public function testDraftAndExcerpt()
    {
        $this->write('posts/draft.md', $this->postFile('Draft', "Body\n", ['published = 0', 'excerpt = "Short"']));
        $this->commit();
        $this->sync();

        $post = Post::firstOrFail();
        $this->assertFalse((bool) $post->published);
        $this->assertSame('Short', $post->excerpt);
    }

    public function testMarkdownElementsFixtureRenders()
    {
        $this->write('posts/markdown-elements.md', $this->postFile('Markdown elements', $this->elementsBody(), ['published = 0']));
        $this->commit();
        $this->sync();

        $html = Post::firstOrFail()->content_html;
        // Footnotes need Winter.Blog 2.2.1+; the theme's content.css styles their markup.
        $expected = [
            '<h2>', '<pre>', '<blockquote>', '<table>', '<del>', '<kbd>', '<dl>', '<details>', '<figure>',
            'class="footnote-ref"', 'class="footnotes"', 'class="footnote-backref"',
        ];

        foreach ($expected as $tag) {
            $this->assertStringContainsString($tag, $html, $tag . ' should survive rendering and cleaning');
        }
    }

    //
    // Categories
    //

    public function testCategoryFilesWithParentsAndMissingParent()
    {
        $this->write('categories/news.md', "name = \"News\"\n;;\nAll the news.\n");
        $this->write('categories/release-notes.md', "name = \"Release Notes\"\nparent = \"news\"\n;;\nReleases.\n");
        $this->write('categories/orphan.md', "name = \"Orphan\"\nparent = \"nowhere\"\n;;\n");
        $this->commit();

        $result = $this->sync();

        $this->assertEmpty($result->failed);
        $news = Category::where('slug', 'news')->firstOrFail();
        $release = Category::where('slug', 'release-notes')->firstOrFail();
        $this->assertSame('Release Notes', $release->name);
        $this->assertSame($news->id, (int) $release->parent_id);
        $this->assertStringContainsString('<p>Releases.</p>', $release->description);
        $this->assertNull(Category::where('slug', 'orphan')->firstOrFail()->parent_id);
    }

    public function testReferencedOnlyCategoryIsCreated()
    {
        $this->write('posts/a.md', $this->postFile('A', "Body\n", ['categories[] = "how-to_guides"']));
        $this->commit();
        $this->sync();

        $category = Category::where('slug', 'how-to_guides')->firstOrFail();
        $this->assertSame('How To Guides', $category->name);
        $this->assertSame([$category->id], Post::firstOrFail()->categories->pluck('id')->all());
    }

    public function testRemovedCategoryStillInUseIsKeptAndUnusedIsDeleted()
    {
        $this->write('categories/used-one.md', "name = \"Used\"\n;;\n");
        $this->write('categories/unused.md', "name = \"Unused\"\n;;\n");
        $this->write('posts/a.md', $this->postFile('A', "Body\n", ['published = 0', 'categories[] = "used-one"']));
        $this->commit();
        $this->sync();

        $this->remove('categories/used-one.md');
        $this->remove('categories/unused.md');
        $this->commit();
        $this->sync();

        // Used only by a draft: Winter.Blog's posts relation would count 0 here, the pivot doesn't.
        $this->assertSame('Used One', Category::where('slug', 'used-one')->firstOrFail()->name);
        $this->assertNull(Category::where('slug', 'unused')->first());
    }

    //
    // Authorship and moves
    //

    public function testAuthorFieldChoosesAuthorOrCommitter()
    {
        $this->write('posts/a.md', $this->postFile('A'));
        $this->commit('Squash', 'Ada Author', 'ada@example.org', 'GitHub', 'noreply@github.com');
        $this->sync();
        $this->assertSame('Ada Author', Post::firstOrFail()->metadata['author_name']);

        Post::query()->delete();
        Source::query()->delete();
        SyncState::query()->delete();
        Config::set('josephcrowell.blogsmd::author_field', 'committer');
        $this->sync();
        $this->assertSame('GitHub', Post::firstOrFail()->metadata['author_name']);
    }

    public function testGithubLoginAndBackendUserLinkComeFromTheCommitEmail()
    {
        $this->write('posts/a.md', $this->postFile('A'));
        $this->commit('Add', 'Guest Author', '12345+guest@users.noreply.github.com');
        $this->sync();

        $post = Post::firstOrFail();
        $this->assertSame('Guest Author', $post->metadata['author_name']);
        $this->assertSame('guest', $post->metadata['author_github']);
        $this->assertNull($post->user_id);

        $user = \Backend\Models\User::first();
        $this->write('posts/b.md', $this->postFile('B'));
        $this->commit('Add', 'Admin', $user->email);
        $this->sync();

        $this->assertSame($user->id, (int) Post::where('slug', 'b')->firstOrFail()->user_id);
    }

    public function testSimilarNewPostIsCreditedToItsOwnAuthor()
    {
        $this->write('posts/a.md', $this->postFile('A', "The same long body text in both posts.\n"));
        $this->commit('Add a', 'Ada Author', 'ada@example.org');
        $this->write('posts/b.md', $this->postFile('B', "The same long body text in both posts.\n"));
        $this->commit('Add b', 'Bob Contributor', 'bob@example.org');
        $this->sync();

        $this->assertSame('Ada Author', Post::where('slug', 'a')->firstOrFail()->metadata['author_name']);
        $this->assertSame('Bob Contributor', Post::where('slug', 'b')->firstOrFail()->metadata['author_name']);
    }

    public function testRenameBeforeFirstSyncKeepsTheOriginalAuthor()
    {
        $this->write('posts/a.md', $this->postFile('A'));
        $this->commit('Add', 'Ada Author', 'ada@example.org');
        $this->move('posts/a.md', 'posts/2026/a.md');
        $this->commit('Move', 'Bob Maintainer', 'bob@example.org');
        $this->move('posts/2026/a.md', 'posts/2026/renamed.md');
        $this->commit('Rename', 'Bob Maintainer', 'bob@example.org');
        $this->sync();

        $this->assertSame('Ada Author', Post::where('slug', '2026/renamed')->firstOrFail()->metadata['author_name']);
    }

    public function testRenameWithinFolderKeepsRowAndAuthor()
    {
        $this->write('posts/2026/old-name.md', $this->postFile('A', "Body\n", ['slug = "kept"']));
        $this->commit('Add', 'Original Author', 'original@example.org');
        $this->sync();
        $id = Post::firstOrFail()->id;

        $this->move('posts/2026/old-name.md', 'posts/2026/new-name.md');
        $this->commit('Rename', 'Someone Else', 'else@example.org');
        $this->sync();

        $post = Post::firstOrFail();
        $this->assertSame($id, $post->id);
        $this->assertSame('2026/kept', $post->slug);
        $this->assertSame('Original Author', $post->metadata['author_name']);
        $this->assertSame('posts/2026/new-name.md', Source::firstOrFail()->source_path);
    }

    public function testRemovedFileDeletesThePostByDefault()
    {
        $this->write('posts/a.md', $this->postFile('A'));
        $this->commit();
        $this->sync();

        $this->remove('posts/a.md');
        $this->commit();
        $result = $this->sync();

        $this->assertSame(1, $result->deleted);
        $this->assertSame(0, Post::count());
        $this->assertSame(0, Source::count());
    }

    public function testRemovedFileUnpublishesWhenConfigured()
    {
        Config::set('josephcrowell.blogsmd::on_removed', 'unpublish');
        $this->write('posts/a.md', $this->postFile('A'));
        $this->commit();
        $this->sync();

        $this->remove('posts/a.md');
        $this->commit();
        $result = $this->sync();

        $this->assertSame(1, $result->unpublished);
        $this->assertFalse((bool) Post::firstOrFail()->published);
        $this->assertNotNull(Source::firstOrFail()->removed_at);
    }

    public function testBackendSettingsControlTheSync()
    {
        // The config says delete and points nowhere useful; the backend settings win.
        Config::set('josephcrowell.blogsmd::repository', '/nonexistent');
        Config::set('josephcrowell.blogsmd::on_removed', 'delete');
        Settings::set(['repository' => $this->repo, 'on_removed' => 'unpublish', 'posts_path' => 'articles']);

        $this->write('articles/a.md', $this->postFile('A'));
        $this->write('posts/ignored.md', $this->postFile('Ignored'));
        $this->commit();
        $this->sync();
        $this->assertSame(['a'], Post::pluck('slug')->all());

        $this->remove('articles/a.md');
        $this->commit();
        $this->assertSame(1, $this->sync()->unpublished);
    }

    //
    // Images
    //

    public function testImageLifecycle()
    {
        $this->write('posts/2026/images/pic.png', $this->png(255, 0, 0));
        $this->write('posts/2026/a.md', $this->postFile('A', "![Pic](images/pic.png)\n\n![Remote](https://example.org/x.png)\n"));
        $this->commit();
        $this->sync();

        $post = Post::firstOrFail();
        $files = $post->content_images()->get();
        $this->assertCount(1, $files);
        $file = $files->first();
        $this->assertTrue((bool) $file->is_public);
        $this->assertSame('content_images', $file->field);
        $this->assertSame(Post::class, $file->attachment_type);
        $this->assertSame($post->id, (int) $file->attachment_id);
        // Stored without the site's host, so the HTML doesn't depend on app.url.
        $this->assertStringStartsWith('http', $file->getPath());
        $this->assertStringContainsString('src="' . parse_url($file->getPath(), PHP_URL_PATH) . '"', $post->content_html);
        $this->assertStringNotContainsString('src="http', explode('https://example.org', $post->content_html)[0]);
        $this->assertStringContainsString('https://example.org/x.png', $post->content_html);

        // Unchanged image is reused.
        $this->sync(true);
        $this->assertSame(1, File::count());
        $this->assertSame($file->id, $post->content_images()->first()->id);

        // Changing only the image re-syncs the post and replaces the file.
        $this->write('posts/2026/images/pic.png', $this->png(0, 0, 255));
        $this->commit('Change image');
        $result = $this->sync();
        $this->assertSame(1, $result->updated);
        $this->assertSame(1, File::count());
        $replacement = Post::firstOrFail()->content_images()->first();
        $this->assertNotSame($file->id, $replacement->id);
        $this->assertFalse(Filesystem::exists($file->getLocalPath()));

        // Removing the image from the Markdown deletes its file.
        $this->write('posts/2026/a.md', $this->postFile('A', "No images now.\n"));
        $this->commit('Drop image');
        $this->sync();
        $this->assertSame(0, File::count());
    }

    public function testTeaserLifecycle()
    {
        $this->write('posts/2026/images/teaser.png', $this->png(255, 0, 0));
        $this->write('posts/2026/a.md', $this->postFile('A', "Body\n", ['teaser = "images/teaser.png"']));
        $this->commit();
        $this->sync();

        // Stored as the post's public featured image; it isn't added to the content.
        $post = Post::firstOrFail();
        $teaser = $post->featured_images()->first();
        $this->assertNotNull($teaser);
        $this->assertTrue((bool) $teaser->is_public);
        $this->assertSame('featured_images', $teaser->field);
        $this->assertSame('posts/2026/images/teaser.png', $teaser->title);
        $this->assertSame(0, $post->content_images()->count());
        $this->assertStringNotContainsString('<img', $post->content_html);

        // Unchanged: the same file is kept.
        $this->assertSame(1, $this->sync(true)->updated);
        $this->assertSame($teaser->id, Post::firstOrFail()->featured_images()->first()->id);
        $this->assertSame(1, File::count());

        // Changing only the image re-syncs the post and replaces the file.
        $this->write('posts/2026/images/teaser.png', $this->png(0, 0, 255));
        $this->commit('Change teaser');
        $this->assertSame(1, $this->sync()->updated);
        $replacement = Post::firstOrFail()->featured_images()->first();
        $this->assertNotSame($teaser->id, $replacement->id);
        $this->assertSame(1, File::count());

        // Removing the setting deletes the teaser.
        $this->write('posts/2026/a.md', $this->postFile('A', "Body\n"));
        $this->commit('Drop teaser');
        $this->sync();
        $this->assertSame(0, Post::firstOrFail()->featured_images()->count());
        $this->assertSame(0, File::count());
    }

    public function testMissingOrInvalidTeaserIsSkipped()
    {
        $this->write('posts/notes.txt', "not an image\n");
        $this->write('posts/a.md', $this->postFile('A', "Body\n", ['teaser = "images/missing.png"']));
        $this->write('posts/b.md', $this->postFile('B', "Body\n", ['teaser = "notes.txt"']));
        $this->write('posts/c.md', $this->postFile('C', "Body\n", ['teaser = "https://example.org/x.png"']));
        $this->commit();
        $result = $this->sync();

        $this->assertEmpty($result->failed);
        $this->assertSame(3, Post::count());
        $this->assertSame(0, File::count());
    }

    public function testFailedSaveLeavesNoUnattachedFile()
    {
        $this->write('posts/one.md', $this->postFile('One', "Body\n", ['slug = "same"']));
        $this->commit();
        $this->sync();

        // A second post with the same slug fails Winter.Blog's unique rule after its image was stored.
        $this->write('posts/images/pic.png', $this->png(0, 255, 0));
        $this->write('posts/two.md', $this->postFile('Two', "![Pic](images/pic.png)\n", ['slug = "same"']));
        $this->commit();
        $result = $this->sync();

        $this->assertArrayHasKey('posts/two.md', $result->failed);
        $this->assertSame(0, File::count());
    }

    public function testMissingImageKeepsOriginalUrl()
    {
        $this->write('posts/a.md', $this->postFile('A', "![Gone](missing.png)\n"));
        $this->commit();
        $result = $this->sync();

        $this->assertEmpty($result->failed);
        $this->assertStringContainsString('src="missing.png"', Post::firstOrFail()->content_html);
    }

    //
    // Git edge cases
    //

    public function testPathsWithSpacesAndNonAscii()
    {
        $this->write('posts/My Folder/a.md', $this->postFile('Spaces', "Body\n", ['slug = "spaces"']));
        $this->write('posts/ünïcode.md', $this->postFile('Unicode', "Body\n", ['slug = "unicode"']));
        $this->commit();
        $result = $this->sync();

        // "My Folder" is not a valid URL segment; the file is read correctly but rejected for its slug.
        $this->assertArrayHasKey('posts/My Folder/a.md', $result->failed);
        $this->assertStringContainsString('not a valid URL segment', $result->failed['posts/My Folder/a.md']);
        $this->assertSame('Unicode', Post::where('slug', 'unicode')->firstOrFail()->title);
    }

    public function testMissingOrCorruptMirrorIsRecloned()
    {
        $this->write('posts/a.md', $this->postFile('A'));
        $this->commit();
        $this->sync();

        Filesystem::deleteDirectory($this->mirror);
        Filesystem::makeDirectory($this->mirror, 0755, true);
        Filesystem::put($this->mirror . '/garbage', 'not a repository');

        $this->write('posts/b.md', $this->postFile('B'));
        $this->commit();
        $result = $this->sync();

        $this->assertSame(SyncResult::STATUS_SYNCED, $result->status);
        $this->assertSame(2, Post::count());
    }

    public function testChangedRepositoryUrlIsRecloned()
    {
        $this->write('posts/a.md', $this->postFile('A'));
        $this->commit();
        $this->sync();

        // A different repository with its own history; the mirror still points at the first one.
        $this->repo = dirname($this->repo) . '/other';
        Filesystem::makeDirectory($this->repo, 0755, true);
        $this->git(['init', '-q', '-b', 'master']);
        $this->write('posts/b.md', $this->postFile('B'));
        $this->commit();
        Config::set('josephcrowell.blogsmd::repository', $this->repo);

        $result = $this->sync();

        $this->assertSame(SyncResult::STATUS_SYNCED, $result->status);
        $this->assertSame(['b'], Post::pluck('slug')->all());
    }

    public function testForcePushFallsBackToSourcePath()
    {
        $this->write('posts/a.md', $this->postFile('A'));
        $this->commit();
        $this->sync();
        $id = Post::firstOrFail()->id;

        // Rewrite history so the processed commit no longer exists.
        $this->git(['checkout', '-q', '--orphan', 'rewritten']);
        $this->write('posts/a.md', $this->postFile('A rewritten'));
        $this->commit('Rewritten');
        $this->git(['branch', '-q', '-D', 'master']);
        $this->git(['branch', '-q', '-m', 'master']);
        $this->git(['reflog', 'expire', '--expire=now', '--all']);
        $this->git(['gc', '-q', '--prune=now']);
        Filesystem::deleteDirectory($this->mirror);

        $result = $this->sync();

        $this->assertEmpty($result->failed);
        $this->assertSame($id, Post::firstOrFail()->id);
        $this->assertSame('A rewritten', Post::firstOrFail()->title);
    }

    protected function elementsBody(): string
    {
        return <<<'MD'
Heading
=======

## Second level

A paragraph with **strong**, *em*, ~~struck~~, `code`, <kbd>Ctrl</kbd> and <https://sonicde.org>.[^1]

[^1]: A footnote (Winter.Blog 2.2.1+).

> A quote
>
> > Nested

- One
  - Two
    - Three

| Left | Right |
| :--- | ---: |
| a | b |

```text
pre block
```

<dl><dt>Term</dt><dd>Definition</dd></dl>

<details><summary>More</summary>Hidden</details>

<figure><figcaption>Caption</figcaption></figure>

---
MD;
    }
}
