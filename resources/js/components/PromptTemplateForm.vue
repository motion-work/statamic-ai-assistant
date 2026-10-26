<template>
  <div class="eg-templates" role="dialog" :aria-label="__('Prompt templates')">
    <div class="eg-templates__head">
      <button
        type="button"
        class="eg-templates__back"
        :title="__('Back')"
        :aria-label="__('Back')"
        @click="$emit('back')"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true">
          <path d="M15 18l-6-6 6-6" />
        </svg>
      </button>
      <span class="eg-templates__title">{{ __(template.title) }}</span>
      <button
        type="button"
        class="eg-templates__close"
        :title="__('Close')"
        :aria-label="__('Close')"
        @click="$emit('close')"
      >
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true">
          <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
    </div>

    <div class="eg-templates__intro">{{ __(template.summary) }}</div>

    <div class="eg-tform__list">
      <div v-for="q in template.questions" :key="q.id" class="eg-tform__field">
        <label class="eg-tform__label">
          <span>{{ __(q.label) }}<span v-if="q.required" class="eg-tform__req" aria-hidden="true">*</span></span>
        </label>

        <!-- Text -->
        <input
          v-if="q.type === 'text'"
          type="text"
          class="eg-tform__input"
          :value="answers[q.id] || ''"
          :placeholder="q.placeholder ? __(q.placeholder) : ''"
          @input="onText(q, $event)"
        />

        <!-- Choice -->
        <div v-else-if="q.type === 'choice'" class="eg-tform__choices">
          <button
            v-for="(opt, i) in q.options"
            :key="opt"
            type="button"
            class="eg-tform__choice"
            :class="{ 'eg-tform__choice--on': choiceValue(q) === opt }"
            @click="onChoice(q, opt)"
          >
            {{ __(opt) }}
          </button>
        </div>

        <!-- Entry picker -->
        <div v-else-if="q.type === 'entry'" class="eg-tform__entry">
          <input
            type="text"
            class="eg-tform__input"
            :value="entryQuery(q.id)"
            :placeholder="__('Search a page by title…')"
            @input="onEntryInput(q, $event)"
            @focus="onEntryFocus(q)"
            @keydown="onEntryKeydown(q, $event)"
          />
          <div v-if="entryState(q.id).open" class="eg-tform__entry-list" role="listbox">
            <div v-if="entryState(q.id).loading" class="eg-tform__entry-state">{{ __('Searching…') }}</div>
            <ul v-else-if="entryState(q.id).results.length">
              <li
                v-for="(item, i) in entryState(q.id).results"
                :key="item.id || item.ref"
                role="option"
                :aria-selected="i === entryState(q.id).activeIndex"
                class="eg-tform__entry-item"
                :class="{ 'is-active': i === entryState(q.id).activeIndex }"
                @mousedown.prevent="selectEntry(q, item)"
                @mousemove="entryState(q.id).activeIndex = i"
              >
                <span class="eg-tform__entry-title">{{ item.title }}</span>
                <span class="eg-tform__entry-meta">{{ item.collection_title || item.collection }}</span>
              </li>
            </ul>
            <div v-else class="eg-tform__entry-state">{{ __('No entries found') }}</div>
          </div>
          <p v-if="answers[q.id]" class="eg-tform__entry-picked">
            {{ answers[q.id].title }}
            <button type="button" class="eg-tform__entry-clear" :aria-label="__('Clear')" @click="clearEntry(q)">×</button>
          </p>
        </div>

        <p v-if="q.help" class="eg-tform__help">{{ __(q.help) }}</p>
      </div>
    </div>

    <div class="eg-tform__foot">
      <button type="button" class="eg-tform__btn eg-tform__btn--ghost" @click="$emit('back')">{{ __('Back') }}</button>
      <button
        type="button"
        class="eg-tform__btn eg-tform__btn--primary"
        :disabled="!canApply"
        @click="onApply"
      >
        {{ __('Start prompt') }}
      </button>
    </div>
  </div>
</template>

<script>
import axios from 'axios';

