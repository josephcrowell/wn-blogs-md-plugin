<?php

namespace JosephCrowell\BlogsMd\Tests;

use JosephCrowell\BlogsMd\Classes\SourceFile;
use System\Tests\Bootstrap\PluginTestCase;

/**
 * Every example file in the README's "Writing a post" and "Categories" sections must parse.
 */
class ReadmeExamplesTest extends PluginTestCase
{
    public function testPostExamplesParse()
    {
        $examples = $this->examples('Writing a post');
        $this->assertNotEmpty($examples);

        foreach ($examples as $example) {
            $parser = new SourceFile;
            $data = $parser->parsePost($example, 'posts/2026/2026-11-02-example.md', 'posts');
            $this->assertNotSame('', $data['title']);
            $this->assertSame([], $parser->warnings);
        }
    }

    public function testCategoryExamplesParse()
    {
        $examples = $this->examples('Categories');
        $this->assertNotEmpty($examples);

        foreach ($examples as $example) {
            $parser = new SourceFile;
            $data = $parser->parseCategory($example, 'categories/release-notes.md');
            $this->assertNotSame('', $data['name']);
            $this->assertSame([], $parser->warnings);
        }
    }

    /**
     * The ```ini blocks in the README section with the given heading.
     */
    protected function examples(string $heading): array
    {
        $readme = file_get_contents(plugins_path('josephcrowell/blogsmd/README.md'));
        $section = preg_split('/^## /m', $readme);
        $text = '';

        foreach ($section as $part) {
            if (str_starts_with($part, $heading . "\n")) {
                $text = $part;
            }
        }

        preg_match_all('/```ini\n(.*?)```/s', $text, $matches);

        return $matches[1];
    }
}
