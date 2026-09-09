<?php

namespace Tests\Unit\Meetings;

use App\Meetings\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class MarkdownRendererTest extends TestCase
{
    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new MarkdownRenderer;
    }

    public function test_a_script_tag_is_escaped_not_executed(): void
    {
        $html = (string) $this->renderer->render('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_an_img_onerror_is_escaped(): void
    {
        $html = (string) $this->renderer->render('<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function test_a_raw_onclick_attribute_never_reaches_the_output_as_a_live_attribute(): void
    {
        $html = (string) $this->renderer->render('<div onclick="alert(1)">click</div>');

        $this->assertStringNotContainsString('<div', $html);
        $this->assertStringContainsString('&lt;div onclick=', $html);
    }

    public function test_a_javascript_link_does_not_produce_a_javascript_href(): void
    {
        $html = (string) $this->renderer->render('[x](javascript:alert(1))');

        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_a_normal_https_link_produces_an_href(): void
    {
        $html = (string) $this->renderer->render('[x](https://example.com)');

        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    public function test_correct_rendering_still_works_for_common_gfm_shapes(): void
    {
        $body = <<<'MD'
            # Heading

            - one
            - two

            **bold** and `code`

            | A | B |
            | --- | --- |
            | 1 | 2 |

            - [ ] todo item
            MD;

        $html = (string) $this->renderer->render($body);

        $this->assertStringContainsString('<h1>Heading</h1>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    public function test_a_pathologically_nested_body_is_bounded_rather_than_exhausting_the_stack(): void
    {
        $body = str_repeat('> ', 200).'deep';

        $html = (string) $this->renderer->render($body);

        $this->assertLessThan(200, substr_count($html, '<blockquote>'));
    }
}
