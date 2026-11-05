<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai;

/**
 * One image from OpenRouter's images endpoint: the decoded bytes, their media
 * type, and the model that made it (the endpoint does not echo the model, so
 * it is the one the request asked for).
 */
final class GeneratedImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mediaType,
        public readonly string $model,
    ) {}

    /** File extension for the media type (jpg for anything unknown). */
    public function extension(): string
    {
        return match (strtolower($this->mediaType)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }
}
