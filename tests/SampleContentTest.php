<?php

namespace JosephCrowell\BlogsMd\Tests;

use Illuminate\Support\Facades\DB;
use JosephCrowell\BlogsMd\Models\Source;
use JosephCrowell\BlogsMd\Updates\RemoveBlogSampleContent;
use System\Tests\Bootstrap\PluginTestCase;
use Winter\Blog\Models\Category;
use Winter\Blog\Models\Post;

/**
 * Winter.Blog's install seeds a sample post and category; installing this plugin removes them.
 */
class SampleContentTest extends PluginTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Post implements Winter.Translate's behaviour; deleting one queries its tables.
        $this->instantiatePlugin('Winter.Translate');
    }

    public function testInstallRemovesWinterBlogSampleContent()
    {
        // PluginTestCase installed Winter.Blog (which seeds) and then this plugin.
        $this->assertFalse(Post::where('slug', 'first-blog-post')->exists());
        $this->assertFalse(Category::where('slug', 'uncategorized')->exists());
    }

    public function testKeepsContentThatIsManagedOrInUse()
    {
        $post = $this->samplePost();
        Source::create([
            'type' => Source::TYPE_POST,
            'model_id' => $post->id,
            'source_path' => 'posts/first-blog-post.md',
            'content_hash' => str_repeat('a', 40),
        ]);

        $category = Category::create(['name' => 'Uncategorized', 'slug' => 'uncategorized']);
        $other = $this->samplePost('other-post', 'Other');
        DB::table('winter_blog_posts_categories')->insert(['post_id' => $other->id, 'category_id' => $category->id]);

        (new RemoveBlogSampleContent)->run();

        $this->assertTrue(Post::where('slug', 'first-blog-post')->exists());
        $this->assertTrue(Category::where('slug', 'uncategorized')->exists());
    }

    public function testRemovesUnmanagedSampleWhenRunAgain()
    {
        $this->samplePost();
        Category::create(['name' => 'Uncategorized', 'slug' => 'uncategorized']);

        (new RemoveBlogSampleContent)->run();

        $this->assertFalse(Post::where('slug', 'first-blog-post')->exists());
        $this->assertFalse(Category::where('slug', 'uncategorized')->exists());
    }

    protected function samplePost(string $slug = 'first-blog-post', string $title = 'First blog post'): Post
    {
        $post = new Post;
        $post->title = $title;
        $post->slug = $slug;
        $post->content = 'You can edit this content by selecting Blog from the administration back-end menu.';
        $post->published = true;
        $post->published_at = now()->subDay();
        $post->save();

        return $post;
    }
}
