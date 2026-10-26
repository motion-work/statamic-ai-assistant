export default {
  data() {
    return {
      showModal: false,
      prompt: '',
      result: '',
      refactorPrompt: '',
      loading: false,
      // modalMode can be "prompt", "result", or "refactor"
      modalMode: 'prompt',
      hadInitialValue: false,
    };
  },

  watch: {
    modalMode(mode) {
      if (mode === 'refactor') {
        this.scheduleFocusRefactorPrompt();
      }
    },
    showModal(open) {
      if (open && this.modalMode === 'refactor') {
        this.scheduleFocusRefactorPrompt();
      }
    },
  },

  methods: {
    transWithFallback(key, fallback) {
      const translation = __('' + key);
      return translation === key ? fallback : translation;
    },
    openModal(getContentFn) {
      const currentContent = getContentFn ? getContentFn() : '';
      if (
        currentContent &&
        currentContent.trim() !== '' &&
        currentContent !== `<br class="ProseMirror-trailingBreak">`
      ) {
        this.result = currentContent;
        this.modalMode = 'refactor';
        this.hadInitialValue = true;
      } else {
        this.prompt = '';
        this.result = '';
        this.modalMode = 'prompt';
        this.hadInitialValue = false;
      }
      this.refactorPrompt = '';
      this.showModal = true;
    },
    /** Sync parent state when the user dismisses (backdrop / esc); Statamic already calls close() internally. */
    onModalDismissed() {
      this.showModal = false;
    },
    /** Programmatic close (e.g. Apply): force the UI modal to tear down; v-model sync can miss internal state. */
    closeModal() {
      const modal = this.$refs.aiModal;
      if (modal && typeof modal.close === 'function') {
        modal.close();
      }
      this.showModal = false;
    },
    resetToPrompt() {
      this.result = '';
      this.modalMode = 'prompt';
    },
    setRefactorMode() {
      this.refactorPrompt = '';
      this.modalMode = 'refactor';
    },
    cancelRefactor() {
      this.refactorPrompt = '';
      this.modalMode = 'prompt';
    },
    handleEnter() {
      if (this.modalMode === 'prompt' && !this.loading && this.prompt !== '') {
        this.submitPrompt();
      } else if (this.modalMode === 'refactor' && !this.loading && this.refactorPrompt !== '') {
        this.submitRefactor();
      }
    },

    /**
     * Focus the refactor "adjustment" field after the modal body has rendered (refactor branch + portal).
     */
    scheduleFocusRefactorPrompt() {
      if (!this.showModal || this.modalMode !== 'refactor') {
        return;
      }
      this.$nextTick(() => {
        this.$nextTick(() => {
          this.focusStatamicFieldRef('refactorPromptInput');
        });
      });
    },

    /**
     * Statamic ui-input / ui-textarea refs are components with a boolean `focus` prop — `ref.focus` may be true, not a function.
     */
    focusStatamicFieldRef(refOrName) {
      const ref = typeof refOrName === 'string' ? this.$refs[refOrName] : refOrName;
      if (ref == null) {
        return;
      }
      if (typeof ref.focus === 'function') {
        ref.focus();
        return;
      }
      const root = ref.$el;
      if (root instanceof HTMLElement || root instanceof SVGElement) {
        if (typeof root.focus === 'function') {
          root.focus();
          return;
        }
      }
      if (root && typeof root.querySelector === 'function') {
        const inner = root.querySelector(
          'input, textarea, select, [contenteditable="true"]',
        );
        if (inner instanceof HTMLElement && typeof inner.focus === 'function') {
          inner.focus();
        }
      }
    },
  },
};
