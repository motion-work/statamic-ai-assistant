<?php

namespace MotionWork\StatamicAiAssistant\Tests\Support;

use GuzzleHttp\Psr7\Utils;
use MotionWork\StatamicAiAssistant\Support\OpenAiChatCompletionStream;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Psr\Http\Message\StreamInterface;

class OpenAiChatCompletionStreamTest extends TestCase
{
    /**
     * @param  list<string>  $events  Raw SSE lines, one event each.
     */
    private function sse(array $events): StreamInterface
    {
        return Utils::streamFor(implode("\n\n", $events)."\n\n");
    }

    private function chunk(array $json): string
    {
        return 'data: '.json_encode($json);
    }

    public function test_it_skips_comments_and_records_model_usage_and_finish_reason(): void
    {
        $stream = $this->sse([
            ': OPENROUTER PROCESSING',
            $this->chunk(['id' => 'gen-1', 'model' => 'm/a', 'choices' => [['delta' => ['reasoning' => 'hmm '], 'finish_reason' => null]]]),
            $this->chunk(['id' => 'gen-1', 'model' => 'm/a', 'choices' => [['delta' => ['content' => 'Hal'], 'finish_reason' => null]]]),
            $this->chunk(['id' => 'gen-1', 'model' => 'm/a', 'choices' => [['delta' => ['content' => 'lo'], 'finish_reason' => 'stop']]]),
            $this->chunk(['id' => 'gen-1', 'model' => 'm/a', 'choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 9, 'cost' => 0.001]]),
            'data: [DONE]',
        ]);

        $shown = '';
        $meta = null;
        $full = OpenAiChatCompletionStream::collect($stream, function (string $delta) use (&$shown) {
            $shown .= $delta;
        }, $meta);

        $this->assertSame('Hallo', $full, 'reasoning never ends up in the answer');
        $this->assertSame('hmm Hallo', $shown, 'but is shown as progress');
        $this->assertSame('gen-1', $meta['id']);
        $this->assertSame('m/a', $meta['model']);
        $this->assertSame(0.001, $meta['usage']['cost']);
        $this->assertSame('stop', $meta['finish_reason']);
    }

    public function test_an_error_after_the_stream_started_throws(): void
    {
        $stream = $this->sse([
            $this->chunk(['choices' => [['delta' => ['content' => 'Half an ans'], 'finish_reason' => null]]]),
            $this->chunk(['error' => ['code' => 502, 'message' => 'Provider disconnected'], 'choices' => [['delta' => [], 'finish_reason' => 'error']]]),
        ]);

        $this->expectExceptionMessage('Provider disconnected');

        OpenAiChatCompletionStream::collect($stream, fn () => null);
    }

    public function test_a_finish_reason_error_without_details_still_throws(): void
    {
        $stream = $this->sse([
            $this->chunk(['choices' => [['delta' => ['content' => 'x'], 'finish_reason' => 'error']]]),
        ]);

        $this->expectException(\RuntimeException::class);

        OpenAiChatCompletionStream::collect($stream, fn () => null);
    }
}
