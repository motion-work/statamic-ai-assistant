<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

/**
 * Alt-text workflow for the Claude connector: show image batches to the model,
 * then preview and save metadata it writes (no Infomaniak / DeepL).
 */
final class AssetAltTools
{
    public function __construct(
        private AssetAltReview $review,
        private AssetAltConfirmation $confirmation,
    ) {}

    /**
     * @param  array<int, string>|null  $locales
     * @return array<string, mixed>
     */
    public function reviewImages(?string $containerHandle, ?string $folder, ?array $locales, ?string $scope, ?string $cursor): array
    {
        return $this->review->review($containerHandle, $folder, $locales, $scope, $cursor);
    }

    /**
     * @param  array<int, array<string, mixed>>  $updates
     * @return array<string, mixed>
     */
    public function previewAssetAlts(array $updates): array
    {
        return $this->confirmation->preview($updates);
    }

    /**
     * @return array<string, mixed>
     */
    public function saveAssetAlts(string $token, bool $confirmed): array
    {
        return $this->confirmation->save($token, $confirmed);
    }
}
