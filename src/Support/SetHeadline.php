<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Illuminate\Support\Str;

/**
 * A block's own title, heading or label, so a location like
 * `Komponenten › tiles 2 "Bankkaution"` names the section a user means.
 */
final class SetHeadline
{
    /**
     * The block's own heading, so a request like "the Bankkaution section"
     * can be matched to its values. Plain text or Bard.
     *
     * @param  array<string, mixed>  $set
     */
    public function setHeadline(array $set): ?string
    {
        foreach (['title', 'heading', 'headline', 'label', 'name'] as $key) {
            $value = $set[$key] ?? null;
            if (is_array($value)) {
                $text = [];
                array_walk_recursive($value, function ($node, $k) use (&$text) {
                    if ($k === 'text' && is_string($node)) {
                        $text[] = $node;
                    }
                });
                $value = implode(' ', $text);
            }
            if (is_string($value)) {
                $value = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00AD}", '', strip_tags($value))));
                if ($value !== '') {
                    return Str::limit($value, 40);
                }
            }
        }

        return null;
    }
}
