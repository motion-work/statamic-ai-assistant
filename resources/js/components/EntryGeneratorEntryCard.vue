<template>
  <div
    class="eg-entry-card"
    :class="[
      `eg-entry-card--${entry.status}`,
      { 'eg-entry-card--expanded': isExpanded },
    ]"
  >
    <header class="eg-entry-card__head">
      <div class="eg-entry-card__head-main">
        <span class="eg-entry-card__index">{{ index + 1 }}</span>
        <div class="eg-entry-card__head-col">
          <div class="eg-entry-card__head-text">
            <p class="eg-entry-card__label">{{ displayLabel }}</p>
            <p class="eg-entry-card__target">
              <span
                v-if="isUpdate"
                class="eg-entry-card__badge eg-entry-card__badge--update"
                :title="__('Updating an existing entry')"
              >{{ __('Update') }}</span>
              <span class="eg-entry-card__badge">{{ entry.collectionTitle || entry.collection }}</span>
              <template v-if="entry.blueprintTitle && entry.blueprintTitle !== entry.collectionTitle">
                <span class="eg-entry-card__badge-sep">·</span>
                <span class="eg-entry-card__badge-bp">{{ entry.blueprintTitle }}</span>
              </template>
            </p>
          </div>
          <div
            v-if="cardActivityLines.length"
            ref="entryActivityScroll"
            class="eg-entry-card__activity"
          >
            <transition-group name="eg-activity" tag="ul" class="eg-entry-card__activity-list">
              <li
                v-for="row in cardActivityLines"
                :key="row.id"
                class="eg-entry-card__activity-line"
                v-html="chatHtml(row.text)"
              ></li>
            </transition-group>
          </div>
        </div>
      </div>
      <div class="eg-entry-card__status">
        <span
          class="eg-entry-card__status-dot"
          :class="`eg-entry-card__status-dot--${entry.status}`"
          aria-hidden="true"
        />
        <span class="eg-entry-card__status-label">{{ statusLabel }}</span>
      </div>
    </header>

    <!-- Per-card progress while drafting -->
    <div
      v-if="entry.status === 'drafting'"
      class="eg-entry-card__progress"
      role="progressbar"
      :aria-valuenow="progressPercent"
      aria-valuemin="0"
      aria-valuemax="100"
    >
      <div class="eg-entry-card__progress-fill" :style="{ width: progressPercent + '%' }" />
    </div>

    <!-- Error message -->
    <div v-if="entry.status === 'failed'" class="eg-entry-card__error">
      <p v-html="chatHtml(entry.error || __('Generation failed.'))"></p>
    </div>

    <!-- Saved confirmation -->
    <div v-if="entry.status === 'saved' && entry.savedEntry" class="eg-entry-card__saved">
      <span class="eg-entry-card__saved-icon" aria-hidden="true">
        <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14">
          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
        </svg>
      </span>
      <span class="eg-entry-card__saved-text">{{ isUpdate ? __('Update applied.') : __('Saved as draft.') }}</span>
      <a
        v-if="!navigateAwayWouldAbortBatch"
        :href="entry.savedEntry.edit_url"
        class="eg-entry-card__saved-link"
      >{{ __('Edit') }} →</a>
      <span
        v-else
        class="eg-entry-card__saved-link eg-entry-card__saved-link--blocked"
        :title="__('Edit becomes available after every entry has finished generating.')"
      >{{ __('Edit') }} →</span>
    </div>

    <!-- Warnings (collapsed unless preview is expanded or there are no other actions) -->
    <div v-if="entry.warnings && entry.warnings.length && (isExpanded || entry.status === 'ready')" class="eg-entry-card__warnings">
      <p class="eg-entry-card__warnings-title">{{ __('Notes') }}</p>
      <ul>
        <li v-for="(w, i) in entry.warnings" :key="i" v-html="chatHtml(w)"></li>
      </ul>
    </div>

    <!-- Preview body -->
    <div v-if="canShowPreview" class="eg-entry-card__preview-wrap">
      <button
        type="button"
        class="eg-entry-card__toggle"
        :aria-expanded="isExpanded ? 'true' : 'false'"
        @click="toggleExpand"
      >
        <span>{{ isExpanded ? __('Hide preview') : __('Show preview') }}</span>
        <svg
          class="eg-entry-card__chevron"
          :class="{ 'eg-entry-card__chevron--open': isExpanded }"
          viewBox="0 0 20 20"
          fill="currentColor"
          width="12"
          height="12"
          aria-hidden="true"
        >
          <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.24 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
        </svg>
      </button>
      <div v-if="isExpanded" class="eg-entry-card__preview">
        <EntryGeneratorContentPreview
          variant="chat"
          :field-preview="entry.fieldPreview"
          :display-data="entry.displayData"
        />
      </div>
    </div>

    <!-- No changes notice for empty updates -->
    <div v-if="entry.status === 'ready' && hasNoChanges" class="eg-entry-card__error">
      <p>{{ isUpdate ? __('The AI did not produce any field changes. Try a more specific prompt.') : __('No content to save.') }}</p>
    </div>

    <!-- Actions -->
    <div v-if="hasActions" class="eg-entry-card__actions">
      <template v-if="entry.status === 'ready'">
        <button type="button" class="eg-entry-card__btn eg-entry-card__btn--ghost" :disabled="busy" @click="$emit('discard')">
          {{ __('Discard') }}
        </button>
        <button
          v-if="!isUpdate"
          type="button"
          class="eg-entry-card__btn eg-entry-card__btn--ghost"
          :disabled="busy"
          @click="$emit('retry')"
        >
          {{ __('Retry') }}
        </button>
        <button
          v-if="!hasNoChanges"
          type="button"
          class="eg-entry-card__btn eg-entry-card__btn--default"
          :disabled="busy"
          @click="$emit('save', 'draft')"
        >
          {{ savingDraft ? __('Saving…') : (isUpdate ? __('Apply update') : __('Save as draft')) }}
        </button>
        <button
          v-if="!hasNoChanges && !navigateAwayWouldAbortBatch"
          type="button"
          class="eg-entry-card__btn eg-entry-card__btn--primary"
          :disabled="busy"
          @click="$emit('save', 'edit')"
        >
          {{ savingEdit ? __('Saving…') : (isUpdate ? __('Apply & edit') : __('Save & edit')) }}
        </button>
      </template>
      <template v-else-if="entry.status === 'failed'">
        <button type="button" class="eg-entry-card__btn eg-entry-card__btn--ghost" @click="$emit('discard')">
          {{ __('Discard') }}
        </button>
        <button type="button" class="eg-entry-card__btn eg-entry-card__btn--primary" @click="$emit('retry')">
          {{ __('Try again') }}
        </button>
      </template>
    </div>

    <p
      v-if="entry.status === 'ready' && navigateAwayWouldAbortBatch"
      class="eg-entry-card__batch-hint"
    >
      {{ __('Save & edit is available after every entry has finished generating.') }}
    </p>
  </div>
