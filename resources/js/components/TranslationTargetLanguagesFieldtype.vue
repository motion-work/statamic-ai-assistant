<template>
  <div class="translation-target-languages-fieldtype">
    <translation-target-language-list
      :sites="targetSites"
      :value="normalizedValue"
      :conflict-predicate="localeHasConflictForBadge"
      wrapper-class="translation-action-modal__target-languages-inner"
      @input="update"
    />
    <p
      v-if="!normalizedValue.length"
      class="translation-page__error-inline translation-page__error-inline--soft translation-target-languages-fieldtype__hint"
    >
      {{ __('Select at least one target language') }}
    </p>
  </div>
</template>

<script>
import { FieldtypeMixin as StatamicFieldtypeMixin } from '@statamic/cms';
import TranslationTargetLanguageList from './TranslationTargetLanguageList.vue';
import { normalizeDestinationLocales } from '../utils/normalizeDestinationLocales.js';

const EVENT = 'statamic-ai-assistant.translation-preflight';

export default {
  mixins: [StatamicFieldtypeMixin],

  components: {
    TranslationTargetLanguageList,
  },

  data() {
    return {
      preflightConflictsByLocale: [],
    };
  },

  computed: {
    publishValues() {
      return this.publishContainer?.values || {};
    },

    overwrite() {
      return !!this.publishValues.overwrite;
    },

    targetSites() {
      return this.config.sites || [];
    },

    normalizedValue() {
      return normalizeDestinationLocales(this.value);
    },
  },

  mounted() {
    const def = this.config.default;
    if (
      def &&
      Array.isArray(def) &&
      def.length > 0 &&
      (!this.value || (Array.isArray(this.value) && this.value.length === 0))
    ) {
      this.update([...def]);
    }
  },

  created() {
    this._onPreflight = (payload) => {
      if (!payload) {
        return;
      }
      this.preflightConflictsByLocale = Array.isArray(payload.conflictsByLocale)
        ? payload.conflictsByLocale
        : [];
    };
    this.$events.$on(EVENT, this._onPreflight);
  },

  beforeUnmount() {
    this.$events.$off(EVENT, this._onPreflight);
  },

  methods: {
    localeHasConflictForBadge(site) {
      if (this.overwrite || !site) {
        return false;
      }
      if (!this.normalizedValue.includes(site.locale)) {
        return false;
      }
      return this.preflightConflictsByLocale.some((b) => b.locale === site.locale);
    },
  },
};
</script>
