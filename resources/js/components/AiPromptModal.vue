<template>
  <ui-modal
    ref="modal"
    :open="open"
    :title="title"
    blur
    @update:open="$emit('update:open', $event)"
    @dismissed="$emit('update:open', false)"
  >
    <ui-modal-close>
      <button type="button" class="ai-prompt__close" :aria-label="__('Close')" :title="__('Close')">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true">
          <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
    </ui-modal-close>

    <div class="ai-prompt" @keydown.enter.meta.prevent="primaryAction" @keydown.enter.ctrl.prevent="primaryAction">
      <AiModalLoadingOverlay
        :show="loading"
        :label="__('Generating…')"
        cancellable
        :cancel-label="__('Cancel')"
        @cancel="cancelRequest"
      />

      <!-- Mode: rewrite the existing text, or write something new -->
      <div v-if="stage === 'input' && hasSource" class="ai-prompt__tabs" role="tablist">
        <button
          v-for="t in tabs"
          :key="t.id"
          type="button"
          role="tab"
          class="ai-prompt__tab"
          :class="{ 'ai-prompt__tab--active': tab === t.id }"
          :aria-selected="tab === t.id ? 'true' : 'false'"
          @click="tab = t.id"
        >
          {{ t.label }}
        </button>
      </div>

      <!-- ── Input: rewrite ── -->
      <div v-if="stage === 'input' && tab === 'rewrite'" class="ai-prompt__section">
        <div>
          <p class="ai-prompt__label">{{ source.mode === 'selection' ? __('Selected text') : __('Current text') }}</p>
          <div v-if="html" class="ai-prompt__preview prose prose-sm" v-html="baseHtml" />
          <div v-else class="ai-prompt__preview ai-prompt__preview--plain">{{ base }}</div>
          <p v-if="source.mode === 'runs'" class="ai-prompt__notice">
            {{ __('Blocks (sets) in this field stay unchanged. The AI only rewrites the text.') }}
          </p>
        </div>

        <div>
          <p class="ai-prompt__label">{{ __('Quick actions') }}</p>
          <div class="ai-prompt__chips">
            <button
              v-for="chip in chips"
              :key="chip.id"
              type="button"
              class="ai-prompt__chip"
              :disabled="loading"
              @click="rewrite(chip.instruction)"
            >
              {{ chip.label }}
            </button>
          </div>
        </div>

        <div>
          <p class="ai-prompt__label">{{ __('Or describe your adjustment') }}</p>
          <ui-textarea
            ref="instructionInput"
            v-model="instruction"
            :rows="2"
            :placeholder="__('refactor_placeholder')"
          />
        </div>
      </div>

      <!-- ── Input: write new ── -->
      <div v-else-if="stage === 'input' && tab === 'write'" class="ai-prompt__section">
        <ui-description :text="__('prompt_placeholder')" />
        <ui-textarea
          ref="promptInput"
          v-model="prompt"
          :rows="4"
          :placeholder="__('prompt_textarea_placeholder')"
        />
        <p v-if="writeNotice" class="ai-prompt__notice">{{ writeNotice }}</p>
      </div>

      <!-- ── Result ── -->
      <div v-else-if="stage === 'result'" class="ai-prompt__section">
        <div v-if="canCompare" class="ai-prompt__tabs ai-prompt__tabs--small" role="tablist">
          <button
            type="button"
            role="tab"
            class="ai-prompt__tab"
            :class="{ 'ai-prompt__tab--active': view === 'edit' }"
            @click="view = 'edit'"
          >
            {{ __('Result') }}
          </button>
          <button
            type="button"
            role="tab"
            class="ai-prompt__tab"
            :class="{ 'ai-prompt__tab--active': view === 'compare' }"
            @click="view = 'compare'"
          >
            {{ __('Compare') }}
          </button>
        </div>

        <template v-if="view === 'edit'">
          <p class="ai-prompt__hint">{{ __('You can edit the result before applying it.') }}</p>
          <template v-if="html">
            <template v-for="(part, i) in resultParts" :key="`${resultVersion}-${i}`">
              <div
                :ref="(el) => setPartEl(el, i)"
                class="ai-prompt__editable prose prose-sm"
                contenteditable="true"
                @input="onPartInput(i, $event)"
              />
              <p v-if="source.mode === 'runs' && target === 'source' && i < resultParts.length - 1" class="ai-prompt__set-marker">
                {{ __('Block (set) stays unchanged') }}
              </p>
            </template>
          </template>
          <ui-textarea
            v-else
            v-model="result"
            :rows="singleLine ? 2 : 6"
          />
        </template>

        <div v-else class="ai-prompt__diff" aria-live="polite">
          <span
            v-for="(part, i) in diff"
            :key="i"
            :class="{ 'ai-prompt__diff-add': part.type === 'add', 'ai-prompt__diff-del': part.type === 'del' }"
          >{{ part.text }}</span>
        </div>
      </div>

      <p v-if="maxWords && stage === 'input'" class="ai-prompt__hint">
        {{ __('Short text: at most :n words.', { n: maxWords }) }}
      </p>
    </div>

    <template #footer>
      <div class="ai-prompt__footer">
        <ui-modal-close>
          <ui-button variant="ghost" :text="__('Cancel')" />
        </ui-modal-close>

        <div class="ai-prompt__footer-actions">
          <template v-if="stage === 'input'">
            <span class="ai-prompt__shortcut" aria-hidden="true">{{ shortcutLabel }}</span>
            <ui-button
              v-if="tab === 'rewrite'"
              variant="primary"
              icon="ai-sparks"
              :text="__('Rewrite')"
              :disabled="loading || instruction.trim() === ''"
              @click="rewrite(instruction)"
            />
            <ui-button
              v-else
              variant="primary"
              icon="ai-sparks"
              :text="__('Generate')"
              :disabled="loading || prompt.trim() === ''"
              @click="write"
            />
          </template>
          <template v-else>
            <ui-button variant="ghost" :text="__('new_prompt')" @click="startOver" />
            <ui-button :text="__('Keep refining')" @click="keepRefining" />
            <span class="ai-prompt__shortcut" aria-hidden="true">{{ shortcutLabel }}</span>
            <ui-button variant="primary" :text="__('apply_changes')" @click="apply" />
          </template>
        </div>
      </div>
    </template>
  </ui-modal>
