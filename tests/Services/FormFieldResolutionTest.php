<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Form;

class FormFieldResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['contact' => 'Contact Form', 'newsletter' => 'Newsletter'] as $handle => $title) {
            Form::make($handle)->title($title)->save();
        }

        Blueprint::make('page')->setNamespace('collections.pages')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'form', 'field' => ['type' => 'form', 'max_items' => 1]],
                ['handle' => 'forms_many', 'field' => ['type' => 'form', 'max_items' => 3]],
            ]]]]],
        ])->save();
    }

    protected function tearDown(): void
    {
        Form::find('contact')?->delete();
        Form::find('newsletter')?->delete();
        parent::tearDown();
    }

    private function service(): EntryGeneratorService
    {
        return app(EntryGeneratorService::class);
    }

    private function field(string $handle)
    {
        return Blueprint::find('collections.pages.page')->field($handle);
    }

    private function mapForm(string $fieldHandle, mixed $value, array &$warnings): mixed
    {
        $m = new \ReflectionMethod($this->service(), 'mapFormFieldValue');
        $m->setAccessible(true);
        $args = [$value, $this->field($fieldHandle), &$warnings];

        return $m->invokeArgs($this->service(), $args);
    }

    public function test_schema_lists_available_forms_for_the_model(): void
    {
        $m = new \ReflectionMethod($this->service(), 'buildFormFieldSchemaPayload');
        $m->setAccessible(true);
        $payload = $m->invoke($this->service(), $this->field('form'), null);

        $this->assertSame('form_reference', $payload['type']);
        $handles = array_column($payload['forms'], 'handle');
        $this->assertContains('contact', $handles);
        $this->assertContains('newsletter', $handles);
    }

    public function test_resolves_by_handle_and_returns_a_string_for_single(): void
    {
        $warnings = [];
        $this->assertSame('contact', $this->mapForm('form', 'contact', $warnings));
        $this->assertSame([], $warnings);
    }

    public function test_resolves_by_title_ignoring_case_and_punctuation(): void
    {
        $warnings = [];
        // "the contact form" → the form titled "Contact Form" (handle: contact).
        $this->assertSame('contact', $this->mapForm('form', 'Contact Form', $warnings));
        $this->assertSame([], $warnings);
    }

    public function test_unknown_form_warns_and_is_not_silently_dropped(): void
    {
        $warnings = [];
        $this->assertNull($this->mapForm('form', 'booking', $warnings));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('booking', $warnings[0]);
    }

    public function test_multi_form_field_returns_an_array_of_handles(): void
    {
        $warnings = [];
        $result = $this->mapForm('forms_many', ['contact', 'Newsletter'], $warnings);

        $this->assertEqualsCanonicalizing(['contact', 'newsletter'], $result);
        $this->assertSame([], $warnings);
    }
}
