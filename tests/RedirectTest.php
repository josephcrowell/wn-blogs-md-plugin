<?php

namespace JosephCrowell\BlogsMd\Tests;

use Illuminate\Support\Facades\Event;
use JosephCrowell\BlogsMd\Models\Settings;
use Winter\Blog\Models\Post;
use Winter\Redirect\Models\Redirect;
use Winter\Storm\Support\Facades\Config;

class RedirectTest extends BlogsMdTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // PluginTestCase migrates only this plugin and its $require (Winter.Blog).
        $this->instantiatePlugin('Winter.Redirect');
    }

    public function testMoveBetweenFoldersChangesSlugAndCreatesRedirect()
    {
        $this->write('posts/2025/post.md', $this->postFile('Moving'));
        $this->commit('Add', 'Original Author', 'original@example.org');
        $this->sync();

        // Event::fake() can't be used: Winter models call Event::fire(), which Laravel's EventFake lacks.
        $dispatched = 0;
        Event::listen('winter.redirect.changed', function () use (&$dispatched) {
            $dispatched++;
        });

        $this->move('posts/2025/post.md', 'posts/2026/post.md');
        $this->commit('Move', 'Someone Else', 'else@example.org');
        $this->sync();

        $post = Post::firstOrFail();
        $this->assertSame('2026/post', $post->slug);
        $this->assertSame('Original Author', $post->metadata['author_name']);

        $redirect = Redirect::firstOrFail();
        $this->assertSame('exact', $redirect->match_type);
        $this->assertSame('path_or_url', $redirect->target_type);
        $this->assertSame('auto', $redirect->from_scheme);
        $this->assertSame('auto', $redirect->to_scheme);
        $this->assertSame('/blog/2025/post', $redirect->from_url);
        $this->assertSame('/blog/2026/post', $redirect->to_url);
        $this->assertSame('301', (string) $redirect->status_code);
        $this->assertTrue((bool) $redirect->is_enabled);
        $this->assertSame(0, (int) $redirect->sort_order);

        $this->assertSame(1, $dispatched);
    }

    public function testNoRedirectWhenDisabled()
    {
        Config::set('josephcrowell.blogsmd::redirect_on_move', false);

        $this->write('posts/2025/post.md', $this->postFile('Moving'));
        $this->commit();
        $this->sync();

        $this->move('posts/2025/post.md', 'posts/2026/post.md');
        $this->commit('Move');
        $this->sync();

        $this->assertSame('2026/post', Post::firstOrFail()->slug);
        $this->assertSame(0, Redirect::count());
    }

    public function testSwitchTurnedOffInTheBackendWinsOverTheConfig()
    {
        // The config says redirect; the backend switch is saved as off, the way the settings form posts it.
        Config::set('josephcrowell.blogsmd::redirect_on_move', true);
        Settings::set(['redirect_on_move' => '0']);

        $this->write('posts/2025/post.md', $this->postFile('Moving'));
        $this->commit();
        $this->sync();

        $this->move('posts/2025/post.md', 'posts/2026/post.md');
        $this->commit('Move');
        $this->sync();

        $this->assertSame(0, Redirect::count());
    }
}
