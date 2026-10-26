<template>
  <EditorContent :editor="editor" class="eg-composer-input" />
</template>

<script>
/**
 * Chat composer with live chips — a minimal TipTap (v3, same editor family as
 * Statamic's Bard) instance whose ONLY special node is `promptChip`: an atomic
 * inline pill for the prompt token grammar (@asset:/@folder: refs, @Title
 * entry mentions, URLs — see composer/promptTokens.js).
 *
 * The outside contract stays PLAIN TEXT: `v-model` carries the raw prompt
 * string. The chip node's renderText() returns the raw token, so serializing
 * the document reproduces exactly what a <textarea> would have contained —
 * the store, mention resolution and the planner never know chips exist.
 *
 * The mention DROPDOWN stays in the parent (it also owns search + results):
 *   - `mention-query` is emitted with {query} while the caret sits in an
 *     unfinished "@…" token, and with null when it leaves one
 *   - `keydown-interceptor` gives the parent first right of refusal on keys
 *     (arrow/enter/tab/escape while its dropdown is open)
 *   - insertMention(text) replaces the active "@…" token with a chip
 */
import { Editor, EditorContent } from '@tiptap/vue-3';
import { Node, mergeAttributes } from '@tiptap/core';
import Document from '@tiptap/extension-document';
import Paragraph from '@tiptap/extension-paragraph';
import Text from '@tiptap/extension-text';
import HardBreak from '@tiptap/extension-hard-break';
import { Placeholder, UndoRedo } from '@tiptap/extensions';
import { tokenizePrompt, chipLabel, formatFileSize } from '../composer/promptTokens.js';

/** Inline atom rendering a prompt token as a pill; getText() yields the raw token. */
const PromptChip = Node.create({
  name: 'promptChip',
  group: 'inline',
  inline: true,
  atom: true,
  selectable: true,

  addAttributes() {
    return {
      kind: { default: 'entry' },
      text: { default: '' },
      label: { default: '' },
      // Extra muted text after the label (e.g. an attachment's file size).
      meta: { default: '' },
    };
  },

  parseHTML() {
    return [{ tag: 'span[data-prompt-chip]' }];
  },

  renderHTML({ node }) {
    const { kind, text, label, meta } = node.attrs;
    const children = [];

    if (kind === 'file') {
      children.push(['span', { class: 'eg-chip-icon eg-chip-icon--file', 'aria-hidden': 'true' }]);
    }
    children.push(`${kind === 'url' || kind === 'file' ? '' : '@'}${label || text}`);
    if (meta) {
      children.push(['span', { class: 'eg-composer-chip__meta' }, ` ${meta}`]);
    }

    return [
      'span',
      mergeAttributes({
        'data-prompt-chip': kind,
        class: `eg-composer-chip eg-composer-chip--${kind}`,
        title: text,
      }),
      ...children,
    ];
  },

  renderText({ node }) {
    return node.attrs.text;
  },
});

