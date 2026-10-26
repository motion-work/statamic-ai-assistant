<template>
    <div class="bold-ai-statamic-assistant">
      <div ref="aiBoldContainer" class="ai-bold-wrapper">
        <!-- AI Icon Button -->
        <div class="ai-icon-wrapper">
          <button type="button" @click="openModal(() => internalValue)">
            <AiIcon class="size-4 text-blue" />
          </button>
        </div>

        <!-- Translation Button & Dropdown -->
        <div
          ref="translationContainer"
          v-show="languages.length > 0"
          :class="!internalValue ? 'opacity-50' : ''"
          class="translation-wrapper"
        >
          <button
            :disabled="!internalValue"
            type="button"
            @click="toggleTranslateDropdown"
          >
            <Translation class="w-4 h-4 text-green-600" />
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
      </div>

      <!-- Main editor (textarea vs. input) -->
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
          :label="transWithFallback('translating', 'Translating…')"
        />
      </div>

      <!-- AI Prompt/Result/Refactor Modal -->
      <ui-modal
        ref="aiModal"
        v-model:open="showModal"
        @dismissed="onModalDismissed"
        :title="modalTitle"
        blur
      >
        <div
          class="ai-modal-body"
          :class="{ 'ai-modal-body--busy': loading }"
          @keyup.enter.ctrl="handleEnter()"
          @keyup.enter.meta="handleEnter()"
        >
          <AiModalLoadingOverlay
            :show="loading"
            :label="transWithFallback('generating', 'Generating…')"
          />

          <!-- Prompt mode -->
          <div v-if="modalMode === 'prompt'" class="space-y-4">
            <ui-description :text="transWithFallback('prompt_placeholder', 'Describe the article you want to generate')" />
            <ui-textarea
              v-model="prompt"
              :rows="4"
              :focus="true"
              :placeholder="transWithFallback('prompt_textarea_placeholder', 'Enter your prompt...')"
            />
          </div>

          <!-- Result mode -->
          <div v-else-if="modalMode === 'result'" class="space-y-4">
            <ui-description :text="transWithFallback('result_description', 'Review the generated content below.')" />
            <ui-textarea
              v-model="result"
              :rows="6"
              ref="resulteditor"
              :read-only="hadInitialValue"
            />
          </div>

          <!-- Refactor mode -->
          <div v-else-if="modalMode === 'refactor'" class="space-y-4">
            <ui-field :label="transWithFallback('current_text', 'Current text')">
              <ui-textarea
                :model-value="result"
                :rows="4"
                read-only
              />
            </ui-field>
            <ui-field
              :label="transWithFallback('edit', 'Describe your adjustment')"
              :instructions="transWithFallback('refactor_description', 'Enter an instruction to update the current text.')"
            >
              <ui-textarea
                ref="refactorPromptInput"
                v-model="refactorPrompt"
                :rows="3"
                :placeholder="transWithFallback('refactor_placeholder', 'Describe your adjustment')"
              />
            </ui-field>
          </div>
        </div>

        <template #footer>
          <div class="flex items-center pt-3 pb-1" :class="modalMode === 'prompt' ? 'justify-end' : 'justify-between'">
            <!-- Prompt -->
            <template v-if="modalMode === 'prompt'">
              <ui-button
                :text="transWithFallback('go', 'Generate')"
                variant="primary"
                icon="ai-sparks"
                :loading="loading"
                :disabled="loading || prompt === ''"
                @click="submitPrompt()"
              />
            </template>

            <!-- Result -->
            <template v-else-if="modalMode === 'result'">
              <ui-button
                :text="transWithFallback('back', 'Back')"
                variant="ghost"
                @click="resetToPrompt()"
              />
              <div class="flex items-center space-x-3">
                <ui-button
                  :text="transWithFallback('refactor', 'Refactor')"
                  @click="setRefactorMode()"
                />
                <ui-button
                  :text="transWithFallback('apply_changes', 'Apply')"
                  variant="primary"
                  @click="validateResult()"
                />
              </div>
            </template>

            <!-- Refactor -->
            <template v-else-if="modalMode === 'refactor'">
              <ui-button
                :text="transWithFallback('back', 'Back')"
                variant="ghost"
                @click="cancelRefactor()"
              />
              <ui-button
                :text="transWithFallback('apply_refactor', 'Apply Refactor')"
                variant="primary"
                :loading="loading"
                :disabled="loading || refactorPrompt === ''"
                @click="submitRefactor()"
              />
            </template>
          </div>
        </template>
      </ui-modal>
    </div>
  </template>

  <script>
  import axios from "axios";
  import { normalizeAiPlainTextOutput, normalizeAiOutput } from "../utils/normalizeAiOutput";
  import { FieldtypeMixin as StatamicFieldtypeMixin } from '@statamic/cms';
  import aiModalMixin from "../mixins/aiModalMixin";
  import aiTranslationMixin from "../mixins/aiTranslationMixin";
  import Translation from "./icons/TranslationIcon.vue";
  import AiIcon from "./icons/AiIcon.vue";
  import AiModalLoadingOverlay from "./AiModalLoadingOverlay.vue";

  export default {
    name: "AiInputWrapper",
    mixins: [StatamicFieldtypeMixin, aiModalMixin, aiTranslationMixin],
    components: { Translation, AiIcon, AiModalLoadingOverlay },

    props: {
      value: { type: String, default: "" },
      inputType: { type: String, default: "textarea" },
    },

    data() {
      return {
        internalValue: this.value,
      };
    },

    computed: {
      modalTitle() {
        if (this.modalMode === 'result') return this.transWithFallback('result_title', 'Generated content');
        if (this.modalMode === 'refactor') return this.transWithFallback('refactor_title', 'Refactor content');
        return this.transWithFallback('title', 'AI assistant');
      },
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
      this.getLocalizations();
      this.$nextTick(() => {
        const group = this.$el.closest('.grid-cell') || this.$el.closest('.form-group');
        if (!group) return;

        const label = group.querySelector('label');
        const container = this.$refs.aiBoldContainer;
        if (label && container) {
          if (label.prepend) {
            label.prepend(container);
          } else {
            label.insertBefore(container, label.firstChild);
          }
        }
      });

      this.editor = {
        commands: { focus: () => this.focusStatamicFieldRef('editor') },
        setEditable: () => {},
      };
      this.resulteditor = {
        commands: { focus: () => this.focusStatamicFieldRef('resulteditor') },
      };
    },

    methods: {
      /**
       * ai_text / ai_textarea: plain text (no HTML). Bard fieldtypes override via missing normalizeAiFieldValue.
       */
      normalizeAiFieldValue(value) {
        if (this.inputType === "textarea") {
          return normalizeAiPlainTextOutput(value);
        }
        if (this.inputType === "input") {
          const plain = normalizeAiPlainTextOutput(value);
          return typeof plain === "string" ? plain.replace(/\s+/g, " ").trim() : plain;
        }
        return normalizeAiOutput(value);
      },

      async submitPrompt() {
        if (!this.prompt) return;
        this.loading = true;
        try {
          let prompt = this.prompt;
          if(this.inputType === 'input'){
            prompt = "generate MAXIMUM 12 words! " + prompt;
          }
          const { data } = await axios.post("/cp/prompt", { title: prompt });
          if (!data.content || String(data.content).trim() === "") {
            throw new Error("Empty response from API. Verify your API key");
          }
          const normalized = this.normalizeAiFieldValue(data.content);
          if (!normalized || String(normalized).trim() === "") {
            throw new Error("Empty response from API. Verify your API key");
          }
          this.result = normalized;
          Statamic.$toast.success(__('Your content has been generated.'));
          this.modalMode = "result";
        } catch (err) {
          Statamic.$toast.error(err.response?.data.error || err.message, { duration: 10000 });
        } finally {
          this.loading = false;
        }
      },

      async submitRefactor() {
        if (!this.refactorPrompt) return;
        this.loading = true;
        try {
          let refactorPrompt = this.refactorPrompt;
          if(this.inputType === 'input'){
            refactorPrompt = "generate MAXIMUM 12 words!" + refactorPrompt;
          }
          const { data } = await axios.post("/cp/promptrefactor", {
            text: this.result,
            prompt: refactorPrompt,
          });
          if (!data.content || String(data.content).trim() === "") {
            throw new Error("Empty response from API. Verify your API key");
          }
          const normalized = this.normalizeAiFieldValue(data.content);
          if (!normalized || String(normalized).trim() === "") {
            throw new Error("Empty response from API. Verify your API key");
          }
          this.result = normalized;
          Statamic.$toast.success(__('Your content has been refactored.'));
          this.modalMode = "result";
        } catch (err) {
          Statamic.$toast.error(err.response?.data.error || err.message, { duration: 10000 });
        } finally {
          this.loading = false;
        }
      },

      validateResult() {
        try {
          this.update(this.normalizeAiFieldValue(this.result));
        } finally {
          if (this.showModal) {
            this.closeModal();
          }
        }
        // After the modal tears down (when it was open), focus the field — avoids CP focus trap and
        // ui-textarea ref.focus being a boolean prop, not a function.
        this.$nextTick(() => {
          this.editor.commands.focus();
          this.editor.setEditable(true);
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
  label:has(.ai-bold-wrapper) {
    display: flex !important;
    align-items: center;
    justify-content: flex-start !important;
    flex-wrap: wrap;
    gap: 0.5rem;
    width: 100%;
    box-sizing: border-box;
  }

  .ai-bold-wrapper {
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