</template>

<script>
import AiModalLoadingOverlay from './AiModalLoadingOverlay.vue';
import { aiPost, friendlyAiError, isAiRequestCancelled } from '../utils/aiRequest';
import { normalizeAiOutput, normalizeAiPlainTextOutput } from '../utils/normalizeAiOutput';
import { diffWords, htmlToText } from '../utils/wordDiff';

/**
 * The AI window shared by the Ai Text / Ai Textarea fieldtypes and the Bard
 * AI button. The caller passes what is in the field (`source`) and receives
 * `apply` with the final text; writing it back (and offering undo) is the
 * caller's job, because a plain field and a Bard document are written
 * differently.
 */
export default {
  name: 'AiPromptModal',
  components: { AiModalLoadingOverlay },

  props: {
    open: { type: Boolean, default: false },
    /** Field label, shown in the title and sent to the AI as context. */
    fieldLabel: { type: String, default: '' },
    /** Bard: results are HTML and shown as rich text. */
    html: { type: Boolean, default: false },
    /** Single-line field (Ai Text): whitespace collapsed, small result box. */
    singleLine: { type: Boolean, default: false },
    /**
     * What is in the field right now.
     * mode: 'all' | 'selection' (Bard selection) | 'runs' (Bard with sets; `texts` are the runs)
     */
    source: { type: Object, default: () => ({ mode: 'all', text: '', texts: null }) },
    /** Sent with every request: field_label, field_handle, entry_title, max_words. */
    context: { type: Object, default: () => ({}) },
    maxWords: { type: Number, default: null },
  },

  emits: ['update:open', 'apply'],

  data() {
    return {
      tab: 'write',
      stage: 'input',
      view: 'edit',
      prompt: '',
      instruction: '',
      base: '',
      baseParts: null,
      result: '',
      resultParts: [],
      resultVersion: 0,
      /** Last AI operation, for the comparison view. */
      lastOp: 'write',
      /** 'source': the result replaces what is in the field; 'new': it is newly written text. */
      target: 'source',
      loading: false,
      controller: null,
      partEls: [],
    };
  },

  computed: {
    title() {
      return this.fieldLabel ? `${__('AI assistant')} · ${this.fieldLabel}` : __('AI assistant');
    },
    hasSource() {
      return String(this.source?.text ?? '').replace(/<[^>]+>/g, '').trim() !== '';
    },
    tabs() {
      return [
        { id: 'rewrite', label: __('Rewrite') },
        { id: 'write', label: __('Write new') },
      ];
    },
    baseHtml() {
      return this.baseParts ? this.baseParts.join('') : this.base;
    },
    chips() {
      return [
        { id: 'shorter', label: __('Shorter'), instruction: 'Make it noticeably shorter while keeping the key message.' },
        { id: 'longer', label: __('Longer'), instruction: 'Make it longer and more detailed, without inventing facts.' },
        { id: 'simpler', label: __('Simpler'), instruction: 'Use simpler, clearer language and shorter sentences.' },
        { id: 'friendlier', label: __('Friendlier'), instruction: 'Make the tone warmer and friendlier.' },
        { id: 'spelling', label: __('Fix spelling'), instruction: 'Only fix spelling, grammar and punctuation. Change nothing else.' },
        { id: 'seo', label: __('Optimise for SEO'), instruction: 'Optimise it for search engines: clear, relevant wording that stays natural and readable.' },
      ];
    },
    writeNotice() {
      if (!this.hasSource) return '';
      if (this.source.mode === 'selection') return __('Replaces the selected text.');
      if (this.source.mode === 'runs') return __('Inserted at the cursor. The existing content and blocks stay.');
      return __('Replaces the current content of this field.');
    },
    canCompare() {
      return this.lastOp === 'rewrite' && this.base !== '';
    },
    diff() {
      const before = this.html ? htmlToText(this.baseHtml) : this.base;
      const after = this.html ? htmlToText(this.resultParts.join('')) : this.result;
      return diffWords(before, after);
    },
    shortcutLabel() {
      const mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
      return mac ? '⌘ ↵' : 'Ctrl ↵';
    },
  },

  watch: {
    open(isOpen) {
      if (isOpen) this.reset();
      else this.cancelRequest();
    },
  },

  methods: {
    reset() {
      this.stage = 'input';
      this.view = 'edit';
      this.prompt = '';
      this.instruction = '';
      this.base = this.source?.text ?? '';
      this.baseParts = this.source?.mode === 'runs' ? [...(this.source.texts || [])] : null;
      this.result = '';
      this.resultParts = [];
      this.target = 'source';
      this.tab = this.hasSource ? 'rewrite' : 'write';
      this.focusInput();
    },

    focusInput() {
      this.$nextTick(() => this.$nextTick(() => {
        const ref = this.tab === 'rewrite' ? this.$refs.instructionInput : this.$refs.promptInput;
        ref?.$el?.querySelector?.('textarea')?.focus();
      }));
    },

    normalize(value) {
      if (this.html) return normalizeAiOutput(value);
      const plain = normalizeAiPlainTextOutput(value);
      return this.singleLine && typeof plain === 'string' ? plain.replace(/\s+/g, ' ').trim() : plain;
    },

    async request(url, payload) {
      this.cancelRequest();
      this.controller = new AbortController();
      this.loading = true;
      try {
        const { data } = await aiPost(url, { ...payload, context: this.context }, this.controller);
        return data;
      } catch (error) {
        if (!isAiRequestCancelled(error)) {
          Statamic.$toast.error(friendlyAiError(error), { duration: 10000 });
        }
        return null;
      } finally {
        this.loading = false;
        this.controller = null;
      }
    },

    cancelRequest() {
      this.controller?.abort();
      this.controller = null;
      this.loading = false;
    },

    async write() {
      if (this.prompt.trim() === '') return;
      const data = await this.request('/cp/prompt', { title: this.prompt });
      if (!data) return;
      const text = this.normalize(data.content ?? '');
      if (!this.hasText(text)) return this.emptyResult();
      this.target = 'new';
      this.base = '';
      this.baseParts = null;
      this.showResult('write', [text]);
    },

    async rewrite(instruction) {
      if (!instruction || instruction.trim() === '') return;

      let data;
      if (this.html && this.baseParts) {
        data = await this.request('/cp/promptHtmlrefactor', { texts: this.baseParts, prompt: instruction });
        if (!data) return;
        const parts = (data.contents || []).map((c) => this.normalize(c));
        if (!parts.some((p) => this.hasText(p))) return this.emptyResult();
        return this.showResult('rewrite', parts);
      }

      data = await this.request(this.html ? '/cp/promptHtmlrefactor' : '/cp/promptrefactor', {
        text: this.base,
        prompt: instruction,
      });
      if (!data) return;
      const text = this.normalize(data.content ?? '');
      if (!this.hasText(text)) return this.emptyResult();
      this.showResult('rewrite', [text]);
    },

    showResult(op, parts) {
      this.lastOp = op;
      this.resultParts = parts;
      this.result = parts.join('');
      this.resultVersion++;
      this.view = 'edit';
      this.stage = 'result';
      this.partEls = [];
    },

    emptyResult() {
      Statamic.$toast.error(__('The AI returned no text. Please try again.'), { duration: 10000 });
    },

    hasText(value) {
      return String(value ?? '').replace(/<[^>]+>/g, '').trim() !== '';
    },

    setPartEl(el, i) {
      if (!el || this.partEls[i] === el) return;
      this.partEls[i] = el;
      el.innerHTML = this.resultParts[i] ?? '';
    },

    onPartInput(i, event) {
      // Stored without re-rendering, so the caret stays where the editor types.
      this.resultParts[i] = event.target.innerHTML;
    },

    startOver() {
      const tab = this.tab;
      this.reset();
      this.tab = this.hasSource ? tab : 'write';
      this.focusInput();
    },

    keepRefining() {
      if (this.html) {
        this.baseParts = this.target === 'source' && this.source?.mode === 'runs' ? [...this.resultParts] : null;
        this.base = this.resultParts.join('');
      } else {
        this.base = this.result;
      }
      this.instruction = '';
      this.tab = 'rewrite';
      this.stage = 'input';
      this.focusInput();
    },

    apply() {
      const parts = this.html ? [...this.resultParts] : [this.normalize(this.result)];
      this.$emit('apply', {
        kind: this.target === 'new' ? 'write' : 'rewrite',
        value: parts.join(''),
        parts,
      });
      this.$emit('update:open', false);
    },

    primaryAction() {
      if (this.loading) return;
      if (this.stage === 'result') return this.apply();
      if (this.tab === 'rewrite') return this.rewrite(this.instruction);
      return this.write();
    },
  },
};
</script>
