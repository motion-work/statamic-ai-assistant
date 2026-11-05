<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Services\Ai\GeneratedImage;
use MotionWork\StatamicAiAssistant\Support\AssetUrl;

/**
 * Saves a generated image as a Statamic asset: in the image_generation folder
 * of the entry's image container (or the container the downloader would use),
 * named after the prompt, labelled `ai_generated: true` with the prompt in
 * `ai_prompt`, and with an alt text from the vision model set before the
 * first save (as AssetImageDownloader does, so AssetSaved already sees it).
 */
class GeneratedImageStore
{
    public function __construct(
        private AssetImageDownloader $downloader,
        private ImageAltTextGenerator $altText,
    ) {}

    /**
     * @return array{container: string, path: string, public_url: ?string}|null Null when the site has no asset container.
     */
    public function save(GeneratedImage $image, ?string $containerHandle, string $prompt, string $altHint = ''): ?array
    {
        $container = $this->downloader->resolveContainer($containerHandle);
        if (! $container) {
            return null;
        }

        $path = $this->folder().'/'.$this->filename($image, $prompt);
        $asset = $container->makeAsset($path);
        $asset->set('ai_generated', true);
        $asset->set('ai_prompt', Str::limit($prompt, 1000, ''));

        $alt = $this->altText->forImageBytes($image->bytes, $image->mediaType, trim($altHint.' '.$prompt));
        if ($alt !== null) {
            $asset->set('alt', $alt);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ai-assistant-');
        if ($tmp === false) {
            throw new \RuntimeException(__('The generated image could not be saved.'));
        }

        try {
            file_put_contents($tmp, $image->bytes);
            $asset->upload(new UploadedFile($tmp, basename($path), $image->mediaType, null, true));
        } finally {
            @unlink($tmp);
        }

        return ['container' => $container->handle(), 'path' => $path, 'public_url' => AssetUrl::relative($asset)];
    }

    private function folder(): string
    {
        $folder = trim((string) config('statamic-ai-assistant.image_generation.folder', 'ai-generated'), '/');

        return $folder !== '' ? $folder : 'ai-generated';
    }

    /** "{first words of the prompt}-{8 chars of the image hash}.{ext}" */
    private function filename(GeneratedImage $image, string $prompt): string
    {
        $words = implode(' ', array_slice(preg_split('/\s+/u', trim($prompt)) ?: [], 0, 8));
        $slug = Str::slug($words) ?: 'image';

        return Str::limit($slug, 60, '').'-'.substr(md5($image->bytes), 0, 8).'.'.$image->extension();
    }
}
