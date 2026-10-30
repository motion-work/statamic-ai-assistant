<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use MotionWork\StatamicAiAssistant\Support\ClaudeAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Statamic\Assets\AssetUploader;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\User;
use Statamic\Fields\Validator as FieldValidator;
use Statamic\Rules\AllowedFile;

/**
 * Images for the Claude connector. Claude cannot hand a chat attachment to a
 * tool, so a tool call opens an upload link for one image; the user (or
 * Claude's own sandbox) posts the file there, and Claude places the stored
 * path with a normal entry edit.
 *
 * The link is the credential: random, single-use, bound to the account that
 * asked for it, valid for 30 minutes. The account's "upload {container}
 * assets" permission is checked when the link is made and again on upload.
 */
class ImageUploads
{
    private const TTL_MINUTES = 30;

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];

    /**
     * @return array<string, mixed>
     */
    public function prepare(UserContract $actor, ?string $containerHandle, ?string $folder, ?string $filename, ?string $alt): array
    {
        $writable = AssetContainer::all()
            ->filter(fn ($container) => $actor->can('store', [AssetContract::class, $container]))
            ->map(fn ($container) => $container->handle())
            ->values()
            ->all();

        if ($containerHandle === null || $containerHandle === '') {
            if (count($writable) !== 1) {
                return [
                    'error' => $writable === [] ? 'forbidden' : 'missing_container',
                    'containers' => $writable,
                    'message' => $writable === []
                        ? 'Your Control Panel role cannot upload assets. Nothing was prepared.'
                        : 'Pass the container to upload into: the container of the asset segment (from preview_entry_edit), or one of these.',
                ];
            }
            $containerHandle = $writable[0];
        }

        $container = AssetContainer::find($containerHandle);
        if (! $container) {
            return ['error' => 'missing_container', 'containers' => $writable, 'message' => 'No asset container has the handle '.$containerHandle.'.'];
        }
        if (! in_array($container->handle(), $writable, true)) {
            return ['error' => 'forbidden', 'message' => 'Your Control Panel role cannot upload to the '.$container->handle().' assets. Nothing was prepared.'];
        }

        $id = Str::random(40);
        $record = [
            'user_id' => (string) $actor->id(),
            'container' => $container->handle(),
            'folder' => AssetUploader::getSafePath((string) $folder),
            'filename' => is_string($filename) && trim($filename) !== '' ? pathinfo(trim($filename), PATHINFO_FILENAME) : null,
            'alt' => is_string($alt) && trim($alt) !== '' ? trim($alt) : null,
            'path' => null,
        ];
        Cache::put($this->key($id), $record, now()->addMinutes(self::TTL_MINUTES));

        return [
            'upload_id' => $id,
            'upload_url' => $this->url($id),
            'container' => $record['container'],
            'folder' => $record['folder'],
            'expires_in_minutes' => self::TTL_MINUTES,
        ];
    }

    /**
     * The open upload, or null when the link is unknown or expired.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $record = $id === '' ? null : Cache::get($this->key($id));

        return is_array($record) ? $record : null;
    }

    /**
     * Store the posted image as an asset, as the account that made the link.
     * An existing file is never overwritten: the name gets a number instead.
     *
     * @return array<string, mixed>
     */
    public function receive(string $id, ?UploadedFile $file): array
    {
        $record = $this->find($id);
        if ($record === null) {
            return ['error' => 'expired', 'message' => 'This upload link is unknown or has expired. Ask Claude for a new one.'];
        }
        if ($record['path'] !== null) {
            return ['error' => 'used', 'message' => 'This link has already been used for '.$record['path'].'. Ask Claude for a new one.'];
        }

        $user = User::find($record['user_id']);
        $container = AssetContainer::find($record['container']);
        if (! $user || ! $container || ! $user->can('store', [AssetContract::class, $container]) || ! ClaudeAccess::allows($user, 'edit')) {
            return ['error' => 'forbidden', 'message' => 'This account can no longer upload to the '.$record['container'].' assets.'];
        }

        $rules = collect($container->validationRules())->map(fn ($rule) => FieldValidator::parse($rule))->all();
        $validator = Validator::make(['file' => $file], [
            'file' => array_merge(
                ['required', 'file', new AllowedFile, 'mimes:'.implode(',', self::IMAGE_EXTENSIONS), 'max:'.(int) floor($this->maxBytes() / 1024)],
                $rules,
            ),
        ]);
        if ($validator->fails()) {
            return ['error' => 'invalid_file', 'message' => implode(' ', $validator->errors()->all())];
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());
        $name = $record['filename'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $path = $this->freePath($container, $record['folder'], AssetUploader::getSafeFilename(($name !== '' ? $name : 'image').'.'.$extension));

        // Asset events (git automation, image presets) see the account.
        auth()->setUser($user);
        $asset = $container->makeAsset($path);
        if ($record['alt'] !== null) {
            $asset->set('alt', $record['alt']);
        }
        $asset->upload($file);
        // The uploader has the last word on the name (lowercasing, a source preset's format).
        $path = $asset->path();

        $record['path'] = $path;
        Cache::put($this->key($id), $record, now()->addMinutes(self::TTL_MINUTES));

        return ['uploaded' => true, 'container' => $container->handle(), 'path' => $path, 'url' => $this->publicUrl($asset)];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(UserContract $actor, string $id): array
    {
        $record = $this->find($id);
        if ($record === null || $record['user_id'] !== (string) $actor->id()) {
            return ['error' => 'expired', 'message' => 'This upload is unknown or has expired. Call prepare_image_upload again.'];
        }
        if ($record['path'] === null) {
            return [
                'uploaded' => false,
                'upload_url' => $this->url($id),
                'message' => 'No image has arrived yet. Ask the user to open the link, drop the image there, and tell you when it is done.',
            ];
        }

        $asset = AssetContainer::find($record['container'])?->asset($record['path']);

        return [
            'uploaded' => true,
            'container' => $record['container'],
            'path' => $record['path'],
            'url' => $asset ? $this->publicUrl($asset) : null,
            'alt' => $record['alt'],
            'message' => 'Use the path as the value of the asset segment in preview_entry_edit, or as an assets field\'s value in preview_compose_entry.',
        ];
    }

    public function url(string $id): string
    {
        return rtrim((string) config('app.url'), '/').'/mcp/upload/'.$id;
    }

    public function maxBytes(): int
    {
        return (int) config('statamic-ai-assistant.image_fetch.max_bytes', 10 * 1024 * 1024);
    }

    private function freePath($container, string $folder, string $basename): string
    {
        $prefix = $folder === '' ? '' : $folder.'/';
        $name = pathinfo($basename, PATHINFO_FILENAME);
        $extension = pathinfo($basename, PATHINFO_EXTENSION);
        $path = $prefix.$basename;
        for ($n = 2; $container->disk()->exists($path); $n++) {
            $path = $prefix.$name.'-'.$n.'.'.$extension;
        }

        return $path;
    }

    private function publicUrl(AssetContract $asset): ?string
    {
        try {
            return $asset->absoluteUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    private function key(string $id): string
    {
        return 'statamic-ai-assistant:mcp-upload:'.$id;
    }
}
