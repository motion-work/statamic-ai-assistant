import axios from "axios";
import { normalizeAiOutput } from "../utils/normalizeAiOutput";

export default {
  data() {
    return {
      loadingTranslation: false,
      showTranslateDropdown: false,
      selectedLanguage: 'en',
      languages: [],
      value: null
    };
  },
  methods: {
    toggleTranslateDropdown() {
      this.showTranslateDropdown = !this.showTranslateDropdown;
    },
    populateLanguagesFromSites() {
      const sites = Statamic.$config.get('sites') || [];
      this.languages = sites.map((site) => ({
        code: site.lang,
        label: site.name,
      }));
    },

    async getLocalizations() {
      this.populateLanguagesFromSites();
      if (this.languages.length) {
        return;
      }
      try {
        const response = await axios.get('/cp/getLocalizations');
        if (!response?.data || !response.data.content) return;
        const locales = response.data.content;
        this.languages = Object.keys(locales).map((key) => {
          const locale = locales[key];
          return {
            code: locale.short_locale,
            label: locale.name,
          };
        });
      } catch (error) {
        console.error('Error fetching locales:', error);
      }
    },
    async translateTo(lang) {
      this.selectedLanguage = lang;
      this.showTranslateDropdown = false;
      this.loadingTranslation = true;

      try {
        const response = await axios.post('/cp/ai-translations/field', {
          text: this.value,
          target_locale: lang,
        });

        if (!response?.data?.translated || response.data.translated === '') {
          throw new Error(__('Empty response from DeepL. Verify your API key.'));
        }

        this.result =
          typeof this.normalizeAiFieldValue === "function"
            ? this.normalizeAiFieldValue(response.data.translated)
            : normalizeAiOutput(response.data.translated);

        if (this.validateResult) {
          this.validateResult();
        } else if (this.editor && this.editor.commands && this.editor.commands.WriteInBard) {
          this.editor.commands.WriteInBard(this.result);
        }

        Statamic.$toast.success(__('Your content has been translated.'));
      } catch (error) {
        Statamic.$toast.error(
          error?.response?.data?.error || error.message || __('Translation failed.'),
          { duration: 10000 }
        );
      } finally {
        this.loadingTranslation = false;
      }
    },
    handleClickOutside(event) {
      if (!this.showTranslateDropdown) {
        return;
      }
      const el = this.$refs.translationContainer;
      if (!el || !(el instanceof Node)) {
        return;
      }
      if (!el.contains(event.target)) {
        this.showTranslateDropdown = false;
      }
    },
  },
  mounted() {
    this.getLocalizations();
    document.addEventListener('click', this.handleClickOutside);
  },
  beforeUnmount() {
    document.removeEventListener('click', this.handleClickOutside);
  }
};
