<?php

namespace MotionWork\StatamicAiAssistant\Services;

/**
 * How the AI agent groups Statamic fieldtypes when it builds the field schema
 * for the model and maps the model's values back.
 */
final class FieldTypes
{
    public const TEXT_TYPES = ['text', 'textarea', 'ai_text', 'ai_textarea'];

    public const HTML_TYPES = ['bard'];

    public const CHOICE_TYPES = ['select', 'button_group', 'radio', 'checkboxes'];

    public const BOOLEAN_TYPES = ['toggle'];

    public const DATE_TYPES = ['date'];

    public const SKIP_TYPES = ['assets', 'section', 'color'];

    public const GROUP_TYPES = ['group'];

    public const LINK_TYPES = ['link'];

    public const RECURSIVE_TYPES = ['replicator', 'grid', 'components'];
}
