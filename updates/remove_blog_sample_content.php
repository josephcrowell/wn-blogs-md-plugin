<?php

namespace JosephCrowell\BlogsMd\Updates;

use Illuminate\Support\Facades\DB;
use Winter\Storm\Database\Updates\Seeder;

/**
 * Removes the sample post and category Winter.Blog seeds on install. Its text tells editors to use the
 * backend, which blogsmd locks; posts come from Git. Sync never touches them, because they have no
 * `sources` row.
 *
 * Plain table deletes, not model deletes: Post's Winter.Translate behaviour would query that plugin's
 * tables, which may not exist yet during `winter:up`. The seeded rows have nothing else attached.
 */
class RemoveBlogSampleContent extends Seeder
{
    public function run()
    {
        $managed = fn (string $type, int $id) => DB::table('josephcrowell_blogsmd_sources')
            ->where('type', $type)
            ->where('model_id', $id)
            ->exists();

        $post = DB::table('winter_blog_posts')
            ->where('slug', 'first-blog-post')
            ->where('title', 'First blog post')
            ->first();

        if ($post && !$managed('post', $post->id)) {
            DB::table('winter_blog_posts_categories')->where('post_id', $post->id)->delete();
            DB::table('winter_blog_posts')->where('id', $post->id)->delete();
        }

        $category = DB::table('winter_blog_categories')->where('slug', 'uncategorized')->first();

        if (
            $category
            && !$managed('category', $category->id)
            && !DB::table('winter_blog_posts_categories')->where('category_id', $category->id)->exists()
            && !DB::table('winter_blog_categories')->where('parent_id', $category->id)->exists()
        ) {
            DB::table('winter_blog_categories')->where('id', $category->id)->delete();
        }
    }
}
