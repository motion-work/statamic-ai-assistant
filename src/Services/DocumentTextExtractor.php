<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for "turn a document into LLM-readable text".
 * Used by chat attachments (uploaded PDFs/TXTs) and by the read_document
 * planner tool (PDF/TXT assets in the asset library), so both paths share
 * the same parsing, whitespace handling and truncation.
 *
 * Extraction can legitimately fail — most often a scanned / image-only PDF
 * with no embedded text. Callers MUST surface that instead of dropping the
 * document silently, otherwise the model fabricates content from the file
 * name. {@see extract()} returns the reason so the failure can be shown.
 */
class DocumentTextExtractor
{
    /** Truncation cap so one document cannot blow the LLM context window. */
    private const MAX_WORDS = 8000;

    /** Extensions this extractor understands. */
    public const READABLE_EXTENSIONS = ['pdf', 'txt', 'md', 'csv'];

    /**
     * Attempt extraction and report the outcome. Exactly one of `content`
     * (readable text) or `reason` (why it could not be read, human-readable)
     * is non-null.
     *
     * @return array{content: ?string, reason: ?string}
     */
    public function extract(string $bytes, string $extension, string $name = ''): array
    {
        $extension = strtolower($extension);

        if ($bytes === '') {
            return $this->failed('the file is empty', $extension, $name);
        }

        if (! in_array($extension, self::READABLE_EXTENSIONS, true)) {
            return $this->failed("the .{$extension} format cannot be read as text", $extension, $name);
        }

        try {
            $content = $extension === 'pdf'
                ? (new \Smalot\PdfParser\Parser)->parseContent($bytes)->getText()
                : $bytes;
        } catch (\Throwable $e) {
            Log::warning('Document text extraction failed', [
                'extension' => $extension,
                'name' => $name,
                'error' => $e->getMessage(),
            ]);

            return $this->failed('the document could not be parsed', $extension, $name);
        }

        // Whitespace-only extraction is effectively empty — the classic
        // scanned/image-only PDF. Surface it so the silent drop is diagnosable
        // and the model never fabricates content it never actually read.
        if (trim((string) $content) === '') {
            return $this->failed(
                $extension === 'pdf'
                    ? 'no text could be extracted — it is most likely a scanned or image-only PDF'
                    : 'the document contains no text',
                $extension,
                $name,
            );
        }

        $words = explode(' ', $content);
        if (count($words) > self::MAX_WORDS) {
            $content = implode(' ', array_slice($words, 0, self::MAX_WORDS))."\n\n[Content truncated...]";
        }

        return ['content' => $content, 'reason' => null];
    }

    /**
     * Text of an uploaded chat attachment, or null when nothing is extractable.
     * Prefer {@see extractUploadedFile()} when you need to show WHY it failed.
     */
    public function fromUploadedFile(UploadedFile $file): ?string
    {
        return $this->extractUploadedFile($file)['content'];
    }

    /**
     * Extraction outcome for an uploaded file (reads it off disk first).
     *
     * @return array{content: ?string, reason: ?string}
     */
    public function extractUploadedFile(UploadedFile $file): array
    {
        $bytes = @file_get_contents($file->getRealPath());

        return $this->extract(
            is_string($bytes) ? $bytes : '',
            $file->getClientOriginalExtension(),
            $file->getClientOriginalName(),
        );
    }

    /**
     * Text from raw document bytes (e.g. a Statamic asset's contents), or null
     * when nothing is extractable.
     */
    public function fromBytes(string $bytes, string $extension, string $name = ''): ?string
    {
        return $this->extract($bytes, $extension, $name)['content'];
    }

    /**
     * @return array{content: null, reason: string}
     */
    private function failed(string $reason, string $extension, string $name): array
    {
        Log::warning('Document text extraction yielded no usable text', [
            'extension' => $extension,
            'name' => $name,
            'reason' => $reason,
        ]);

        return ['content' => null, 'reason' => $reason];
    }
}
