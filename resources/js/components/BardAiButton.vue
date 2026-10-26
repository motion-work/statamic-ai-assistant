<template>
    <div>
        <div class="ai-bard-btn-wrapper">
            <button class="bard-toolbar-button ai-bard-btn"
                v-tooltip="tooltipText" @click="openModal(editor.commands.getBardContent)" :disabled="disabled || loading">
                <AiIcon class="size-4 text-blue" />
            </button>
        </div>

        <!-- Modal for AI prompt / result / refactor -->
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
                    <ui-description :text="transWithFallback('prompt_placeholder', 'Describe the content you want to generate')" />
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
                    <div
                        ref="resulteditor"
                        v-html="result"
                        class="prose prose-sm max-w-none rounded-lg border border-gray-400/60 bg-gray-100 p-3 dark:border-gray-700 dark:bg-gray-900"
                    ></div>
                </div>

                <!-- Refactor mode -->
                <div v-else-if="modalMode === 'refactor'" class="space-y-4">
                    <ui-field :label="transWithFallback('current_text', 'Current text')">
                        <div
                            ref="resulteditor"
                            v-html="result"
                            class="prose prose-sm max-w-none rounded-lg border border-gray-400/60 bg-gray-100 p-3 dark:border-gray-700 dark:bg-gray-900"
                        ></div>
                    </ui-field>
                    <ui-field
                        :label="transWithFallback('edit', 'Describe your adjustment')"
                        :instructions="transWithFallback('refactor_description', 'Enter an instruction to update the current text.')"
                    >
                        <ui-textarea
                            ref="refactorPromptInput"
                            v-model="refactorPrompt"
                            :rows="3"
                            :placeholder="transWithFallback('refactor_placeholder', 'Describe your changes')"
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
import { normalizeAiOutput } from "../utils/normalizeAiOutput";
import AiIcon from "./icons/AiIcon.vue";
import aiModalMixin from "../mixins/aiModalMixin";
import AiModalLoadingOverlay from "./AiModalLoadingOverlay.vue";

export default {
    mixins: [aiModalMixin],
    name: "BardAiButton",
    components: {
        AiIcon,
        AiModalLoadingOverlay,
    },
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
            value: null,
            showModal: false,
            prompt: "",
            result: "",
            refactorPrompt: "",
            loading: false,
            modalMode: "prompt",
            hadInitialValue: false,
            tooltipText: "AI Assistant"
        };
    },
    computed: {
        modalTitle() {
            if (this.modalMode === 'result') return this.transWithFallback('result_title', 'Generated content');
            if (this.modalMode === 'refactor') return this.transWithFallback('refactor_title', 'Refactor content');
            return this.transWithFallback('title', 'AI assistant');
        },
    },
    methods: {
        async submitPrompt() {
            if (!this.prompt) return;
            this.loading = true;
            try {
                const response = await axios.post("/cp/prompt", { title: this.prompt });
                if (!response?.data || !response.data.content) return;
                const normalized = normalizeAiOutput(response.data.content);
                if (!normalized || normalized === "") {
                    throw new Error("Empty response from API. Verify your API key.");
                }
                this.result = normalized;
                this.modalMode = "result";
            } catch (error) {
                Statamic.$toast.error(error?.response?.data?.error || error.message || __('Generation failed.'), { duration: 10000 });
            } finally {
                this.loading = false;
            }
        },
        async submitRefactor() {
            if (!this.refactorPrompt) return;
            this.loading = true;
            try {
                const newHTML = await this.editor.commands.refactorHTMLWithAi({
                    text: this.result,
                    refactorPrompt: this.refactorPrompt,
                });
                if (!newHTML || newHTML.trim() === "") {
                    Statamic.$toast.error('Empty response from API. Verify your API key', { duration: 10000 });
                }
                else{
                    this.result = normalizeAiOutput(newHTML);
                    Statamic.$toast.success(__('Your content has been refactored.'));
                    this.modalMode = "result";
                }
            } catch (error) {
                Statamic.$toast.error(error?.response?.data?.error || error.message || __('Refactor failed.'), { duration: 10000 });
            } finally {
                this.loading = false;
            }
        },
        validateResult() {
            try {
                this.editor.commands.WriteInBard(this.result);
            } finally {
                this.closeModal();
            }
        },
    }
};
</script>
