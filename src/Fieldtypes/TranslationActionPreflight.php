<?php

namespace BoldWeb\StatamicAiAssistant\Fieldtypes;

use Statamic\Fields\Fieldtype;

class TranslationActionPreflight extends Fieldtype
{
    protected $selectableInForms = true;

    protected $localizable = false;

    protected $validatable = false;

    protected $categories = ['special'];

    public function process($data)
    {
        return null;
    }

    public function preload()
    {
        return [];
    }
}
