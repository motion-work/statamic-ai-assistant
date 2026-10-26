<template>
    <div>
        <AiTranslateMenu
            v-show="languages.length > 0"
            class="ai-bard-btn-wrapper"
            trigger-class="ai-bard-translate-trigger"
            :languages="languages"
            :disabled="!hasContent"
            :disabled-reason="__('The field is empty.')"
            @select="translateTo"
        />
        <div
            ref="loadingBard"
            :style="{ display: loadingTranslation ? 'flex' : 'none' }"
            class="absolute inset-0 z-10 flex items-center justify-center"
            role="status"
            :aria-label="__('Translating…')"
        >
            <ui-icon name="loading" class="z-10 size-6 text-gray-600" />
            <div class="absolute inset-0 rounded bg-white/75 dark:bg-gray-850/75"></div>
        </div>
    </div>
</template>

<script>
import AiTranslateMenu from './AiTranslateMenu.vue';
import aiTranslationMixin from '../mixins/aiTranslationMixin';
import fieldContextMixin from '../mixins/fieldContextMixin';
import { applyToBard, bardSnapshot, snapshotTexts } from '../utils/bardAi';

export default {
    mixins: [aiTranslationMixin, fieldContextMixin],
    name: 'BardAiTranslation',
    components: { AiTranslateMenu },
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
            hasContent: false,
            snapshot: null,
        };
    },
    mounted() {
        this.$nextTick(() => {
            const groupNode = this.$el.closest('.bard-fieldtype-wrapper');
            if (groupNode && this.$refs.loadingBard) {
                groupNode.appendChild(this.$refs.loadingBard);
            }
            this.syncHasContent();
            if (this.editor && typeof this.editor.on === 'function') {
                this._onBardEditorUpdate = () => this.syncHasContent();
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
        /** Text outside of sets (set nodes are atoms, so they add no text). */
        syncHasContent() {
            this.hasContent = (this.editor?.state?.doc?.textContent ?? '').trim() !== '';
        },

        /** The whole field; with sets, only the text runs between them. */
        translatableContent() {
            this.snapshot = bardSnapshot(this.editor, { ignoreSelection: true });
            return this.snapshot.mode === 'runs'
                ? { texts: snapshotTexts(this.snapshot), html: true }
                : { text: this.snapshot.html, html: true };
        },

        applyTranslation(data) {
            return applyToBard(this.editor, this.snapshot, data.translations ?? data.translated);
        },
    },
};
</script>
