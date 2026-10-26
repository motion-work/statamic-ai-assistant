<template>
  <Stack :open="open" inset :show-close-button="false" @update:open="$emit('update:open', $event)">
    <div class="h-full">
      <div class="flex h-full min-h-0 flex-col">
        <div class="flex flex-1 flex-col gap-4 overflow-auto p-4">
          <!-- Statamic's native CP asset browser (globally registered component):
               folder navigation, search, grid/table, pagination — all built in. -->
          <asset-browser
            :key="container.id"
            :container="container"
            :initial-columns="columns"
            :selected-path="path"
            :selected-assets="selections"
            :allow-bulk-actions="false"
            allow-selecting-existing-upload
            :autoselect-uploads="true"
            @selections-updated="selections = $event"
            @navigated="path = $event"
          >
            <template #header="{ canUpload, openFileBrowser, mode, modeChanged }">
              <div class="flex items-center gap-2 sm:gap-3 mb-4">
                <div class="flex flex-1 items-center gap-2 sm:gap-3">
                  <ListingSearch />
                </div>
                <Button v-if="canUpload" :text="__('Upload')" icon="upload" @click="openFileBrowser" />
                <ToggleGroup :model-value="mode" @update:model-value="modeChanged">
                  <ToggleItem icon="layout-grid" value="grid" />
                  <ToggleItem icon="layout-list" value="table" />
                </ToggleGroup>
              </div>
            </template>
          </asset-browser>
        </div>

        <div class="flex items-center justify-between border-t bg-gray-100 dark:bg-gray-850 dark:border-gray-700 px-4 py-2 sm:p-4">
          <div class="dark:text-gray-200 text-sm text-gray-700">
            {{ __n(':count asset selected|:count assets selected', selections.length) }}
          </div>

          <div class="flex items-center space-x-3">
            <Button variant="ghost" :text="__('Cancel')" @click="$emit('update:open', false)" />
            <Button
              v-if="path"
              :text="__('Reference folder')"
              @click="insertFolder"
            />
            <Button
              variant="primary"
              :disabled="selections.length === 0"
              :text="__n('Reference :count asset|Reference :count assets', selections.length)"
              @click="insertAssets"
            />
          </div>
        </div>
      </div>
    </div>
  </Stack>
</template>

<script>
import { Button, Stack, ListingSearch, ToggleGroup, ToggleItem } from '@statamic/cms/ui';

/**
 * Thin chat-flavored port of Statamic's core asset Selector (not exported by
 * @statamic/cms): the native asset-browser inside a Stack, with the "Select"
 * action replaced by inserting "@asset:" / "@folder:" references into the chat.
 */
export default {
  name: 'AgentAssetSelectorStack',

  components: { Button, Stack, ListingSearch, ToggleGroup, ToggleItem },

  props: {
    open: { type: Boolean, default: false },
    /** Container payload in the fieldtype-preload shape (from /asset-browser). */
    container: { type: Object, required: true },
    columns: { type: Array, default: () => [] },
    /** Folder path to open at ('' = container root). */
    folder: { type: String, default: '' },
  },

  emits: ['update:open', 'insert'],

  data() {
    return {
      selections: [],
      path: this.folder,
    };
  },

  watch: {
    open(now) {
      // Fresh state per opening; keep the requested start folder.
      if (now) {
        this.selections = [];
        this.path = this.folder;
      }
    },
    folder(now) {
      this.path = now;
    },
  },

  methods: {
    /** Selections are asset ids ("container::path") — exactly our ref format. */
    insertAssets() {
      if (!this.selections.length) return;
      this.$emit('insert', this.selections.map((id) => `asset:${id}`));
      this.$emit('update:open', false);
    },

    insertFolder() {
      if (!this.path) return;
      this.$emit('insert', [`folder:${this.container.id}::${this.path}`]);
      this.$emit('update:open', false);
    },
  },
};
</script>
