<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

/**
 * Alt-text copy rules for the connected model (Claude). Repeated in review_images
 * batches so the model follows them without the CMS calling its own LLM.
 */
final class AssetAltBrief
{
    public const TEXT = <<<'BRIEF'
Alt text rules (you write the strings; the CMS only saves what you send):
- Decorative images (spacer, border, or redundant with adjacent heading text): use an empty alt and say why in your summary to the user.
- Otherwise describe what a screen-reader user needs: logos/signs → visible text; charts → one sentence of what they show, not the data.
- One native sentence per language, same facts — no calques, no "image of" / "Bild von" / "photo de". About 100–125 characters. No filename, no camera jargon, no keyword stuffing.
- Do not invent people, brands, or words not visible. If the picture is unclear, skip that asset and explain.
- Keep glossary product and brand names as in read_glossary.
BRIEF;
}
