<template>
    <div>
        <div
            v-show="languages.length > 0"
            ref="translationContainer"
            :class="!bardHasTranslatableContent ? 'opacity-50' : ''"
            class="ai-bard-btn-wrapper"
        >
            <button
                type="button"
                class="ai-bard-translate-trigger"
                :disabled="!bardHasTranslatableContent"
                :aria-label="__('DeepL Translation')"
                @click="toggleTranslateDropdown"
            >
                <Translation class="h-4 w-4 text-green-600" />
            </button>

            <div v-if="showTranslateDropdown" class="ai-translate-dropdown">
                <div
                    v-for="lang in languages"
                    :key="lang.code"
                    class="ai-translate-dropdown__item"
                    @click="translateTo(lang.code)"
                >
                    {{ lang.label }}
                </div>
            </div>
        </div>
        <div
            ref="loadingBard"
            :style="{ display: loadingTranslation ? 'flex' : 'none' }"
            class="absolute inset-0 z-10 flex items-center justify-center"
        >
            <ui-icon name="loading" class="z-10 size-6 text-gray-600" />
            <div class="absolute inset-0 rounded bg-white/75 dark:bg-gray-850/75"></div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import Translation from './icons/TranslationIcon.vue';
import aiTranslationMixin from '../mixins/aiTranslationMixin';

/** True when Bard HTML has no meaningful text (matches empty-field UX for ai_text translation). */
function isBardHtmlEffectivelyEmpty(html) {
    if (html == null || typeof html !== 'string') {
        return true;
    }
    const t = html.trim();
    if (t === '') {
        return true;
    }
    if (t === '<br class="ProseMirror-trailingBreak">') {
        return true;
    }
    const text = t
        .replace(/<[^>]+>/g, ' ')
        .replace(/&nbsp;/gi, ' ')
        .replace(/\u00a0/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    return text === '';
}

export default {
    mixins: [aiTranslationMixin],
    name: 'BardAiTranslation',
    components: { Translation },
    props: {
        editor: Object,
        bard: Object,
        button: Object,
        active: Boolean,
        variant: String,
        config: Object,
    },
    data() {
        return {
            value: null,
            loadingTranslation: false,
            showTranslateDropdown: false,
            selectedLanguage: 'en',
            languages: [],
            bardHasTranslatableContent: false,
        };
    },
    mounted() {
        this.$nextTick(() => {
            const groupNode = this.$el.closest('.bard-fieldtype-wrapper');
            if (groupNode && this.$refs.loadingBard) {
                groupNode.appendChild(this.$refs.loadingBard);
            }
            this.syncBardTranslatableContent();
            if (this.editor && typeof this.editor.on === 'function') {
                this._onBardEditorUpdate = () => {
                    this.syncBardTranslatableContent();
                };
                this.editor.on('update', this._onBardEditorUpdate);
            }
        });
    },
    beforeUnmount() {
        if (this.editor && typeof this.editor.off === 'function' && this._onBardEditorUpdate) {
            this.editor.off('update', this._onBardEditorUpdate);
        }
    },
    methods: {
        syncBardTranslatableContent() {
            let html = '';
            try {
                html = this.editor?.commands?.getBardContent?.() ?? '';
            } catch (e) {
                html = '';
            }
            this.bardHasTranslatableContent = !isBardHtmlEffectivelyEmpty(html);
        },
        toggleTranslateDropdown() {
            if (!this.bardHasTranslatableContent) {
                return;
            }
            const result = this.editor.commands.getBardContent();
            if (result && result !== '') {
                this.value = result;
            }
            this.showTranslateDropdown = !this.showTranslateDropdown;
        },
        async translateTo(lang) {
            this.selectedLanguage = lang;
            this.showTranslateDropdown = false;
            this.loadingTranslation = true;

            try {
                const response = await axios.post('/cp/ai-translations/field', {
                    text: this.value,
                    target_locale: lang,
                    html: true,
                });

                if (!response?.data?.translated || response.data.translated === '') {
                    throw new Error('Empty response from DeepL. Verify your API key.');
                }

                this.result = response.data.translated;
                this.editor.commands.WriteInBard(this.result);
                Statamic.$toast.success(__('Your content has been translated.'));
            } catch (error) {
                Statamic.$toast.error(
                    error?.response?.data?.error || error.message || __('Translation failed.'),
                    { duration: 10000 },
                );
            } finally {
                this.loadingTranslation = false;
            }
        },
    },
};
</script>
