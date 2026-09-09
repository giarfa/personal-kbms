<?php

namespace Tests\Unit\Transcripts;

use App\Transcripts\TranscriptsDirectory;
use Tests\TestCase;

class TranscriptsDirectoryTest extends TestCase
{
    private ?string $tempDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/kbms-transcripts-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
        // sys_get_temp_dir() may itself be a symlink (e.g. macOS /var -> /private/var);
        // canonicalize so assertions comparing against TranscriptsDirectory::base()
        // (always a realpath()) are not comparing two different string forms of
        // the same directory.
        $this->tempDir = realpath($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            chmod($this->tempDir, 0755);
            $this->removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;

            if (is_link($path) || is_file($path)) {
                @chmod($path, 0644);
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            }
        }

        rmdir($dir);
    }

    private function directory(): TranscriptsDirectory
    {
        return new TranscriptsDirectory;
    }

    public function test_an_unset_path_is_not_configured(): void
    {
        config(['kbms.transcripts_path' => null]);

        $this->assertFalse($this->directory()->configured());
        $this->assertNull($this->directory()->base());
    }

    public function test_a_missing_path_is_not_configured(): void
    {
        config(['kbms.transcripts_path' => $this->tempDir.'/does-not-exist']);

        $this->assertFalse($this->directory()->configured());
    }

    public function test_a_path_that_is_a_file_not_a_directory_is_not_configured(): void
    {
        $file = $this->tempDir.'/not-a-dir.md';
        file_put_contents($file, 'x');

        config(['kbms.transcripts_path' => $file]);

        $this->assertFalse($this->directory()->configured());
    }

    public function test_an_unreadable_base_is_not_configured(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Cannot test unreadable permissions running as root.');
        }

        chmod($this->tempDir, 0000);

        config(['kbms.transcripts_path' => $this->tempDir]);

        $this->assertFalse($this->directory()->configured());
    }

    public function test_a_valid_directory_is_configured_and_reports_its_realpath(): void
    {
        config(['kbms.transcripts_path' => $this->tempDir]);

        $this->assertTrue($this->directory()->configured());
        $this->assertSame(realpath($this->tempDir), $this->directory()->base());
    }

    public function test_the_index_lists_only_configured_extensions_case_insensitively(): void
    {
        file_put_contents($this->tempDir.'/a.md', 'x');
        file_put_contents($this->tempDir.'/b.TXT', 'x');
        file_put_contents($this->tempDir.'/c.pdf', 'x');

        config(['kbms.transcripts_path' => $this->tempDir]);

        $files = $this->directory()->files();

        $this->assertCount(2, $files);
        $this->assertArrayHasKey($this->tempDir.'/a.md', $files);
        $this->assertArrayHasKey($this->tempDir.'/b.TXT', $files);
    }

    public function test_the_index_does_not_recurse_into_a_subdirectory(): void
    {
        mkdir($this->tempDir.'/nested');
        file_put_contents($this->tempDir.'/nested/hidden.md', 'x');
        file_put_contents($this->tempDir.'/top.md', 'x');

        config(['kbms.transcripts_path' => $this->tempDir]);

        $files = $this->directory()->files();

        $this->assertCount(1, $files);
        $this->assertArrayHasKey($this->tempDir.'/top.md', $files);
    }

    public function test_resolve_rejects_a_name_with_a_directory_separator(): void
    {
        config(['kbms.transcripts_path' => $this->tempDir]);

        $this->assertNull($this->directory()->resolve('sub/dir.md'));
    }

    public function test_resolve_rejects_a_name_with_a_null_byte(): void
    {
        config(['kbms.transcripts_path' => $this->tempDir]);

        $this->assertNull($this->directory()->resolve("evil.md\0.txt"));
    }

    public function test_resolve_rejects_dot_dot_traversal(): void
    {
        config(['kbms.transcripts_path' => $this->tempDir]);

        $this->assertNull($this->directory()->resolve('../outside.md'));
    }

    public function test_a_symlink_inside_the_base_pointing_outside_it_is_rejected(): void
    {
        $outside = sys_get_temp_dir().'/kbms-outside-'.uniqid().'.md';
        file_put_contents($outside, 'secret');
        symlink($outside, $this->tempDir.'/link.md');

        config(['kbms.transcripts_path' => $this->tempDir]);

        $this->assertNull($this->directory()->resolve('link.md'));
        $this->assertArrayNotHasKey($this->tempDir.'/link.md', $this->directory()->files());

        unlink($outside);
    }

    public function test_a_missing_path_inside_the_base_is_contained_not_rejected(): void
    {
        config(['kbms.transcripts_path' => $this->tempDir]);

        // Broken (missing-but-legitimate) and Rejected (escapes the base)
        // must not collapse: a path realpath() cannot resolve because it
        // simply does not exist is still `contains() === true`.
        $this->assertTrue($this->directory()->contains($this->tempDir.'/gone.md'));
    }

    public function test_resolve_returns_the_joined_path_for_a_valid_name(): void
    {
        file_put_contents($this->tempDir.'/note.md', 'x');

        config(['kbms.transcripts_path' => $this->tempDir]);

        $this->assertSame($this->tempDir.'/note.md', $this->directory()->resolve('note.md'));
    }
}
