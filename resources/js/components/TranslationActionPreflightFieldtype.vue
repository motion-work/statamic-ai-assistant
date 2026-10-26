<template>
  <div class="translation-action-preflight-root">
  <!-- Same order as Bulk translations configure step: overwrite card content → estimate → conflict -->
  <div v-if="part === 'hints'" class="translation-action-preflight translation-action-preflight--hints">
    <div class="translation-action-modal__notices">
      <div
        v-if="overwrite"
        class="translation-action-modal__notice translation-action-modal__notice--warning"
        role="status"
      >
        <span class="translation-action-modal__notice-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
            <line x1="12" y1="9" x2="12" y2="13" />
            <line x1="12" y1="17" x2="12.01" y2="17" />
          </svg>
        </span>
        <p class="translation-action-modal__notice-text">
          {{ __('Existing translations in the destination language will be replaced.') }}
        </p>
      </div>

      <div
        v-if="showSkipCallout"
        class="translation-action-modal__notice translation-action-modal__notice--info"
        role="status"
      >
        <span class="translation-action-modal__notice-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="16" x2="12" y2="11" />
            <circle cx="12" cy="8" r="1.25" fill="currentColor" stroke="none" />
          </svg>
        </span>
        <p class="translation-action-modal__notice-text">
          {{ __('Skip existing callout') }}
        </p>
      </div>
    </div>
  </div>

  <div v-else class="translation-action-preflight translation-action-preflight--footer">
    <div v-if="estimateText" class="translation-page__estimate translation-action-modal__estimate">
      {{ estimateText }}
    </div>

    <div
      v-if="hasConflictWithoutOverwrite"
      class="translation-action-modal__notice translation-action-modal__notice--danger"
      role="alert"
    >
      <span class="translation-action-modal__notice-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10" />
          <line x1="15" y1="9" x2="9" y2="15" />
          <line x1="9" y1="9" x2="15" y2="15" />
        </svg>
      </span>
      <div class="translation-action-modal__notice-body">
        <p class="translation-action-modal__notice-text">{{ conflictMessage }}</p>
        <div v-if="conflictsByLocale.length" class="translation-action-modal__conflict-detail">
          <p class="translation-action-modal__notice-text translation-action-modal__notice-text--detail">
            {{ __('Conflict detail intro') }}
          </p>
          <ul class="translation-action-modal__conflict-locales">
            <li
              v-for="block in conflictsByLocale"
              :key="block.locale"
              class="translation-action-modal__conflict-locale-block"
            >
              <span class="translation-action-modal__conflict-locale-label">{{ block.locale_label }}</span>
              <ul class="translation-action-modal__conflict-pages">
                <li v-for="(title, idx) in block.entry_titles" :key="block.locale + '-' + idx">
                  {{ title }}
                </li>
              </ul>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- Second-step overwrite warning (same copy as Bulk translations) — only footer instance wires the Run button -->
  <div
    v-if="part === 'footer' && showOverwriteConfirm"
    class="translation-page__modal-backdrop translation-action-modal__overwrite-backdrop"
    role="dialog"
    aria-modal="true"
    @click.self="cancelOverwriteConfirm"
  >
    <div class="translation-page__modal translation-action-modal__overwrite-dialog">
      <h3 class="translation-page__modal-title">{{ __('Overwrite confirmation title') }}</h3>
      <p class="translation-page__modal-text">{{ __('Overwrite confirmation body') }}</p>
      <ul class="translation-page__modal-list">
        <li>{{ __('Overwrite confirmation bullet 1') }}</li>
        <li>{{ __('Overwrite confirmation bullet 2') }}</li>
      </ul>
      <div class="translation-page__modal-actions">
        <Button variant="default" :text="__('Cancel')" @click="cancelOverwriteConfirm" />
        <Button variant="primary" :text="__('Yes, replace existing translations')" @click="confirmOverwriteAndRun" />
      </div>
    </div>
  </div>
  </div>
</template>

<script>
import { FieldtypeMixin as StatamicFieldtypeMixin } from '@statamic/cms';
import { Button } from '@statamic/cms/ui';
import { normalizeDestinationLocales } from '../utils/normalizeDestinationLocales.js';

const EVENT = 'statamic-ai-assistant.translation-preflight';

