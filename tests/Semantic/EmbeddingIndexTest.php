<?php

namespace MotionWork\StatamicAiAssistant\Tests\Semantic;

use MotionWork\StatamicAiAssistant\Semantic\EmbeddingIndex;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

class EmbeddingIndexTest extends TestCase
{
    use FakesEmbeddings;

    private EmbeddingIndex $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useSemanticSearch();
        $this->index = new EmbeddingIndex('default');
    }

    protected function tearDown(): void
    {
        $this->tearDownSemanticSearch();
        parent::tearDown();
    }

    public function test_vectors_are_written_and_found_by_similarity(): void
    {
        $this->index->upsert([
            'care' => ['collection' => 'pages', 'hash' => 'h1', 'vector' => self::topicVector('Pflege von Holz')],
            'warranty' => ['collection' => 'pages', 'hash' => 'h2', 'vector' => self::topicVector('Garantie')],
            'payment' => ['collection' => 'faq', 'hash' => 'h3', 'vector' => self::topicVector('Zahlung')],
        ], 'model-a', self::DIMENSIONS);

        $hits = $this->index->nearest(self::topicVector('Möbel pflegen'), null, 5, 0.3);
        $this->assertSame('care', $hits[0]['id']);
        $this->assertCount(1, $hits, 'Below min similarity is dropped.');
        $this->assertEqualsWithDelta(1.0, $hits[0]['score'], 0.01);

        $this->assertSame([], $this->index->nearest(self::topicVector('Möbel'), 'faq', 5, 0.3), 'Collection filter.');
        $this->assertSame(['care' => 'h1', 'warranty' => 'h2', 'payment' => 'h3'], $this->index->hashes('model-a', self::DIMENSIONS));
    }

    public function test_upsert_replaces_and_remove_deletes_rows(): void
    {
        $this->index->upsert(['a' => ['collection' => 'pages', 'hash' => 'h1', 'vector' => self::topicVector('Garantie')]], 'model-a', self::DIMENSIONS);
        $this->index->upsert(['a' => ['collection' => 'pages', 'hash' => 'h2', 'vector' => self::topicVector('Pflege')], 'b' => ['collection' => 'pages', 'hash' => 'h3', 'vector' => self::topicVector('Zahlung')]], 'model-a', self::DIMENSIONS);

        $this->assertSame('a', $this->index->nearest(self::topicVector('Pflege'), null, 5, 0.3)[0]['id']);

        $this->index->remove(['a']);
        $this->assertSame(['b' => 'h3'], $this->index->hashes('model-a', self::DIMENSIONS));
        $this->assertSame('b', $this->index->nearest(self::topicVector('Zahlung'), null, 5, 0.3)[0]['id'], 'Remaining rows keep their vectors.');
    }

    public function test_another_model_or_size_counts_as_empty_and_starts_fresh(): void
    {
        $this->index->upsert(['a' => ['collection' => 'pages', 'hash' => 'h1', 'vector' => self::topicVector('Pflege')]], 'model-a', self::DIMENSIONS);

        $this->assertTrue($this->index->matches('model-a', self::DIMENSIONS));
        $this->assertFalse($this->index->matches('model-b', self::DIMENSIONS));
        $this->assertSame([], $this->index->hashes('model-a', 128));

        $this->index->upsert(['b' => ['collection' => 'pages', 'hash' => 'h2', 'vector' => self::topicVector('Pflege')]], 'model-b', self::DIMENSIONS);
        $this->assertSame(['b' => 'h2'], $this->index->hashes('model-b', self::DIMENSIONS), 'Old rows of model-a are gone.');
    }

    public function test_a_damaged_vector_file_is_unreadable_and_clear_removes_the_index(): void
    {
        $this->index->upsert(['a' => ['collection' => 'pages', 'hash' => 'h1', 'vector' => self::topicVector('Pflege')]], 'model-a', self::DIMENSIONS);
        file_put_contents($this->indexPath.'/default/vectors.bin', 'broken');

        try {
            $this->index->nearest(self::topicVector('Pflege'), null, 5, 0.3);
            $this->fail('A vector file that does not match the manifest must not be read.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('do not match', $e->getMessage());
        }

        $this->index->clear();
        $this->assertFalse($this->index->exists());
        $this->assertNull($this->index->manifest());
    }
}
