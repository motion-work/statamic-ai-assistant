<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\DocumentTextExtractor;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Http\UploadedFile;

class DocumentTextExtractorTest extends TestCase
{
    private DocumentTextExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new DocumentTextExtractor;
    }

    public function test_plain_text_bytes_pass_through(): void
    {
        $this->assertSame(
            "Opening hours\nMon-Fri 9-17",
            $this->extractor->fromBytes("Opening hours\nMon-Fri 9-17", 'txt', 'hours.txt'),
        );
    }

    public function test_empty_or_whitespace_only_documents_yield_null(): void
    {
        $this->assertNull($this->extractor->fromBytes('', 'txt'));
        $this->assertNull($this->extractor->fromBytes("  \n\t ", 'txt'));
    }

    public function test_unparseable_pdf_bytes_yield_null_instead_of_throwing(): void
    {
        $this->assertNull($this->extractor->fromBytes('this is not a pdf', 'pdf', 'broken.pdf'));
    }

    public function test_extract_reports_a_reason_for_an_unreadable_pdf(): void
    {
        // An unreadable PDF (unparseable here, or text-less/scanned in the wild)
        // must come back as a reason, never as fabricated content.
        $result = $this->extractor->extract('not really a pdf', 'pdf', 'scan.pdf');

        $this->assertNull($result['content']);
        $this->assertNotEmpty($result['reason']);
    }

    public function test_extract_reports_empty_and_unsupported_reasons(): void
    {
        $this->assertStringContainsString('empty', $this->extractor->extract('', 'pdf', 'x.pdf')['reason']);
        $this->assertStringContainsString('cannot be read', $this->extractor->extract('data', 'docx', 'x.docx')['reason']);
    }

    public function test_extract_returns_content_with_no_reason_on_success(): void
    {
        $result = $this->extractor->extract('Just some notes', 'txt', 'notes.txt');

        $this->assertSame('Just some notes', $result['content']);
        $this->assertNull($result['reason']);
    }

    public function test_very_long_documents_are_truncated(): void
    {
        $text = implode(' ', array_fill(0, 9000, 'word'));

        $result = $this->extractor->fromBytes($text, 'txt');

        $this->assertStringEndsWith('[Content truncated...]', $result);
        $this->assertLessThan(strlen($text), strlen($result));
    }

    public function test_uploaded_txt_file_is_read_from_disk(): void
    {
        $file = UploadedFile::fake()->createWithContent('notes.txt', 'Budget: 12000 CHF');

        $this->assertSame('Budget: 12000 CHF', $this->extractor->fromUploadedFile($file));
    }
}
