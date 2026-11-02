<?php

namespace JosephCrowell\BlogsMd\Tests;

use JosephCrowell\BlogsMd\Classes\SourceException;
use JosephCrowell\BlogsMd\Classes\SourceFile;
use System\Tests\Bootstrap\PluginTestCase;

class SourceFileTest extends PluginTestCase
{
    public function testSplitsOnSeparatorWithCrlfLineEndings()
    {
        $data = (new SourceFile)->parsePost("title = \"CRLF\"\r\n;;\r\nBody text\r\n", 'posts/crlf.md', 'posts');

        $this->assertSame('CRLF', $data['title']);
        $this->assertSame("Body text\n", $data['body']);
    }

    public function testOnlyTheFirstSeparatorSplits()
    {
        $data = (new SourceFile)->parsePost("title = \"T\"\n;;\nOne\n;;\nTwo\n", 'posts/t.md', 'posts');

        $this->assertSame("One\n;;\nTwo\n", $data['body']);
    }

    public function testSetextHeadingsStayInTheBody()
    {
        $body = "Heading\n=======\n\nSub\n---\n\nText\n";
        $data = (new SourceFile)->parsePost("title = \"T\"\n;;\n" . $body, 'posts/t.md', 'posts');

        $this->assertSame($body, $data['body']);
    }

    public function testMissingSeparatorIsAnError()
    {
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('no ";;" line');

        (new SourceFile)->parsePost("title = \"T\"\nBody\n", 'posts/t.md', 'posts');
    }

    public function testMissingTitleIsAnError()
    {
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('"title" setting is required');

        (new SourceFile)->parsePost("excerpt = \"x\"\n;;\nBody\n", 'posts/t.md', 'posts');
    }

    public function testEmptyBodyIsAnError()
    {
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('body is empty');

        (new SourceFile)->parsePost("title = \"T\"\n;;\n\n  \n", 'posts/t.md', 'posts');
    }

    public function testTeaserIsOptional()
    {
        $parser = new SourceFile;
        $with = $parser->parsePost("title = \"T\"\nteaser = \"images/t.png\"\n;;\nBody\n", 'posts/t.md', 'posts');
        $without = $parser->parsePost("title = \"T\"\n;;\nBody\n", 'posts/t.md', 'posts');

        $this->assertSame('images/t.png', $with['teaser']);
        $this->assertNull($without['teaser']);
        $this->assertSame([], $parser->warnings);
    }

    public function testUnknownKeysAreWarnings()
    {
        $parser = new SourceFile;
        $parser->parsePost("title = \"T\"\ncolour = \"red\"\n[author]\nname = \"X\"\n;;\nBody\n", 'posts/t.md', 'posts');

        // The author always comes from Git; an [author] section is just an unknown setting.
        $this->assertCount(2, $parser->warnings);
        $this->assertStringContainsString('"colour"', $parser->warnings[0]);
        $this->assertStringContainsString('"author"', $parser->warnings[1]);
    }

    public function testBadDateIsAnError()
    {
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('not a valid date');

        (new SourceFile)->parsePost("title = \"T\"\ndate = \"not a date at all\"\n;;\nBody\n", 'posts/t.md', 'posts');
    }

    public function testDatesComeFromTheSettingThenTheFileName()
    {
        $parser = new SourceFile;

        $fromSetting = $parser->parsePost("title = \"T\"\ndate = \"2026-11-02 09:00\"\n;;\nB\n", 'posts/x.md', 'posts');
        $this->assertSame('2026-11-02 09:00:00', $fromSetting['date']->format('Y-m-d H:i:s'));

        $fromName = $parser->parsePost("title = \"T\"\n;;\nB\n", 'posts/2026-10-05-x.md', 'posts');
        $this->assertSame('2026-10-05 00:00:00', $fromName['date']->format('Y-m-d H:i:s'));

        $none = $parser->parsePost("title = \"T\"\n;;\nB\n", 'posts/x.md', 'posts');
        $this->assertNull($none['date']);
    }

    public function testNestedFoldersBecomePartOfTheSlug()
    {
        $parser = new SourceFile;

        $this->assertSame('2026/first-post', $parser->postSlug('posts/2026/2026-11-02-first-post.md', 'posts'));
        $this->assertSame('news/2026/renamed', $parser->postSlug('posts/News/2026/2026-11-02-x.md', 'posts', 'Renamed'));
    }

    public function testReservedFirstSegmentIsRejected()
    {
        foreach (['page', 'category'] as $segment) {
            try {
                (new SourceFile)->postSlug('posts/' . $segment . '/x.md', 'posts');
                $this->fail('Expected "' . $segment . '" to be rejected.');
            } catch (SourceException $e) {
                $this->assertStringContainsString('can\'t start with "' . $segment . '"', $e->getMessage());
            }
        }
    }

    public function testInvalidSlugSegmentIsRejected()
    {
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('not a valid URL segment');

        (new SourceFile)->postSlug('posts/with space/x.md', 'posts');
    }

    public function testTwoLetterCategorySlugIsRejected()
    {
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('3 to 64 characters');

        (new SourceFile)->parsePost("title = \"T\"\ncategories[] = \"ab\"\n;;\nB\n", 'posts/t.md', 'posts');
    }

    public function testCategoryFile()
    {
        $data = (new SourceFile)->parseCategory("name = \"Release Notes\"\nparent = \"news\"\n;;\nAbout.\n", 'categories/release-notes.md');

        $this->assertSame('release-notes', $data['slug']);
        $this->assertSame('Release Notes', $data['name']);
        $this->assertSame('news', $data['parent']);
    }

    public function testInvalidIniIsAnError()
    {
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('not valid INI');

        (new SourceFile)->parsePost("title = \"unterminated\nx = (\n;;\nB\n", 'posts/t.md', 'posts');
    }
}
