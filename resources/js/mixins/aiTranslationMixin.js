import axios from "axios";
import { showTranslationNotice } from '../utils/showTranslationNotice.js';
import { showUndoToast } from '../utils/undoToast.js';
import { friendlyAiError } from '../utils/aiRequest.js';

/**
 * Translation logic shared by the AI fieldtypes and the Bard translate button.
 * The component provides `translatableContent()` (what to send) and
 * `applyTranslation(response)` (write it back; returns an undo callback).
 */
export default {
  data() {
    return {
      loadingTranslation: false,
      allLanguages: [],
    };
  },

  computed: {
    /** Site languages, without the one this entry is already in. */
    languages() {
      const current = typeof this.aiCurrentLang === 'string' ? this.aiCurrentLang : null;
      return this.allLanguages.filter((lang) => lang.code !== current);
    },
  },

  methods: {
    populateLanguagesFromSites() {
      const sites = Statamic.$config.get('sites') || [];
      const seen = new Set();
      this.allLanguages = sites
        .filter((site) => !seen.has(site.lang) && seen.add(site.lang))
        .map((site) => ({ code: site.lang, label: site.name }));
    },

    async getLocalizations() {
      this.populateLanguagesFromSites();
      if (this.allLanguages.length) {
        return;
      }
      try {
        const response = await axios.get('/cp/getLocalizations');
        if (!response?.data || !response.data.content) return;
        const locales = response.data.content;
        this.allLanguages = Object.keys(locales).map((key) => ({
          code: locales[key].short_locale,
          label: locales[key].name,
        }));
      } catch (error) {
        console.error('Error fetching locales:', error);
      }
    },

    async translateTo(lang) {
      this.loadingTranslation = true;

      try {
        const response = await axios.post('/cp/ai-translations/field', {
          ...this.translatableContent(),
          target_locale: lang.code,
        });

        const data = response?.data || {};
        const hasResult = Array.isArray(data.translations)
          ? data.translations.some((t) => String(t ?? '').trim() !== '')
          : String(data.translated ?? '').trim() !== '';
        if (!hasResult) {
          throw new Error('empty translation');
        }

        const undo = this.applyTranslation(data);
        showUndoToast(__('Translated into :language.', { language: lang.label }), undo);
        showTranslationNotice(data.notice);
      } catch (error) {
        Statamic.$toast.error(
          friendlyAiError(error, 'The translation failed. Please try again. If it keeps happening, tell your administrator.'),
          { duration: 10000 }
        );
      } finally {
        this.loadingTranslation = false;
      }
    },
  },

  mounted() {
    this.getLocalizations();
  },
};
