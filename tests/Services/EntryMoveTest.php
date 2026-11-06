<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\EntryMover;
use MotionWork\StatamicAiAssistant\Support\EntryTreePlacement;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Tools\MoveEntryTool;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Structures\CollectionStructure;

/**
 * move_entry: an entry gets a new parent / position in its collection tree.
 * Groups: "group" (with child "child"), "other" (empty), "loose" (top level).
 */
class EntryMoveTest extends TestCase
{
    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::default()->handle();

        $faq = Collection::make('faq_tree')->title('FAQ')->sites([$this->site]);
        $faq->structure((new CollectionStructure)->maxDepth(2))->save();
        Collection::make('flat_notes')->title('Notes')->sites([$this->site])->save();

        foreach (['group', 'child', 'other', 'loose'] as $id) {
            Entry::make()->id($id)->collection('faq_tree')->locale($this->site)->slug($id)->data(['title' => ucfirst($id)])->saveQuietly();
        }
        Entry::make()->id('note')->collection('flat_notes')->locale($this->site)->slug('note')->data(['title' => 'Note'])->saveQuietly();

        $this->tree()->tree([
            ['entry' => 'group', 'children' => [['entry' => 'child']]],
            ['entry' => 'other'],
            ['entry' => 'loose'],
        ])->save();

        config(['statamic.editions.pro' => true]);
        User::make()->id('move-super')->email('move-super@example.com')->makeSuper()->save();
        Role::make('faq_editor')->title('FAQ editor')->permissions(['access cp', 'view faq_tree entries', 'edit faq_tree entries'])->save();
        User::make()->id('move-editor')->email('move-editor@example.com')->assignRole('faq_editor')->save();
    }

    protected function tearDown(): void
    {
        foreach (['move-super', 'move-editor'] as $id) {
            User::find($id)?->delete();
        }
        Role::find('faq_editor')?->delete();
        foreach (['group', 'child', 'other', 'loose', 'note'] as $id) {
            Entry::find($id)?->delete();
        }
        Collection::find('faq_tree')?->delete();
        Collection::find('flat_notes')?->delete();
        parent::tearDown();
    }

    public function test_an_entry_moves_under_a_new_parent_and_the_tree_is_saved(): void
    {
        $result = (new EntryMover)->move('loose', 'other', null, User::find('move-super'));

        $this->assertTrue($result['ok']);
        $this->assertSame('other', EntryTreePlacement::parentMap($this->freshTree()->tree())['loose']);
        $this->assertStringContainsString('Loose', $result['label']);
        $this->assertStringContainsString('Other', $result['label']);
    }

    public function test_it_can_be_placed_after_a_sibling_and_moved_to_the_top_level(): void
    {
        $mover = new EntryMover;
        $super = User::find('move-super');

        $this->assertTrue($mover->move('loose', 'group', 'child', $super)['ok']);
        $this->assertSame(['child', 'loose'], array_column($this->freshTree()->tree()[0]['children'], 'entry'));

        $this->assertTrue($mover->move('child', null, 'group', $super)['ok']);
        $this->assertSame(['group', 'child', 'other'], array_column($this->freshTree()->tree(), 'entry'));
    }

    public function test_invalid_moves_are_refused_with_a_reason(): void
    {
        $mover = new EntryMover;
        $super = User::find('move-super');

        $this->assertSame('entry_not_found', $mover->move('missing', 'group', null, $super)['error']);
        $this->assertSame('not_structured', $mover->move('note', null, null, $super)['error']);
        $this->assertSame('parent_not_found', $mover->move('loose', 'missing', null, $super)['error']);
        $this->assertSame('parent_elsewhere', $mover->move('loose', 'note', null, $super)['error']);
        $this->assertSame('parent_is_descendant', $mover->move('group', 'child', null, $super)['error']);
        $this->assertSame('parent_is_descendant', $mover->move('group', 'group', null, $super)['error']);
        $this->assertSame('after_not_sibling', $mover->move('loose', 'group', 'other', $super)['error']);
        $this->assertSame('too_deep', $mover->move('group', 'other', null, $super)['error'], 'group + its child under "other" needs 3 levels');
        $this->assertNull(EntryTreePlacement::parentMap($this->freshTree()->tree())['loose']);
    }

    public function test_the_root_page_stays_fixed(): void
    {
        $this->tree()->structure()->expectsRoot(true)->save();

        $mover = new EntryMover;
        $this->assertSame('root_fixed', $mover->move('group', 'other', null, User::find('move-super'))['error']);
        $this->assertSame('root_no_children', $mover->move('loose', 'group', null, User::find('move-super'))['error']);
    }

    public function test_it_needs_edit_and_reorder_rights(): void
    {
        $mover = new EntryMover;

        $this->assertSame('not_allowed', $mover->move('loose', 'other', null, User::find('move-editor'))['error'], 'edit without reorder');
        $this->assertSame('not_allowed', $mover->move('loose', 'other', null, null)['error'], 'no user');

        Role::find('faq_editor')->addPermission('reorder faq_tree entries')->save();
        $this->assertTrue($mover->move('loose', 'other', null, User::find('move-editor'))['ok']);
    }

    public function test_the_tool_acts_for_the_session_owner_and_is_offered_only_with_trees(): void
    {
        $this->assertTrue(MoveEntryTool::isUseful());

        $tool = new MoveEntryTool(new EntryMover, fn () => 'move-super');
        $result = $tool->handle(json_encode(['entry_id' => 'loose', 'parent_id' => 'other']), new ToolContext);
        $this->assertTrue($result['ok']);

        $this->assertSame('entry_id_required', $tool->handle('{"parent_id":""}', new ToolContext)['error']);

        Collection::find('faq_tree')->structure(null)->save();
        $this->assertFalse(MoveEntryTool::isUseful());
    }

    private function tree()
    {
        return Collection::find('faq_tree')->structure()->in($this->site);
    }

    private function freshTree()
    {
        Blink::flush();

        return Collection::find('faq_tree')->structure()->in($this->site);
    }
}
