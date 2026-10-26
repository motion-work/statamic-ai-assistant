<?php

namespace BoldWeb\StatamicAiAssistant\Fieldtypes;

use Statamic\Fields\Fieldtype;

class TranslationTargetLanguages extends Fieldtype
{
    protected $selectableInForms = true;

    protected $categories = ['special'];

    public function process($data)
    {
        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter($data));
    }
}
