<template>
  <div class="translation-progress">
    <!-- Overall progress -->
    <div class="translation-progress__bar-wrapper">
      <div
        class="translation-progress__bar-track"
        :class="{ 'translation-progress__bar-track--indeterminate': indeterminate }"
      >
        <div
          v-if="indeterminate"
          class="translation-progress__bar-fill translation-progress__bar-fill--indeterminate"
          aria-hidden="true"
        ></div>
        <div
          v-else
          class="translation-progress__bar-fill"
          :style="{ width: progressPercent + '%' }"
          :class="{
            'translation-progress__bar-fill--completed': isCompleted,
            'translation-progress__bar-fill--error': hasFatalError,
          }"
        ></div>
      </div>
      <div class="translation-progress__bar-label">
        <span v-if="isCompleted">{{ __('Translation complete') }}</span>
        <span v-else-if="hasFatalError">{{ __('Translation failed') }}</span>
        <span v-else-if="indeterminate">{{ __('Translating…') }}</span>
        <span v-else-if="progress && progress.current_entry">
          {{ __('Translating') }}: {{ progress.current_entry }}
        </span>
        <span v-else>{{ __('Starting...') }}</span>
        <span class="translation-progress__percent">{{ percentLabel }}</span>
      </div>
    </div>

    <!-- Failed state (HTTP error, batch dispatch, or batch catch) -->
    <div v-if="hasFatalError" class="translation-progress__fatal" role="alert">
      <h4 class="translation-progress__fatal-title">{{ __('Translation failed') }}</h4>
      <ul v-if="fatalMessages.length" class="translation-progress__fatal-list">
        <li v-for="(line, idx) in fatalMessages" :key="idx">{{ line }}</li>
      </ul>
    </div>

    <!-- Summary card (shown on completion) -->
    <div v-if="isCompleted" class="translation-progress__summary">
      <div class="translation-progress__summary-grid">
        <div class="translation-progress__stat translation-progress__stat--success">
          <span class="translation-progress__stat-number">{{ progress.translated || 0 }}</span>
          <span class="translation-progress__stat-label">{{ __('Translated') }}</span>
        </div>
        <div class="translation-progress__stat translation-progress__stat--update">
          <span class="translation-progress__stat-number">{{ progress.updated || 0 }}</span>
          <span class="translation-progress__stat-label">{{ __('Overridden') }}</span>
        </div>
        <div v-if="errorCount > 0" class="translation-progress__stat translation-progress__stat--error">
          <span class="translation-progress__stat-number">{{ errorCount }}</span>
          <span class="translation-progress__stat-label">{{ __('Errors') }}</span>
        </div>
        <div
          v-if="linkedCreatedTotalDisplay > 0"
          class="translation-progress__stat translation-progress__stat--linked"
        >
          <span class="translation-progress__stat-number">{{ linkedCreatedTotalDisplay }}</span>
          <span class="translation-progress__stat-label">{{ __('Also created (linked)') }}</span>
        </div>
      </div>

      <!-- Error details -->
      <div v-if="progress.errors && progress.errors.length" class="translation-progress__errors">
        <h4 class="translation-progress__errors-title">{{ __('Error details') }}</h4>
        <ul>
          <li v-for="(error, idx) in progress.errors" :key="idx">{{ error }}</li>
        </ul>
      </div>
    </div>

    <!-- Per-entry status list -->
    <div v-if="normalizedRows.length" class="translation-progress__entries">
      <div
        v-for="row in normalizedRows"
        :key="row.key"
        class="translation-progress__entry"
        :class="{ 'translation-progress__entry--linked': row.linked_entry }"
      >
        <span class="translation-progress__entry-icon">
          <svg v-if="row.status === 'completed'" class="translation-progress__icon--check" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
          </svg>
          <svg v-else-if="row.status === 'failed'" class="translation-progress__icon--error" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
          </svg>
          <span v-else class="translation-progress__spinner"></span>
        </span>
        <div class="translation-progress__entry-main">
          <a
            v-if="row.edit_url && row.status === 'completed'"
            :href="row.edit_url"
            target="_blank"
            rel="noopener noreferrer"
            class="translation-progress__entry-link"
          >
            <span class="translation-progress__entry-titles">
              <span class="translation-progress__entry-origin">{{ row.origin_title || '—' }}</span>
              <span class="translation-progress__entry-arrow" aria-hidden="true">→</span>
              <span class="translation-progress__entry-target">{{ row.target_title || '—' }}</span>
            </span>
            <span v-if="row.lang_label" class="translation-progress__entry-lang">{{ row.lang_label }}</span>
          </a>
          <span v-else class="translation-progress__entry-titles translation-progress__entry-titles--plain">
            <span class="translation-progress__entry-origin">{{ row.origin_title || '—' }}</span>
            <span v-if="row.target_title" class="translation-progress__entry-arrow" aria-hidden="true">→</span>
            <span v-if="row.target_title" class="translation-progress__entry-target">{{ row.target_title }}</span>
            <span v-if="row.lang_label" class="translation-progress__entry-lang">{{ row.lang_label }}</span>
          </span>
          <div v-if="row.linked_entry && row.collection_title" class="translation-progress__entry-linked-meta">
            {{ row.collection_title }}
          </div>
        </div>
        <span
          class="translation-progress__entry-status"
          :class="'translation-progress__entry-status--' + row.status"
        >
          <template v-if="row.linked_entry">{{ __('Linked') }}</template>
          <template v-else-if="row.status === 'completed'">{{ __('Translated') }}</template>
          <template v-else-if="row.status === 'failed'">{{ __('Failed') }}</template>
          <template v-else>{{ row.status }}</template>
        </span>
        <span v-if="row.error" class="translation-progress__entry-error">{{ row.error }}</span>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    progress: {
      type: Object,
      default: () => ({}),
    },
    entries: {
      type: Object,
      default: () => ({}),
    },
    siteLocales: {
      type: Array,
      default: () => [],
    },
    indeterminate: {
      type: Boolean,
      default: false,
    },
  },

  computed: {
    progressPercent() {
      if (!this.progress || !this.progress.total) return 0;
      const c = Number(this.progress.current) || 0;
      const t = Number(this.progress.total) || 1;
      return Math.min(100, Math.round((c / t) * 100));
    },

    percentLabel() {
      if (this.indeterminate) {
        return '…';
      }
      return `${this.progressPercent}%`;
    },

    isCompleted() {
      return this.progress && this.progress.status === 'completed';
    },

    hasFatalError() {
      return this.progress && this.progress.status === 'failed';
    },

    errorCount() {
      return this.progress?.errors?.length || 0;
    },

    linkedCreatedTotalDisplay() {
      const p = this.progress || {};
      if (p.linked_created_total != null && Number(p.linked_created_total) > 0) {
        return Number(p.linked_created_total);
      }
      let n = 0;
      Object.values(this.entries || {}).forEach((row) => {
        n += (row.linked_entries || []).length;
      });
      return n;
    },

    fatalMessages() {
      const p = this.progress || {};
      if (p.fatal_error) {
        return [p.fatal_error];
      }
      const errs = (p.errors || []).filter(Boolean);
      if (errs.length) {
        return errs;
      }
      return [typeof this.__ === 'function' ? this.__('Translation failed') : 'Translation failed'];
    },

    normalizedRows() {
      const obj = this.entries || {};
      const localeNames = {};
      (this.siteLocales || []).forEach((s) => {
        localeNames[s.locale] = s.name;
      });

      const rows = [];
      Object.keys(obj).forEach((key) => {
        const row = obj[key] || {};
        const loc = row.destination_locale || '';
        const raw = row.status || 'processing';
        const st = raw === 'skipped' ? 'completed' : raw;
        const langLabel = localeNames[loc] || loc;
        rows.push({
          key,
          status: st,
          error: row.error,
          origin_title: row.origin_title,
          target_title: row.target_title,
          edit_url: row.edit_url,
          lang_label: langLabel,
          linked_entry: false,
        });
        (row.linked_entries || []).forEach((le) => {
          rows.push({
            key: `${key}::linked::${le.entry_id}`,
            status: 'completed',
            error: null,
            origin_title: typeof this.__ === 'function' ? this.__('Also created') : 'Also created',
            target_title: le.title,
            edit_url: le.edit_url,
            lang_label: langLabel,
            linked_entry: true,
            collection_title: le.collection_title,
          });
        });
      });
      return rows;
    },
  },
};
</script>