export default {
  name: 'AgentComposerInput',
  components: { EditorContent },

  props: {
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    /** Entry titles registered via the picker — the only "@Title" chips we trust. */
    knownTitles: { type: Array, default: () => [] },
    /** Pending attachment metadata by token: { "report.pdf": {name, size} }. */
    fileMeta: { type: Object, default: () => ({}) },
    /** Return true to consume a keydown before the editor sees it (dropdown nav). */
    keydownInterceptor: { type: Function, default: null },
  },

  emits: ['update:modelValue', 'send', 'mention-query', 'chip-click', 'blur'],

  data() {
    return {
      editor: null,
      // Last text this component emitted; external watcher echoes are no-ops.
      lastEmitted: this.modelValue,
      // Active "@query" range (doc positions) while the mention picker is open.
      mentionRange: null,
      _lastQuery: undefined,
    };
  },

  watch: {
    modelValue(value) {
      if (value === this.lastEmitted) return;
      this.setFromText(value);
    },

    disabled(value) {
      this.editor?.setEditable(!value);
    },

    placeholder(value) {
      const ext = this.editor?.extensionManager.extensions.find((e) => e.name === 'placeholder');
      if (ext) {
        ext.options.placeholder = value;
        this.editor.view.dispatch(this.editor.state.tr); // re-render decoration
      }
    },
  },

  mounted() {
    this.editor = new Editor({
      editable: !this.disabled,
      content: this.docFromText(this.modelValue),
      extensions: [
        Document,
        Paragraph,
        Text,
        HardBreak,
        UndoRedo,
        PromptChip,
        Placeholder.configure({ placeholder: this.placeholder }),
      ],
      editorProps: {
        handleKeyDown: (view, event) => {
          if (this.keydownInterceptor && this.keydownInterceptor(event)) {
            return true;
          }
          if (event.key === 'Enter' && !event.shiftKey) {
            this.$emit('send');
            return true;
          }
          return false;
        },
        // Chips are interactive like their transcript counterparts: the parent
        // opens previews/browser/URL. Returning false keeps default node
        // selection, so a clicked chip can still be deleted with backspace.
        handleClickOn: (view, pos, node) => {
          if (node.type.name === 'promptChip') {
            this.$emit('chip-click', { kind: node.attrs.kind, text: node.attrs.text });
          }
          return false;
        },
      },
      onUpdate: () => {
        this.chipifyCompletedTokens();
        this.emitText();
        this.emitMentionQuery();
      },
      onSelectionUpdate: () => {
        this.emitMentionQuery();
      },
      onBlur: () => {
        this.$emit('blur');
      },
    });
  },

  beforeUnmount() {
    this.editor?.destroy();
    this.editor = null;
  },

  methods: {
    // ── Public API (used by the parent via ref) ──

    focus(position = 'end') {
      this.editor?.chain().focus(position).run();
    },

    /** Replace the active "@query" token with a chip (+ trailing space). */
    insertMention(mentionText) {
      if (!this.editor || !this.mentionRange) return;

      const kind = mentionText.startsWith('asset:') ? 'asset'
        : mentionText.startsWith('folder:') ? 'folder'
          : 'entry';
      const token = `@${mentionText}`;

      this.editor.chain()
        .focus()
        .insertContentAt(this.mentionRange, [
          { type: 'promptChip', attrs: { kind, text: token, label: chipLabel(kind, token) } },
          { type: 'text', text: ' ' },
        ])
        .run();

      this.mentionRange = null;
    },

    // ── Text ↔ document round-trip ──

    /** Node attrs for a chip segment; file chips are enriched from fileMeta. */
    chipAttrs(seg) {
      const attrs = { kind: seg.kind, text: seg.text, label: seg.label, meta: '' };

      if (seg.kind === 'file') {
        const info = this.fileMeta[seg.text.replace(/^@file:/, '')];
        if (info) {
          attrs.label = info.name || attrs.label;
          if (info.size) attrs.meta = formatFileSize(info.size);
        }
      }

      return attrs;
    },

    /** One paragraph; newlines are hard breaks; tokens become chip nodes. */
    docFromText(text) {
      const content = [];
      String(text ?? '').split('\n').forEach((line, i) => {
        if (i > 0) content.push({ type: 'hardBreak' });
        for (const seg of tokenizePrompt(line, this.knownTitles)) {
          if (seg.type === 'chip') {
            content.push({ type: 'promptChip', attrs: this.chipAttrs(seg) });
          } else if (seg.text !== '') {
            content.push({ type: 'text', text: seg.text });
          }
        }
      });

      return { type: 'doc', content: [{ type: 'paragraph', content }] };
    },

    serialize() {
      if (!this.editor) return '';

      return this.editor.getText({
        blockSeparator: '\n',
        textSerializers: { hardBreak: () => '\n' },
      });
    },

    setFromText(text) {
      if (!this.editor) return;
      this.lastEmitted = text;
      this.editor.commands.setContent(this.docFromText(text), { emitUpdate: false });
      if (text !== '') {
        this.editor.commands.focus('end');
      }
    },

    emitText() {
      const text = this.serialize();
      if (text === this.lastEmitted) return;
      this.lastEmitted = text;
      this.$emit('update:modelValue', text);
    },

    // ── Live chipification ──

    /**
     * Convert completed tokens in plain text runs into chip nodes. A token
     * still being typed (caret inside/at its end, no boundary after it) is
     * left alone so the user can finish it.
     */
    chipifyCompletedTokens() {
      const { state } = this.editor;
      const caret = state.selection.empty ? state.selection.head : -1;
      const replacements = [];

      state.doc.descendants((node, pos) => {
        if (!node.isText || !node.text) return;

        let offset = 0;
        for (const seg of tokenizePrompt(node.text, this.knownTitles)) {
          const from = pos + offset;
          const to = from + seg.text.length;
          offset += seg.text.length;

          if (seg.type !== 'chip') continue;

          const nextChar = node.text.slice(to - pos, to - pos + 1);
          const stillTyping = caret >= from && caret <= to && !/\s/.test(nextChar);
          if (stillTyping) continue;

          replacements.push({ from, to, seg });
        }
      });

      if (!replacements.length) return;

      const chipType = state.schema.nodes.promptChip;
      let tr = state.tr;
      for (const { from, to, seg } of replacements.reverse()) {
        tr = tr.replaceWith(from, to, chipType.create(this.chipAttrs(seg)));
      }
      this.editor.view.dispatch(tr);
    },

    // ── Mention query (the parent owns the dropdown) ──

    emitMentionQuery() {
      const next = this.computeMentionQuery();
      this.mentionRange = next ? { from: next.from, to: next.to } : null;

      const query = next ? next.query : null;
      if (query === this._lastQuery) return;
      this._lastQuery = query;
      this.$emit('mention-query', query === null ? null : { query });
    },

    /**
     * "@query" directly before the caret, "\0" marking chip/break boundaries
     * so a mention can never start inside or across another node.
     */
    computeMentionQuery() {
      if (!this.editor || this.disabled) return null;
      const { state } = this.editor;
      if (!state.selection.empty) return null;

      const $head = state.selection.$head;
      const before = state.doc.textBetween($head.start(), state.selection.head, '\0', '\0');
      const at = before.lastIndexOf('@');
      if (at === -1) return null;

      const charBefore = at === 0 ? '' : before[at - 1];
      if (charBefore && charBefore !== '\0' && !/\s/.test(charBefore)) return null;

      const query = before.slice(at + 1);
      if (/[\s\0]/.test(query) || query.length > 40) return null;

      return {
        query,
        from: state.selection.head - query.length - 1,
        to: state.selection.head,
      };
    },
  },
};
</script>
