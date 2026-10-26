<?php

namespace MotionWork\StatamicAiAssistant\Tests\Tools;

use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Tools\ListTaxonomiesTool;
use MotionWork\StatamicAiAssistant\Tools\ReadGlobalsTool;
use MotionWork\StatamicAiAssistant\Tools\ReadNavTreeTool;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

class CmsContextToolsTest extends TestCase
{
    public function test_read_globals_returns_set_values(): void
    {
        $set = GlobalSet::make('contact')->title('Contact');
        $set->save();
        $set->in('default')->data(['phone' => '+41 33 000 00 00', 'email' => 'info@example.com'])->save();

        $result = (new ReadGlobalsTool)->handle('{}', new ToolContext);

        $this->assertTrue($result['ok']);
        $contact = collect($result['globals'])->firstWhere('handle', 'contact');
        $this->assertNotNull($contact);
        $this->assertSame('Contact', $contact['title']);
        $this->assertSame('+41 33 000 00 00', $contact['values']['phone']);
        $this->assertSame('info@example.com', $contact['values']['email']);
    }

    public function test_read_globals_unknown_handle_is_reported(): void
    {
        $result = (new ReadGlobalsTool)->handle('{"handle":"nope"}', new ToolContext);

        $this->assertFalse($result['ok']);
        $this->assertSame('global_set_not_found', $result['error']);
    }

    public function test_list_taxonomies_lists_and_reads_terms(): void
    {
        Taxonomy::make('topics')->title('Topics')->save();
        $term = Term::make()->taxonomy('topics')->slug('news');
        $term->dataForLocale('default', ['title' => 'News']);
        $term->save();

        $tool = new ListTaxonomiesTool;

        $list = $tool->handle('{}', new ToolContext);
        $this->assertTrue($list['ok']);
        $topics = collect($list['taxonomies'])->firstWhere('handle', 'topics');
        $this->assertNotNull($topics);
        $this->assertSame('Topics', $topics['title']);

        $terms = $tool->handle('{"taxonomy":"topics"}', new ToolContext);
        $this->assertTrue($terms['ok']);
        $this->assertContains('news', array_column($terms['terms'], 'slug'));
    }

    public function test_list_taxonomies_unknown_handle_is_reported(): void
    {
        $result = (new ListTaxonomiesTool)->handle('{"taxonomy":"missing"}', new ToolContext);

        $this->assertFalse($result['ok']);
        $this->assertSame('taxonomy_not_found', $result['error']);
    }

    public function test_read_nav_tree_returns_empty_list_when_no_navs(): void
    {
        $result = (new ReadNavTreeTool)->handle('{}', new ToolContext);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['navigations']);
    }
}
