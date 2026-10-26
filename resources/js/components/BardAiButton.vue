<template>
    <div>
        <div class="ai-bard-btn-wrapper">
            <button
                type="button"
                class="bard-toolbar-button ai-bard-btn"
                v-tooltip="__('AI assistant')"
                :aria-label="__('AI assistant')"
                :disabled="disabled"
                @click="openAssistant"
            >
                <AiIcon class="size-4 text-blue" />
            </button>
        </div>

        <AiPromptModal
            v-model:open="showModal"
            html
            :field-label="aiFieldLabel"
            :source="modalSource"
            :context="aiContext"
            :max-words="aiMaxWords"
            @apply="onApply"
        />
    </div>
</template>

<script>
import AiIcon from "./icons/AiIcon.vue";
import AiPromptModal from "./AiPromptModal.vue";
import fieldContextMixin from "../mixins/fieldContextMixin";
import { applyToBard, bardSnapshot, snapshotTexts } from "../utils/bardAi";
import { showUndoToast } from "../utils/undoToast";

export default {
    name: "BardAiButton",
    mixins: [fieldContextMixin],
    components: { AiIcon, AiPromptModal },
    props: {
        editor: Object,
        bard: Object,
        button: Object,
        active: Boolean,
        variant: String,
        config: Object,
        disabled: Boolean
    },
    data() {
        return {
            showModal: false,
            snapshot: null,
            modalSource: { mode: 'all', text: '' },
        };
    },
    methods: {
        /**
         * Works on the selection when there is one; otherwise on the whole
         * field. A field with sets is handled as text runs so the sets are
         * never sent to the AI (see utils/bardAi.js).
         */
        openAssistant() {
            this.snapshot = bardSnapshot(this.editor);
            this.modalSource = {
                mode: this.snapshot.mode,
                text: this.snapshot.html,
                texts: this.snapshot.mode === 'runs' ? snapshotTexts(this.snapshot) : null,
            };
            this.showModal = true;
        },

        onApply({ kind, value, parts }) {
            const snapshot = this.snapshot;
            const runs = snapshot.mode === 'runs';
            const undo = applyToBard(
                this.editor,
                snapshot,
                runs && kind === 'rewrite' ? parts : value,
                { insertAtCursor: runs && kind === 'write' },
            );
            showUndoToast(__('Text applied.'), undo);
        },
    }
};
</script>
