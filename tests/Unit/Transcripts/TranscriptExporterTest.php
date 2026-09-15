<?php

namespace Tests\Unit\Transcripts;

use App\Meetings\MarkdownRenderer;
use App\Meetings\OccurrenceKey;
use App\Models\MeetingTranscript;
use App\Transcripts\TranscriptExport;
use App\Transcripts\TranscriptExporter;
use App\Transcripts\TranscriptReader;
use App\Transcripts\TranscriptsDirectory;
use App\Transcripts\TranscriptState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptExporterTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tempDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = realpath(sys_get_temp_dir()).'/kbms-exporter-test-'.uniqid();
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

    private function exporter(): TranscriptExporter
    {
        return new TranscriptExporter(new TranscriptReader(new TranscriptsDirectory, new MarkdownRenderer));
    }

    private function key(): OccurrenceKey
    {
        return new OccurrenceKey('exporter-uid', '');
    }

    private function file(string $name, string $contents): string
    {
        $path = $this->tempDir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_missing_when_no_row_exists(): void
    {
        $this->assertSame(TranscriptState::Missing, $this->exporter()->pathFor($this->key()));
        $this->assertSame(TranscriptState::Missing, $this->exporter()->forOccurrence($this->key()));
    }

    public function test_suppressed_for_the_manual_unlink_tombstone(): void
    {
        MeetingTranscript::factory()->suppressed()->create([
            'event_uid' => 'exporter-uid',
            'event_recurrence_id' => '',
        ]);

        $this->assertSame(TranscriptState::Suppressed, $this->exporter()->pathFor($this->key()));
        $this->assertSame(TranscriptState::Suppressed, $this->exporter()->forOccurrence($this->key()));
    }

    public function test_a_healthy_row_resolves_to_its_verified_path_and_complete_content(): void
    {
        $path = $this->file('healthy.md', '# Complete transcript');
        MeetingTranscript::factory()->convention()->create([
            'event_uid' => 'exporter-uid',
            'event_recurrence_id' => '',
            'path' => $path,
        ]);

        $this->assertSame($path, $this->exporter()->pathFor($this->key()));

        $export = $this->exporter()->forOccurrence($this->key());
        $this->assertInstanceOf(TranscriptExport::class, $export);
        $this->assertSame($path, $export->path);
        $this->assertSame('healthy.md', $export->filename());
        $this->assertStringContainsString('Complete transcript', (string) $export->content->rendered);
    }

    public function test_a_broken_stored_path_surfaces_its_own_state(): void
    {
        MeetingTranscript::factory()->convention()->create([
            'event_uid' => 'exporter-uid',
            'event_recurrence_id' => '',
            'path' => $this->tempDir.'/gone.md',
        ]);

        $this->assertSame(TranscriptState::Broken, $this->exporter()->pathFor($this->key()));
        $this->assertSame(TranscriptState::Broken, $this->exporter()->forOccurrence($this->key()));
    }

    public function test_an_unreadable_stored_path_surfaces_its_own_state(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Cannot test unreadable permissions running as root.');
        }

        $path = $this->file('locked.md', 'secret');
        chmod($path, 0000);
        MeetingTranscript::factory()->convention()->create([
            'event_uid' => 'exporter-uid',
            'event_recurrence_id' => '',
            'path' => $path,
        ]);

        $this->assertSame(TranscriptState::Unreadable, $this->exporter()->pathFor($this->key()));
        $this->assertSame(TranscriptState::Unreadable, $this->exporter()->forOccurrence($this->key()));
    }

    public function test_a_stored_path_escaping_the_base_surfaces_rejected(): void
    {
        $outsideDir = realpath(sys_get_temp_dir()).'/kbms-exporter-outside-'.uniqid();
        mkdir($outsideDir, 0755, true);
        $outside = $outsideDir.'/outside.md';
        file_put_contents($outside, 'x');

        MeetingTranscript::factory()->convention()->create([
            'event_uid' => 'exporter-uid',
            'event_recurrence_id' => '',
            'path' => $outside,
        ]);

        $this->assertSame(TranscriptState::Rejected, $this->exporter()->pathFor($this->key()));
        $this->assertSame(TranscriptState::Rejected, $this->exporter()->forOccurrence($this->key()));

        unlink($outside);
        rmdir($outsideDir);
    }
}
