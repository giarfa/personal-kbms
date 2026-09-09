<?php

namespace Tests\Unit\Transcripts;

use App\Meetings\MarkdownRenderer;
use App\Transcripts\TranscriptContent;
use App\Transcripts\TranscriptReader;
use App\Transcripts\TranscriptsDirectory;
use App\Transcripts\TranscriptState;
use Tests\TestCase;

class TranscriptReaderTest extends TestCase
{
    private ?string $tempDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = realpath(sys_get_temp_dir()).'/kbms-reader-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        config(['kbms.transcripts_path' => $this->tempDir]);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*') ?: [] as $file) {
                @chmod($file, 0644);
                unlink($file);
            }

            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    private function reader(): TranscriptReader
    {
        return new TranscriptReader(new TranscriptsDirectory, new MarkdownRenderer);
    }

    private function file(string $name, string $contents): string
    {
        $path = $this->tempDir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_a_file_larger_than_the_cap_reads_exactly_the_cap_and_flags_truncation(): void
    {
        config(['kbms.transcript_preview_bytes' => 10]);
        $path = $this->file('big.txt', str_repeat('a', 50));

        $content = $this->reader()->read($path);

        $this->assertInstanceOf(TranscriptContent::class, $content);
        $this->assertTrue($content->truncated);
        $this->assertSame(10, $content->bytesRead);
        $this->assertSame(50, $content->totalBytes);
    }

    public function test_a_file_at_exactly_the_cap_is_not_flagged(): void
    {
        config(['kbms.transcript_preview_bytes' => 10]);
        $path = $this->file('exact.txt', str_repeat('a', 10));

        $content = $this->reader()->read($path);

        $this->assertFalse($content->truncated);
        $this->assertSame(10, $content->bytesRead);
    }

    public function test_an_empty_file_reads_cleanly(): void
    {
        $path = $this->file('empty.txt', '');

        $content = $this->reader()->read($path);

        $this->assertFalse($content->truncated);
        $this->assertSame(0, $content->bytesRead);
        $this->assertSame(0, $content->totalBytes);
    }

    public function test_truncation_lands_on_a_utf8_boundary_for_multibyte_content(): void
    {
        // "café" repeated so a byte-count cut at an arbitrary offset is
        // very likely to land mid-codepoint on the "é" (2-byte UTF-8).
        config(['kbms.transcript_preview_bytes' => 9]);
        $path = $this->file('multibyte.txt', str_repeat('café', 10));

        $content = $this->reader()->read($path);

        $this->assertTrue($content->truncated);
        $this->assertTrue(mb_check_encoding((string) $content->rendered, 'UTF-8'));
    }

    public function test_a_missing_file_is_broken(): void
    {
        $path = $this->tempDir.'/does-not-exist.md';

        $this->assertSame(TranscriptState::Broken, $this->reader()->read($path));
    }

    public function test_an_unreadable_file_is_unreadable(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Cannot test unreadable permissions running as root.');
        }

        $path = $this->file('locked.md', 'secret');
        chmod($path, 0000);

        $this->assertSame(TranscriptState::Unreadable, $this->reader()->read($path));
    }

    public function test_a_path_outside_the_base_is_rejected(): void
    {
        $outsideDir = realpath(sys_get_temp_dir()).'/kbms-reader-outside-'.uniqid();
        mkdir($outsideDir, 0755, true);
        file_put_contents($outsideDir.'/outside.md', 'x');

        $this->assertSame(TranscriptState::Rejected, $this->reader()->read($outsideDir.'/outside.md'));

        unlink($outsideDir.'/outside.md');
        rmdir($outsideDir);
    }

    public function test_missing_unreadable_and_rejected_are_three_distinct_states(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Cannot test unreadable permissions running as root.');
        }

        $missing = $this->reader()->read($this->tempDir.'/gone.md');

        $lockedPath = $this->file('locked2.md', 'x');
        chmod($lockedPath, 0000);
        $unreadable = $this->reader()->read($lockedPath);

        $rejected = $this->reader()->read('/etc/passwd');

        $this->assertNotSame($missing, $unreadable);
        $this->assertNotSame($unreadable, $rejected);
        $this->assertNotSame($missing, $rejected);
    }

    public function test_markdown_with_a_script_tag_and_javascript_link_renders_escaped(): void
    {
        $path = $this->file('hostile.md', "<script>alert(1)</script>\n\n[click](javascript:alert(1))");

        $content = $this->reader()->read($path);

        $html = (string) $content->rendered;
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_a_txt_transcript_with_markdown_syntax_is_escaped_not_converted(): void
    {
        $path = $this->file('plain.txt', '# Not a heading\n\n**not bold**');

        $content = $this->reader()->read($path);

        $html = (string) $content->rendered;
        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertFalse($content->isMarkdown);
    }

    public function test_file_get_contents_appears_nowhere_in_the_reader(): void
    {
        $source = file_get_contents(app_path('Transcripts/TranscriptReader.php'));
        // Strip comments/docblocks first — the class's own docblock explains
        // WHY file_get_contents() and @ are avoided, which would otherwise
        // trip a naive substring search on its own documentation.
        $code = preg_replace('#/\*.*?\*/|//.*$#ms', '', (string) $source) ?? '';

        $this->assertStringNotContainsString('file_get_contents', $code);
        $this->assertStringNotContainsString('@', $code);
    }
}
