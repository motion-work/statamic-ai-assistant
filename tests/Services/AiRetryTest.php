<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\InfomaniakService;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

class AiRetryTest extends TestCase
{
    private function isTransient(\Throwable $e): bool
    {
        $service = new InfomaniakService;
        $method = new \ReflectionMethod($service, 'isTransientAiFailure');
        $method->setAccessible(true);

        return (bool) $method->invoke($service, $e);
    }

    private function requestException(int $status): RequestException
    {
        return new RequestException(new Response(new PsrResponse($status)));
    }

    public function test_gateway_and_rate_limit_statuses_are_transient(): void
    {
        foreach ([429, 500, 502, 503, 504] as $status) {
            $this->assertTrue($this->isTransient($this->requestException($status)), "status {$status} should retry");
        }
    }

    public function test_client_errors_are_not_transient(): void
    {
        foreach ([400, 401, 403, 404, 422] as $status) {
            $this->assertFalse($this->isTransient($this->requestException($status)), "status {$status} should not retry");
        }
    }

    public function test_dropped_connection_is_transient_but_timeout_is_not(): void
    {
        // A reset / dropped connection is worth retrying.
        $this->assertTrue($this->isTransient(new ConnectionException('Connection reset by peer')));

        // A hard timeout is NOT retried — the model is just slow; retrying stacks waits.
        $this->assertFalse($this->isTransient(
            new ConnectionException('cURL error 28: Operation timed out after 120002 milliseconds')
        ));
    }
}
