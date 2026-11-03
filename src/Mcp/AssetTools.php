<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use MotionWork\StatamicAiAssistant\Mcp\Concerns\ResolvesViewableAssetContainer;
use MotionWork\StatamicAiAssistant\Support\AssetUrl;
use Statamic\Contracts\Entries\Entry;

/**
 * The asset library: listing the files the account may view, and the upload
 * link for an image the user has in the chat.
 */
final class AssetTools
{
    use ResolvesViewableAssetContainer;

    public function __construct(
        private ConnectorContext $context,
        private ImageUploads $images,
    ) {}

    /**
     * The files and subfolders of one folder in the asset library, or with a
     * query, the files below it whose name contains it. Needs the role's
     * "view {container} assets" permission, as browsing in the Control Panel.
     *
     * @return array<string, mixed>
     */
    public function listAssets(?string $containerHandle, ?string $folder, ?string $query): array
    {
        $container = $this->viewableAssetContainer($containerHandle);
        if (is_array($container)) {
            return $container;
        }

        $folder = trim((string) $folder, '/');
        $needle = mb_strtolower(trim((string) $query));
        $assets = $container->assets($folder === '' ? '/' : $folder, $needle !== '');
        if ($needle !== '') {
            $assets = $assets->filter(fn ($asset) => str_contains(mb_strtolower($asset->basename()), $needle));
        }
        $assets = $assets->sortBy(fn ($asset) => $asset->path())->values();

        $urlPrefix = $this->sharedUrlPrefix($assets->first());

        $limit = 100;
        $files = $assets->take($limit)->map(fn ($asset) => $this->assetRow($asset, $urlPrefix))->all();

        return array_filter([
            'container' => $container->handle(),
            'folder' => $folder,
            'url_prefix' => $urlPrefix,
            'folders' => $needle === '' ? $container->folders($folder === '' ? '/' : $folder)->values()->all() : null,
            'files' => $files,
            'total_files' => $assets->count(),
            'message' => $assets->count() > $limit ? 'Only the first '.$limit.' files are listed. Open a subfolder or pass a query to narrow it down.' : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Every URL is the container's URL plus the path: say it once. Null when
     * the container has no public URL.
     */
    private function sharedUrlPrefix(mixed $first): ?string
    {
        $firstUrl = $first ? AssetUrl::absolute($first) : null;

        return $firstUrl !== null && str_ends_with($firstUrl, $first->path()) ? substr($firstUrl, 0, -strlen($first->path())) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function assetRow(mixed $asset, ?string $urlPrefix): array
    {
        $image = $asset->isImage() || $asset->isSvg();
        $alt = $image ? $asset->get('alt') : null;

        return array_filter([
            'path' => $asset->path(),
            'type' => match (true) {
                $image => 'image',
                $asset->isVideo() => 'video',
                default => $asset->extension(),
            },
            'kb' => (int) ceil($asset->size() / 1024),
            'alt' => $alt ?: null,
            'url' => $urlPrefix === null ? AssetUrl::absolute($asset) : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * An upload link for one image the user has in the chat. The image is
     * stored in the asset library only; placing it on a page is a normal
     * entry edit with its own preview and confirmation.
     *
     * @return array<string, mixed>
     */
    public function prepareImageUpload(?string $container, ?string $folder, ?string $filename, ?string $alt): array
    {
        if ($this->context->actor() === null) {
            return ['error' => 'not_signed_in', 'message' => 'Uploading needs a signed-in Control Panel account.'];
        }

        $prepared = $this->images->prepare($this->context->actor(), $container, $folder, $filename, $alt);
        if (isset($prepared['error'])) {
            return $prepared;
        }

        return $prepared + [
            'max_megabytes' => round($this->images->maxBytes() / 1048576, 1),
            'message' => 'A chat attachment cannot be passed to this tool. First try to upload it yourself: if you can run code, the attachment is usually a file in /mnt/user-data/uploads; post it with curl -F "file=@<path>" '.$prepared['upload_url'].' and read the JSON reply. Only if you cannot run code, the file is not there, or the request is blocked (the site\'s domain must be allowed under Settings, Capabilities, Code execution), give the user upload_url as a link: they open it, drop the image, and come back. Either way, call check_image_upload with upload_id for the stored path. The link takes one image and expires in '.$prepared['expires_in_minutes'].' minutes.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkImageUpload(string $uploadId): array
    {
        if ($this->context->actor() === null) {
            return ['error' => 'not_signed_in', 'message' => 'Uploading needs a signed-in Control Panel account.'];
        }

        return $this->images->status($this->context->actor(), trim($uploadId));
    }
}
