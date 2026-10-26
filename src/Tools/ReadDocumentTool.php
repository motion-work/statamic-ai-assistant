<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use MotionWork\StatamicAiAssistant\Services\DocumentTextExtractor;
use Statamic\Facades\Asset;

/**
 * Lets the planner read the text of a PDF/TXT/MD/CSV document stored in the
 * asset library — so "@asset:…report.pdf" references can actually be read
 * instead of the agent claiming it cannot open PDFs. Shares its extraction
 * with chat attachments via {@see DocumentTextExtractor}.
 */
class ReadDocumentTool implements ChatTool
{
    private const MAX_DOCUMENT_BYTES = 15 * 1024 * 1024;

    /** Cap on the text returned per call — keeps one huge PDF from eating the context. */
    private const MAX_RETURN_CHARS = 20000;

    public function __construct(private DocumentTextExtractor $extractor = new DocumentTextExtractor) {}

    public function name(): string
    {
        return 'read_document';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'read_document',
                'description' => 'Read the TEXT CONTENT of a document in the asset library (pdf, txt, md, csv). '
                    .'Pass the exact "container::path" reference — from an @asset: mention or list_assets. '
                    .'Use this whenever the user references or asks about a document asset; you CAN read PDFs through this tool.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'ref' => [
                            'type' => 'string',
                            'description' => 'Exact asset reference "container::path/to/file.pdf".',
                        ],
                    ],
                    'required' => ['ref'],
                ],
            ],
        ];
    }

    public function handle(string $argumentsJson, ToolContext $context): array
    {
        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        $ref = isset($args['ref']) && is_string($args['ref']) ? trim($args['ref']) : '';
        $asset = $ref !== '' ? Asset::find($ref) : null;

        if (! $asset) {
            return ['ok' => false, 'error' => "Asset \"{$ref}\" not found. Use exact \"container::path\" references from @asset: mentions or list_assets."];
        }

        $extension = strtolower((string) $asset->extension());
        if (! in_array($extension, DocumentTextExtractor::READABLE_EXTENSIONS, true)) {
            return ['ok' => false, 'error' => "Asset \"{$ref}\" is not a readable document (supported: ".implode(', ', DocumentTextExtractor::READABLE_EXTENSIONS).'). For images use analyze_image.'];
        }

        if ((int) $asset->size() > self::MAX_DOCUMENT_BYTES) {
            return ['ok' => false, 'error' => 'Document is too large to read ('.round($asset->size() / 1_000_000, 1).'MB).'];
        }

        $context->reportActivity((string) __('Reading document :file', ['file' => $asset->basename()]));

        $contents = $asset->contents();
        if (! is_string($contents) || $contents === '') {
            return ['ok' => false, 'error' => 'Could not read the document file.'];
        }

        $result = $this->extractor->extract($contents, $extension, $asset->basename());
        if ($result['content'] === null) {
            return ['ok' => false, 'error' => 'Could not read "'.$asset->basename().'": '.$result['reason'].'.'];
        }
        $text = $result['content'];

        $truncated = mb_strlen($text) > self::MAX_RETURN_CHARS;
        if ($truncated) {
            $text = mb_substr($text, 0, self::MAX_RETURN_CHARS);
        }

        return [
            'ok' => true,
            'ref' => $ref,
            'name' => $asset->basename(),
            'truncated' => $truncated,
            'content' => $text,
        ];
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_document_reads', 20));
    }
}
