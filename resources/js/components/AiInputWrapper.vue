<template>
    <div class="ai-statamic-assistant">
      <div ref="aiContainer" class="ai-assistant-wrapper">
        <div class="ai-icon-wrapper">
          <button
            type="button"
            :title="__('AI assistant')"
            :aria-label="__('AI assistant')"
            @click="openAssistant"
          >
            <AiIcon class="size-4 text-blue" />
          </button>
        </div>

        <AiTranslateMenu
          v-show="languages.length > 0"
          class="translation-wrapper"
          :languages="languages"
          :disabled="!internalValue"
          :disabled-reason="__('The field is empty.')"
          @select="translateTo"
        />
      </div>

      <div class="relative">
        <ui-input
          v-if="inputType === 'input'"
          key="input"
          ref="editor"
          :model-value="internalValue"
          @update:model-value="internalValue = $event"
        />
        <ui-textarea
          v-else
          key="textarea"
          ref="editor"
          :model-value="internalValue"
          @update:model-value="internalValue = $event"
          :rows="4"
        />

        <AiModalLoadingOverlay
          :show="loadingTranslation"
          compact
          :label="__('Translating…')"
        />
      </div>

      <AiPromptModal
        v-model:open="showModal"
        :field-label="aiFieldLabel"
        :single-line="inputType === 'input'"
        :source="modalSource"
        :context="aiContext"
        :max-words="aiMaxWords"
        @apply="onApply"
      />
    </div>
  </template>

  <script>
  import { FieldtypeMixin as StatamicFieldtypeMixin } from '@statamic/cms';
  import aiTranslationMixin from "../mixins/aiTranslationMixin";
  import fieldContextMixin from "../mixins/fieldContextMixin";
  import AiIcon from "./icons/AiIcon.vue";
  import AiModalLoadingOverlay from "./AiModalLoadingOverlay.vue";
  import AiPromptModal from "./AiPromptModal.vue";
  import AiTranslateMenu from "./AiTranslateMenu.vue";
  import { normalizeAiPlainTextOutput } from "../utils/normalizeAiOutput";
  import { showUndoToast } from "../utils/undoToast";

  export default {
    name: "AiInputWrapper",
    mixins: [StatamicFieldtypeMixin, aiTranslationMixin, fieldContextMixin],
    components: { AiIcon, AiModalLoadingOverlay, AiPromptModal, AiTranslateMenu },

    props: {
      value: { type: String, default: "" },
      inputType: { type: String, default: "textarea" },
    },

    data() {
      return {
        internalValue: this.value,
        showModal: false,
        modalSource: { mode: 'all', text: '' },
      };
    },

    watch: {
      internalValue(newVal) {
        this.update(newVal);
      },
      value(newVal) {
        if (newVal !== this.internalValue) {
          this.internalValue = newVal;
        }
      },
    },

    mounted() {
      this.$nextTick(() => {
        const group = this.$el.closest('.grid-cell') || this.$el.closest('.form-group');
        if (!group) return;

        const label = group.querySelector('label');
        const container = this.$refs.aiContainer;
        if (label && container) {
          if (label.prepend) {
            label.prepend(container);
          } else {
            label.insertBefore(container, label.firstChild);
          }
        }
      });
    },

    methods: {
      openAssistant() {
        this.modalSource = { mode: 'all', text: this.internalValue || '' };
        this.showModal = true;
      },

      /** Write the AI result into the field, with undo. */
      onApply({ value }) {
        const previous = this.internalValue;
        this.internalValue = value;
        this.focusField();
        showUndoToast(__('Text applied.'), () => {
          this.internalValue = previous;
        });
      },

      translatableContent() {
        return { text: this.internalValue };
      },

      applyTranslation(data) {
        const previous = this.internalValue;
        let text = normalizeAiPlainTextOutput(data.translated);
        if (this.inputType === 'input' && typeof text === 'string') {
          text = text.replace(/\s+/g, ' ').trim();
        }
        this.internalValue = text;
        return () => {
          this.internalValue = previous;
        };
      },

      focusField() {
        this.$nextTick(() => {
          this.$refs.editor?.$el?.querySelector?.('input, textarea')?.focus();
        });
      },
    },
  };
  </script>

  <style>
  /*
   * Icons are prepended into the <label>; we use flex order so the visible order is: title text, then icons.
   * Statamic often uses justify-between on labels — that pushes title and icons to opposite ends; override so
   * they stay inline next to each other.
   */
  label:has(.ai-assistant-wrapper) {
    display: flex !important;
    align-items: center;
    justify-content: flex-start !important;
    flex-wrap: wrap;
    gap: 0.5rem;
    width: 100%;
    box-sizing: border-box;
  }

  .ai-assistant-wrapper {
    display: flex;
    align-items: center;
    flex-shrink: 0;
    gap: 2px;
    order: 2;
  }

  /* Fieldtype icon buttons */
  .ai-icon-wrapper,
  .translation-wrapper {
    position: relative;
  }

  .ai-icon-wrapper > button,
  .translation-wrapper > button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    border: none;
    border-radius: 0.375rem;
    background: transparent;
    cursor: pointer;
    transition: background 0.15s;
  }

  .ai-icon-wrapper > button:hover,
  .translation-wrapper > button:hover {
    background: rgba(0, 0, 0, 0.05);
  }

  .dark .ai-icon-wrapper > button:hover,
  .dark .translation-wrapper > button:hover {
    background: rgba(255, 255, 255, 0.07);
  }
  </style>
