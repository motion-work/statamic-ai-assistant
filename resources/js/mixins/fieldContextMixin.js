import { publishContextKey } from '@statamic/cms/ui';

/**
 * What an AI request from a field should know about where it is: the field
 * label/handle, the entry title, and the field's word limit. Also the site of
 * the localization being edited, so the translate menu can leave out the
 * language the entry is already in.
 */
export default {
  inject: {
    aiPublishContext: { from: publishContextKey, default: null },
  },

  computed: {
    /** The field's config: the fieldtype's own, or the Bard field's for Bard toolbar buttons. */
    aiFieldConfig() {
      return this.bard?.config ?? this.config ?? {};
    },

    aiFieldHandle() {
      return this.bard?.handle ?? this.aiFieldConfig.handle ?? this.handle ?? '';
    },

    aiFieldLabel() {
      return this.aiFieldConfig.display || this.aiFieldHandle || '';
    },

    aiRootContext() {
      let ctx = this.aiPublishContext;
      while (ctx?.parentContainer) ctx = ctx.parentContainer;
      return ctx;
    },

    aiEntryTitle() {
      const values = this.aiRootContext?.values?.value ?? this.aiRootContext?.values;
      const title = values?.title;
      return typeof title === 'string' ? title : '';
    },

    aiMaxWords() {
      const n = parseInt(this.aiFieldConfig.max_words, 10);
      return Number.isFinite(n) && n > 0 ? n : null;
    },

    aiContext() {
      return {
        field_label: this.aiFieldLabel || null,
        field_handle: this.aiFieldHandle || null,
        entry_title: this.aiEntryTitle || null,
        max_words: this.aiMaxWords,
      };
    },

    /** Language of the localization being edited (e.g. "de"), or null if unknown. */
    aiCurrentLang() {
      const handle = this.aiPublishContext?.site?.value ?? this.aiRootContext?.site?.value;
      if (!handle) return null;
      const site = (Statamic.$config.get('sites') || []).find((s) => s.handle === handle);
      return site?.lang ?? null;
    },
  },
};