</template>

<script>
import EntryGeneratorContentPreview from './EntryGeneratorContentPreview.vue';
import { state as entryGenState, STATUS } from '../store/entryGeneratorStore.js';
import { formatChatTextWithBoldUrls } from '../formatChatUrls.js';

export default {
  name: 'EntryGeneratorEntryCard',
  components: { EntryGeneratorContentPreview },

  props: {
    entry: { type: Object, required: true },
    index: { type: Number, required: true },
    autoExpand: { type: Boolean, default: false },
  },

  emits: ['save', 'retry', 'discard'],

  data() {
    return {
      manualExpanded: null, // null = follow autoExpand; true/false = user override
    };
  },

  computed: {
    isExpanded() {
      if (this.manualExpanded !== null) return this.manualExpanded;
      return this.autoExpand && ['ready', 'saved'].includes(this.entry.status);
    },

    canShowPreview() {
      return ['ready', 'saved'].includes(this.entry.status) && this.entry.displayData;
    },

    hasActions() {
      return ['ready', 'failed'].includes(this.entry.status);
    },

    busy() {
      return this.entry.status === 'saving';
    },

    savingDraft() {
      return this.busy && this.entry.savingMode === 'draft';
    },

    savingEdit() {
      return this.busy && this.entry.savingMode === 'edit';
    },

    isUpdate() {
      return this.entry.operation === 'update';
    },

    hasNoChanges() {
      const d = this.entry.data;
      return !d || (typeof d === 'object' && Object.keys(d).length === 0);
    },

    statusLabel() {
      switch (this.entry.status) {
        case 'queued': return this.__('Waiting…');
        case 'drafting': return this.isUpdate ? this.__('Updating…') : this.__('Drafting…');
        case 'ready': return this.__('Ready to review');
        case 'saving': return this.__('Saving…');
        case 'saved': return this.isUpdate ? this.__('Updated') : this.__('Saved');
        case 'failed': return this.__('Failed');
        default: return '';
      }
    },

    displayLabel() {
      return this.entry.label || this.__('Untitled entry');
    },

    progressPercent() {
      const len = this.entry.tokenLength || 0;
      // Soft logarithmic curve, capped at 92% — completes via status change to 'ready'.
      return Math.min(92, 12 + Math.floor(len / 60));
    },

    cardActivityLines() {
      return entryGenState.activityLog.filter((row) => row.entryId === this.entry.id);
    },

    /**
     * Opening the CP editor navigates away and aborts the NDJSON stream — hide
     * navigation-style actions while any sibling entry is still generating.
     */
    navigateAwayWouldAbortBatch() {
      const s = entryGenState;
      if (!s.generating || s.entries.length < 2) return false;
      return s.entries.some((e) => [STATUS.QUEUED, STATUS.DRAFTING, STATUS.SAVING].includes(e.status));
    },
  },

  watch: {
    cardActivityLines: {
      handler() {
        this.$nextTick(() => {
          const el = this.$refs.entryActivityScroll;
          if (el) el.scrollTop = el.scrollHeight;
        });
      },
      deep: true,
    },
  },

  methods: {
    chatHtml(text) {
      return formatChatTextWithBoldUrls(text);
    },

    toggleExpand() {
      this.manualExpanded = !this.isExpanded;
    },
  },
};
</script>