/**
 * Clarifying-questions form for a single prompt template. Self-contained: runs
 * its own entry search against /cp/ai-generate/entry-search so it doesn't
 * entangle with the chat composer's @-mention machinery.
 *
 * Emits `apply(answers)` with answers keyed by question id. Choice answers hold
 * the English option string (locale-stable for buildPrompt). Entry answers hold
 * `{ id, title, collection, collection_title }`.
 */
export default {
  name: 'PromptTemplateForm',

  props: {
    template: { type: Object, required: true },
  },

  emits: ['apply', 'back', 'close'],

  data() {
    const answers = {};
    for (const q of this.template.questions || []) {
      if (q.type === 'choice' && typeof q.default === 'number') {
        answers[q.id] = q.options[q.default];
      }
    }
    return {
      answers,
      entrySearch: {}, // qid -> { query, results, loading, open, activeIndex, _req, _debounce }
    };
  },

  computed: {
    canApply() {
      return (this.template.questions || []).every((q) => !q.required || this.hasAnswer(q));
    },
  },

  methods: {
    hasAnswer(q) {
      const v = this.answers[q.id];
      if (v == null) return false;
      if (typeof v === 'string') return v.trim() !== '';
      if (typeof v === 'object') return !!v.title;
      return true;
    },

    onText(q, event) {
      this.answers[q.id] = event.target.value;
    },

    choiceValue(q) {
      return this.answers[q.id];
    },

    onChoice(q, opt) {
      this.answers[q.id] = opt;
    },

    // ── Entry picker ──
    entryState(qid) {
      if (!this.entrySearch[qid]) {
        this.entrySearch[qid] = {
          query: '',
          results: [],
          loading: false,
          open: false,
          activeIndex: 0,
          _req: 0,
          _debounce: null,
        };
      }
      return this.entrySearch[qid];
    },
    entryQuery(qid) {
      return this.entryState(qid).query;
    },
    onEntryInput(q, event) {
      const st = this.entryState(q.id);
      st.query = event.target.value;
      clearTimeout(st._debounce);
      const q2 = st.query.trim();
      if (q2.length < 1) {
        st.open = false;
        st.results = [];
        return;
      }
      st._debounce = setTimeout(() => this.searchEntries(q.id, q2), 160);
    },
    onEntryFocus(q) {
      const st = this.entryState(q.id);
      if (st.results.length) st.open = true;
    },
    onEntryKeydown(q, event) {
      const st = this.entryState(q.id);
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        this.moveEntry(st, 1);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        this.moveEntry(st, -1);
      } else if (event.key === 'Enter' || event.key === 'Tab') {
        const item = st.results[st.activeIndex];
        if (item) {
          event.preventDefault();
          this.selectEntry(q, item);
        }
      } else if (event.key === 'Escape') {
        event.preventDefault();
        st.open = false;
      }
    },
    moveEntry(st, dir) {
      const n = st.results.length;
      if (!n) return;
      st.activeIndex = (st.activeIndex + dir + n) % n;
    },
    async searchEntries(qid, query) {
      const st = this.entryState(qid);
      st.loading = true;
      st.open = true;
      const reqId = ++st._req;
      try {
        const { data } = await axios.get('/cp/ai-generate/entry-search', {
          params: { q: query, limit: 8 },
        });
        if (reqId !== st._req) return;
        // Only entries — assets/folders are not valid page targets here.
        st.results = (data.results || []).filter((r) => r.kind === 'entry');
        st.activeIndex = 0;
      } catch (e) {
        if (reqId === st._req) st.results = [];
      } finally {
        if (reqId === st._req) st.loading = false;
      }
    },
    selectEntry(q, item) {
      this.answers[q.id] = {
        id: item.id,
        title: item.title,
        collection: item.collection,
        collection_title: item.collection_title,
      };
      const st = this.entryState(q.id);
      st.query = item.title;
      st.open = false;
    },
    clearEntry(q) {
      this.answers[q.id] = null;
      const st = this.entryState(q.id);
      st.query = '';
      st.results = [];
      st.open = false;
    },

    onApply() {
      if (!this.canApply) return;
      this.$emit('apply', this.answers);
    },
  },
};
</script>