export default {
  components: { Button },

  mixins: [StatamicFieldtypeMixin],

  data() {
    return {
      hasConflict: null,
      conflictsByLocale: [],
      checking: false,
      showOverwriteConfirm: false,
      overwriteSubmitConfirmed: false,
    };
  },

  computed: {
    part() {
      return this.config.preflight_part || 'footer';
    },

    publishValues() {
      return this.publishContainer?.values || {};
    },

    entryIds() {
      return this.config.entry_ids || [];
    },

    siteLocaleLabels() {
      return this.config.site_locale_labels || {};
    },

    sourceLocale() {
      return this.config.default_source_locale || '';
    },

    destinationLocales() {
      return normalizeDestinationLocales(this.publishValues.destination_locales);
    },

    overwrite() {
      return !!this.publishValues.overwrite;
    },

    wouldSkipExisting() {
      return this.hasConflict === true;
    },

    hasConflictWithoutOverwrite() {
      return !this.overwrite && this.hasConflict === true;
    },

    showSkipCallout() {
      return !this.overwrite && this.wouldSkipExisting;
    },

    totalOperations() {
      const m = this.destinationLocales.length;
      if (m === 0) {
        return 0;
      }
      return this.entryIds.length * m;
    },

    destinationLanguageNames() {
      const names = [];
      this.destinationLocales.forEach((loc) => {
        names.push(this.siteLocaleLabels[loc] || loc);
      });
      return names.join(', ');
    },

    estimateText() {
      const n = this.entryIds.length;
      const langs = this.destinationLanguageNames;
      if (!n || !this.destinationLocales.length) {
        return this.__('Choose sources and targets to see the estimate.');
      }
      return this.__('Will translate :entries entries into :langs (:ops operations) using DeepL.', {
        entries: n,
        langs: langs,
        ops: this.totalOperations,
      });
    },

    conflictMessage() {
      return this.__('Conflict without overwrite message');
    },

    canStartTranslation() {
      if (this.entryIds.length === 0 || !this.sourceLocale || this.destinationLocales.length === 0) {
        return false;
      }
      if (this.destinationLocales.some((l) => l === this.sourceLocale)) {
        return false;
      }
      if (this.hasConflictWithoutOverwrite) {
        return false;
      }
      return true;
    },
  },

  watch: {
    publishValues: {
      deep: true,
      handler() {
        if (this.part === 'footer') {
          this.scheduleCheck();
        }
      },
    },

    hasConflict() {
      if (this.part === 'footer') {
        this.broadcast();
      }
    },

    checking() {
      if (this.part === 'footer') {
        this.broadcast();
      }
    },

    overwrite(val) {
      if (!val) {
        this.showOverwriteConfirm = false;
        this.overwriteSubmitConfirmed = false;
      }
    },
  },

  created() {
    this._preflightDebounce = null;
    this.scheduleCheck = () => {
      clearTimeout(this._preflightDebounce);
      this._preflightDebounce = setTimeout(() => {
        if (this.part === 'footer') {
          this.checkConflict();
        }
      }, 300);
    };

    if (this.part === 'hints') {
      this.eventHandler = (payload) => {
        if (!payload) {
          return;
        }
        this.hasConflict = payload.hasConflict;
        this.checking = payload.checking;
      };
      this.$events.$on(EVENT, this.eventHandler);
    }
  },

  mounted() {
    if (this.part === 'footer') {
      this._onConfirmModalPrimaryClick = this.onConfirmModalPrimaryClick.bind(this);
      document.addEventListener('click', this._onConfirmModalPrimaryClick, true);
      this.$nextTick(() => {
        this.syncConfirmButton();
        this.checkConflict();
      });
    }
  },

  beforeUnmount() {
    if (this.part === 'footer' && this._onConfirmModalPrimaryClick) {
      document.removeEventListener('click', this._onConfirmModalPrimaryClick, true);
    }
    if (this.part === 'hints' && this.eventHandler) {
      this.$events.$off(EVENT, this.eventHandler);
    }
    if (this._preflightDebounce) {
      clearTimeout(this._preflightDebounce);
    }
  },

  updated() {
    if (this.part === 'footer') {
      this.$nextTick(() => this.syncConfirmButton());
    }
  },

  methods: {
    onConfirmModalPrimaryClick(e) {
      if (this.part !== 'footer') {
        return;
      }
      const t = e.target;
      if (!t || !t.closest) {
        return;
      }
      const btn = t.closest('button.btn-primary');
      if (!btn || btn.closest('.translation-page__modal-backdrop')) {
        return;
      }
      const form = btn.closest('form.confirmation-modal');
      if (!form || !form.querySelector('.translation-action-preflight')) {
        return;
      }
      if (btn.disabled) {
        return;
      }
      if (this.showOverwriteConfirm) {
        return;
      }
      if (!this.publishValues.overwrite) {
        this.overwriteSubmitConfirmed = false;
        return;
      }
      if (this.overwriteSubmitConfirmed) {
        this.$nextTick(() => {
          this.overwriteSubmitConfirmed = false;
        });
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
      this.showOverwriteConfirm = true;
    },

    cancelOverwriteConfirm() {
      this.showOverwriteConfirm = false;
    },

    confirmOverwriteAndRun() {
      this.showOverwriteConfirm = false;
      this.overwriteSubmitConfirmed = true;
      this.$nextTick(() => {
        const form = document.querySelector('form.confirmation-modal');
        const btn = form?.querySelector('button.btn-primary');
        if (btn && !btn.disabled) {
          btn.click();
        }
      });
    },

    broadcast() {
      this.$events.$emit(EVENT, {
        hasConflict: this.hasConflict,
        checking: this.checking,
        conflictsByLocale: this.conflictsByLocale || [],
      });
    },

    async checkConflict() {
      if (!this.entryIds.length || !this.sourceLocale || !this.destinationLocales.length) {
        this.hasConflict = false;
        this.conflictsByLocale = [];
        this.broadcast();
        this.$nextTick(() => this.syncConfirmButton());
        return;
      }
      if (this.destinationLocales.some((l) => l === this.sourceLocale)) {
        this.hasConflict = false;
        this.conflictsByLocale = [];
        this.broadcast();
        this.$nextTick(() => this.syncConfirmButton());
        return;
      }

      this.checking = true;
      this.broadcast();
      this.syncConfirmButton();

      try {
        const { data } = await this.$axios.post('/cp/ai-translations/conflict-check', {
          entry_ids: this.entryIds,
          destination_locales: this.destinationLocales,
        });
        this.hasConflict = !!data.has_conflict;
        this.conflictsByLocale = Array.isArray(data.conflicts_by_locale) ? data.conflicts_by_locale : [];
      } catch (e) {
        this.hasConflict = false;
        this.conflictsByLocale = [];
      } finally {
        this.checking = false;
        this.broadcast();
        this.$nextTick(() => this.syncConfirmButton());
      }
    },

    syncConfirmButton() {
      const form = document.querySelector('form.confirmation-modal');
      if (!form) {
        return;
      }
      const btn = form.querySelector('button.btn-primary');
      if (!btn) {
        return;
      }
      const disable = this.checking || !this.canStartTranslation || this.hasConflict === null;
      btn.disabled = !!disable;
    },
  },
};
</script>
