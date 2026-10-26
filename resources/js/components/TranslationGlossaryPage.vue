<template>
  <div class="translation-page translation-page--wide">
    <header class="translation-page__hero translation-page__hero--agent">
      <div class="translation-page__hero-main">
        <div class="translation-page__hero-icon" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
            <path d="M9 7h6M9 11h4" />
          </svg>
        </div>
        <div class="translation-page__hero-text">
          <p class="translation-page__eyebrow">{{ __('DeepL translations') }}</p>
          <h1 class="translation-page__title">{{ __('Glossary & style rules') }}</h1>
          <p class="translation-page__subtitle">
            {{ __('Fixed translations for your key terms and a writing style per language. Applied automatically to every DeepL translation — pages, bulk runs, single fields and Bard content.') }}
          </p>
        </div>
      </div>
      <div class="translation-page__hero-actions">
        <span v-if="dirty" class="gp-dirty-pill">{{ __('Unsaved changes') }}</span>
        <button
          type="button"
          class="gp-btn gp-btn--primary"
          :disabled="saving || !dirty"
          @click="save"
        >
          <svg v-if="saving" class="gp-spinner" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke-width="3" fill="none" stroke-linecap="round" />
          </svg>
          <span>{{ saving ? __('Saving…') : __('Save & sync to DeepL') }}</span>
        </button>
      </div>
    </header>

    <div class="gp-body">
      <!-- Loading -->
      <div v-if="loading" class="gp-state">
        <div class="gp-state__spinner" aria-hidden="true"></div>
        <p>{{ __('Loading…') }}</p>
      </div>

      <!-- Load error -->
      <div v-else-if="loadError" class="gp-state gp-state--error">
        <p>{{ loadError }}</p>
        <button type="button" class="gp-btn" @click="load">{{ __('Retry') }}</button>
      </div>

      <template v-else>
        <!-- Sync warnings from the last save -->
        <div v-if="syncWarnings.length" class="gp-warnings" role="alert">
          <p v-for="(w, i) in syncWarnings" :key="i">{{ w }}</p>
        </div>

        <!-- Tabs -->
        <nav class="gp-tabs" role="tablist">
          <button
            type="button"
            class="gp-tab"
            role="tab"
            :class="{ 'gp-tab--active': activeTab === 'glossary' }"
            :aria-selected="activeTab === 'glossary'"
            @click="activeTab = 'glossary'"
          >
            {{ __('Glossary') }}
            <span class="gp-tab__count">{{ rows.length }}</span>
          </button>
          <button
            type="button"
            class="gp-tab"
            role="tab"
            :class="{ 'gp-tab--active': activeTab === 'styles' }"
            :aria-selected="activeTab === 'styles'"
            @click="activeTab = 'styles'"
          >
            {{ __('Style rules') }}
            <span class="gp-tab__count">{{ configuredStylesCount }}</span>
          </button>
        </nav>

        <!-- ============ GLOSSARY TAB ============ -->
        <section v-if="activeTab === 'glossary'" class="translation-page__panel gp-panel">
          <div class="gp-panel__head">
            <div>
              <h2 class="translation-page__panel-title">{{ __('Glossary terms') }}</h2>
              <p class="gp-panel__intro">
                {{ __('One row per term, one column per language. DeepL will always use exactly these translations. A row is used for a language pair as soon as both cells are filled.') }}
              </p>
            </div>
            <span v-if="glossarySynced" class="gp-sync-badge" :title="__('The glossary is active on DeepL.')">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l4 4 10-10" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
              {{ __('Synced with DeepL') }}
            </span>
          </div>

          <div class="gp-toolbar">
            <div class="gp-search">
              <svg class="gp-search__icon" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="11" cy="11" r="7" fill="none" stroke-width="2" />
                <path d="M20 20l-3.5-3.5" fill="none" stroke-width="2" stroke-linecap="round" />
              </svg>
              <input
                v-model="search"
                type="search"
                class="gp-search__input"
                :placeholder="__('Filter terms…')"
              />
            </div>
            <button type="button" class="gp-btn gp-btn--add" @click="addRow">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke-width="2" stroke-linecap="round" /></svg>
              {{ __('Add term') }}
            </button>
          </div>

          <div v-if="rows.length === 0" class="gp-state gp-state--inline">
            <p>{{ __('No glossary terms yet. Add your brand names, room categories, and recurring wording so every translation uses them consistently.') }}</p>
            <button type="button" class="gp-btn gp-btn--primary" @click="addRow">{{ __('Add first term') }}</button>
          </div>

          <div v-else class="gp-table-wrap">
            <table class="gp-table">
              <thead>
                <tr>
                  <th v-for="lang in languages" :key="lang" scope="col">
                    <span class="gp-table__lang">{{ languageLabel(lang) }}</span>
                    <code class="gp-table__code">{{ lang }}</code>
                  </th>
                  <th class="gp-table__actions-col" scope="col">
                    <span class="sr-only">{{ __('Actions') }}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in filteredRows" :key="row.id" class="gp-row">
                  <td v-for="lang in languages" :key="lang">
                    <input
                      type="text"
                      class="gp-cell-input"
                      :value="row.terms[lang] || ''"
                      :placeholder="__('Term in :lang', { lang: languageLabel(lang) })"
                      @input="updateTerm(row, lang, $event.target.value)"
                    />
                  </td>
                  <td class="gp-table__actions-col">
                    <button
                      type="button"
                      class="gp-row-remove"
                      :aria-label="__('Remove term')"
                      @click="removeRow(row)"
                    >
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6l-12 12" fill="none" stroke-width="2" stroke-linecap="round" /></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
            <p v-if="search && filteredRows.length === 0" class="gp-muted">{{ __('No terms match the filter.') }}</p>
          </div>
        </section>

        <!-- ============ STYLE RULES TAB ============ -->
        <section v-else class="translation-page__panel gp-panel">
          <div class="gp-panel__head">
            <div>
              <h2 class="translation-page__panel-title">{{ __('Style rules per language') }}</h2>
              <p class="gp-panel__intro">
                {{ __('Describe how DeepL should write in each language: tone, formality, spelling preferences, wording to avoid. Rules apply whenever content is translated INTO that language.') }}
              </p>
              <p class="gp-panel__intro gp-panel__intro--muted">
                {{ __('Glossary terms take priority: text that contains a glossary term is translated with the term enforced, so a style rule may not apply to those specific sentences.') }}
              </p>
            </div>
          </div>

          <div class="gp-styles">
            <article v-for="lang in languages" :key="lang" class="gp-style-card">
              <header class="gp-style-card__head">
                <h3>{{ languageLabel(lang) }}</h3>
                <code class="gp-table__code">{{ lang }}</code>
                <span
                  v-if="styles[lang] && styles[lang].synced && !dirty"
                  class="gp-sync-badge"
                  :title="__('This style rule is active on DeepL.')"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l4 4 10-10" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                  {{ __('Synced') }}
                </span>
              </header>

              <ul class="gp-style-list">
                <li
                  v-for="(rule, idx) in (styleDraft[lang] || [])"
                  :key="idx"
                  class="gp-style-item"
                >
                  <span class="gp-style-item__num" aria-hidden="true">{{ idx + 1 }}</span>
                  <textarea
                    class="gp-textarea gp-style-item__input"
                    rows="2"
                    :value="rule"
                    :placeholder="stylePlaceholder(lang)"
                    @input="updateStyleInstruction(lang, idx, $event.target.value)"
                    @keydown.enter.exact.prevent="addStyleInstruction(lang, idx)"
                  ></textarea>
                  <button
                    type="button"
                    class="gp-style-item__remove"
                    :aria-label="__('Remove instruction')"
                    @click="removeStyleInstruction(lang, idx)"
                  >
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6l-12 12" fill="none" stroke-width="2" stroke-linecap="round" /></svg>
                  </button>
                </li>
              </ul>

              <button type="button" class="gp-style-add" @click="addStyleInstruction(lang)">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke-width="2" stroke-linecap="round" /></svg>
                {{ (styleDraft[lang] || []).length === 0 ? __('Add instruction') : __('Add another instruction') }}
              </button>
            </article>
          </div>
        </section>
      </template>
    </div>
  </div>
</template>

<script>
export default {
  data() {
    return {
      loading: true,
      saving: false,
      loadError: null,
      activeTab: "glossary",
      languages: [],
      // Rows: [{ id, terms: { de: '', en: '' } }] — locally mutable
      rows: [],
      originalRows: [],
      // Styles from server: { de: { instructions, synced } }
      styles: {},
      // Editable instructions: { de: '' }
      styleDraft: {},
      originalStyleDraft: {},
      glossarySynced: false,
      syncWarnings: [],
      dirty: false,
      search: "",
    };
  },

  computed: {
    filteredRows() {
      const q = this.search.trim().toLowerCase();
      if (!q) return this.rows;
      return this.rows.filter((row) =>
        Object.values(row.terms || {}).some((t) =>
          String(t || "").toLowerCase().includes(q)
        )
      );
    },

    configuredStylesCount() {
      return Object.values(this.styleDraft).filter(
        (list) => Array.isArray(list) && list.some((v) => (v || "").trim() !== "")
      ).length;
    },
  },

  mounted() {
    this.load();
  },

  methods: {
    languageLabel(lang) {
      try {
        const locale = (Statamic.$config && Statamic.$config.get("locale")) || "en";
        const dn = new Intl.DisplayNames([locale], { type: "language" });
        const label = dn.of(lang);
        if (label && label !== lang) {
          return label.charAt(0).toUpperCase() + label.slice(1);
        }
      } catch (e) {
        // Fall through to the raw code.
      }
      return lang.toUpperCase();
    },

    stylePlaceholder(lang) {
      if (lang === "de") {
        return this.__('e.g. Formelle Anrede (Sie). Immer «ss» statt «ß» (Schweizer Rechtschreibung). Warmer, gastfreundlicher Ton.');
      }
      return this.__('e.g. Friendly, concise tone. Use British spelling. Never translate the hotel name.');
    },

    async load() {
      this.loading = true;
      this.loadError = null;
      try {
        const { data } = await this.$axios.get("/cp/ai-translation-glossary/data");
        this.applyServerState(data);
      } catch (e) {
        this.loadError =
          (e && e.response && e.response.data && e.response.data.error) ||
          this.__("Could not load the glossary.");
      } finally {
        this.loading = false;
      }
    },

    applyServerState(data) {
      this.languages = data.languages || [];
      this.rows = (data.entries || []).map((e) => ({
        id: e.id,
        terms: { ...(e.terms || {}) },
      }));
      this.originalRows = JSON.parse(JSON.stringify(this.rows));
      this.styles = data.styles || {};
      const styleDraft = {};
      for (const lang of this.languages) {
        const stored = (this.styles[lang] && this.styles[lang].instructions) || [];
        // Server sends an array; tolerate a legacy string too.
        styleDraft[lang] = Array.isArray(stored)
          ? [...stored]
          : String(stored || "").trim() !== ""
          ? [String(stored)]
          : [];
      }
      this.styleDraft = styleDraft;
      this.originalStyleDraft = JSON.parse(JSON.stringify(styleDraft));
      this.glossarySynced = !!data.glossary_synced;
      this.dirty = false;
    },

    addRow() {
      const terms = {};
      for (const lang of this.languages) terms[lang] = "";
      this.rows.push({
        id: "new-" + Math.random().toString(36).slice(2, 10),
        terms,
      });
      this.markDirty();
      this.$nextTick(() => {
        const inputs = this.$el.querySelectorAll(".gp-row:last-child .gp-cell-input");
        if (inputs[0]) inputs[0].focus();
      });
    },

    removeRow(row) {
      const hasContent = Object.values(row.terms || {}).some(
        (t) => (t || "").trim() !== ""
      );
      if (hasContent) {
        const ok = window.confirm(this.__("Remove this term from the glossary?"));
        if (!ok) return;
      }
      this.rows = this.rows.filter((r) => r !== row);
      this.markDirty();
    },

    updateTerm(row, lang, value) {
      row.terms = { ...row.terms, [lang]: value };
      this.markDirty();
    },

    updateStyleInstruction(lang, idx, value) {
      const list = [...(this.styleDraft[lang] || [])];
      list.splice(idx, 1, value);
      this.styleDraft = { ...this.styleDraft, [lang]: list };
      this.markDirty();
    },

    addStyleInstruction(lang, afterIdx = null) {
      const list = [...(this.styleDraft[lang] || [])];
      const insertAt = afterIdx === null ? list.length : afterIdx + 1;
      list.splice(insertAt, 0, "");
      this.styleDraft = { ...this.styleDraft, [lang]: list };
      this.markDirty();
      this.$nextTick(() => {
        const cards = this.$el.querySelectorAll(".gp-style-card");
        const card = cards[this.languages.indexOf(lang)];
        if (!card) return;
        const inputs = card.querySelectorAll(".gp-style-item__input");
        const target = inputs[insertAt];
        if (target) target.focus();
      });
    },

    removeStyleInstruction(lang, idx) {
      const list = [...(this.styleDraft[lang] || [])];
      list.splice(idx, 1);
      this.styleDraft = { ...this.styleDraft, [lang]: list };
      this.markDirty();
    },

    markDirty() {
      this.dirty = this.computeDirty();
    },

    computeDirty() {
      const normRows = (rows) =>
        rows
          .map((r) => {
            const terms = {};
            for (const k of Object.keys(r.terms || {}).sort()) {
              const v = (r.terms[k] || "").trim();
              if (v !== "") terms[k] = v;
            }
            return terms;
          })
          .filter((t) => Object.keys(t).length > 0);

      if (
        JSON.stringify(normRows(this.rows)) !==
        JSON.stringify(normRows(this.originalRows))
      ) {
        return true;
      }

      const normStyle = (list) =>
        (Array.isArray(list) ? list : [])
          .map((v) => (v || "").trim())
          .filter(Boolean);

      for (const lang of this.languages) {
        if (
          JSON.stringify(normStyle(this.styleDraft[lang])) !==
          JSON.stringify(normStyle(this.originalStyleDraft[lang]))
        ) {
          return true;
        }
      }
      return false;
    },

    async save() {
      if (this.saving) return;
      this.saving = true;
      try {
        const entries = this.rows
          .map((r) => ({
            id: String(r.id || "").startsWith("new-") ? null : r.id,
            terms: r.terms,
          }))
          .filter((r) =>
            Object.values(r.terms || {}).some((t) => (t || "").trim() !== "")
          );

        const styles = {};
        for (const lang of this.languages) {
          styles[lang] = (this.styleDraft[lang] || [])
            .map((v) => (v || "").trim())
            .filter(Boolean);
        }

        const { data } = await this.$axios.post(
          "/cp/ai-translation-glossary/save",
          { entries, styles }
        );

        this.applyServerState(data);
        this.syncWarnings = data.warnings || [];

        if (this.syncWarnings.length === 0) {
          this.$toast.success(this.__("Glossary & style rules saved and synced to DeepL."));
        } else {
          this.$toast.error(this.__("Saved, but the DeepL sync reported problems."));
        }
      } catch (e) {
        const msg =
          (e && e.response && e.response.data && e.response.data.error) ||
          this.__("Could not save. Please try again.");
        this.$toast.error(msg);
      } finally {
        this.saving = false;
      }
    },
  },
};
</script>

<style scoped>
/* Uses --tp-* tokens from resources/css/app.css (same as the other addon pages) */

.gp-body {
  padding-bottom: 3rem;
}

.gp-dirty-pill {
  font-size: 0.75rem;
  font-weight: 500;
  padding: 0.25rem 0.65rem;
  border-radius: 999px;
  background: var(--tp-warning-bg);
  color: var(--tp-warning-text);
  border: 1px solid var(--tp-warning-border);
}

.gp-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.5rem 0.95rem;
  border-radius: var(--tp-radius);
  border: 1px solid var(--tp-border-strong);
  background: var(--tp-surface);
  color: var(--tp-text);
  font-size: 0.875rem;
  font-weight: 500;
  cursor: pointer;
  transition: background-color 120ms ease, border-color 120ms ease, box-shadow 120ms ease;
  line-height: 1;
}
.gp-btn:hover:not(:disabled) {
  background: var(--tp-surface-muted);
}
.gp-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.gp-btn--primary {
  background: var(--tp-accent);
  border-color: var(--tp-accent);
  color: #fff;
}
.gp-btn--primary:hover:not(:disabled) {
  background: var(--tp-accent-bright);
  border-color: var(--tp-accent-bright);
}
.gp-btn--primary:disabled {
  background: var(--tp-surface-muted);
  border-color: var(--tp-border);
  color: var(--tp-text-muted);
  opacity: 0.7;
}
.gp-btn--add svg {
  width: 12px;
  height: 12px;
  stroke: currentColor;
  fill: none;
}

.gp-spinner {
  width: 14px;
  height: 14px;
  animation: gp-spin 0.9s linear infinite;
  stroke: currentColor;
  stroke-dasharray: 40;
  stroke-dashoffset: 20;
}
@keyframes gp-spin {
  to {
    transform: rotate(360deg);
  }
}

.gp-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  padding: 3rem 1.5rem;
  border: 1px dashed var(--tp-border-strong);
  border-radius: var(--tp-radius-lg);
  color: var(--tp-text-muted);
  background: var(--tp-surface);
}
.gp-state--error {
  border-color: var(--tp-danger-border);
  color: var(--tp-danger-text);
  background: var(--tp-danger-bg);
}
.gp-state--inline {
  padding: 2rem 1.25rem;
}
.gp-state__spinner {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 3px solid var(--tp-border);
  border-top-color: var(--tp-accent);
  animation: gp-spin 0.9s linear infinite;
}

.gp-warnings {
  margin-bottom: 1rem;
  padding: 0.85rem 1.1rem;
  border-radius: var(--tp-radius);
  border: 1px solid var(--tp-warning-border);
  background: var(--tp-warning-bg);
  color: var(--tp-warning-text);
  font-size: 0.85rem;
  line-height: 1.5;
}
.gp-warnings p {
  margin: 0;
}
.gp-warnings p + p {
  margin-top: 0.35rem;
}

.gp-tabs {
  display: inline-flex;
  gap: 0.25rem;
  padding: 0.25rem;
  margin-bottom: 1rem;
  border: 1px solid var(--tp-border);
  border-radius: 10px;
  background: var(--tp-surface-muted);
}
.gp-tab {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.45rem 0.95rem;
  border: none;
  border-radius: 7px;
  background: transparent;
  color: var(--tp-text-muted);
  font-size: 0.875rem;
  font-weight: 500;
  cursor: pointer;
  line-height: 1;
  transition: background-color 120ms ease, color 120ms ease, box-shadow 120ms ease;
}
.gp-tab:hover {
  color: var(--tp-text);
}
.gp-tab--active {
  background: var(--tp-surface);
  color: var(--tp-text);
  box-shadow: var(--tp-shadow);
  font-weight: 600;
}
.gp-tab__count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 0.35rem;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.08);
  font-size: 0.7rem;
  font-weight: 600;
  line-height: 1;
}

.gp-panel__head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}
.gp-panel__intro {
  font-size: 0.875rem;
  color: var(--tp-text-muted);
  line-height: 1.5;
  margin: 0.35rem 0 0;
  max-width: 72ch;
}
.gp-panel__intro--muted {
  font-size: 0.8125rem;
  opacity: 0.8;
}

.gp-sync-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.72rem;
  font-weight: 500;
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  background: var(--tp-success-soft);
  color: var(--tp-success);
  white-space: nowrap;
  flex-shrink: 0;
}
.gp-sync-badge svg {
  width: 10px;
  height: 10px;
  stroke: currentColor;
  fill: none;
}

.gp-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.85rem;
  flex-wrap: wrap;
}
.gp-search {
  position: relative;
  flex: 1 1 240px;
  max-width: 360px;
}
.gp-search__icon {
  position: absolute;
  left: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  width: 16px;
  height: 16px;
  stroke: var(--tp-text-muted);
  fill: none;
}
.gp-search__input {
  width: 100%;
  padding: 0.55rem 0.85rem 0.55rem 2.25rem;
  border-radius: var(--tp-radius);
  border: 1px solid var(--tp-border-strong);
  background: var(--tp-surface);
  font-size: 0.875rem;
  color: var(--tp-text);
  transition: border-color 120ms ease, box-shadow 120ms ease;
}
.gp-search__input:focus {
  outline: none;
  border-color: var(--tp-accent);
  box-shadow: 0 0 0 2px var(--tp-accent-ring);
}

.gp-table-wrap {
  overflow-x: auto;
  border: 1px solid var(--tp-border);
  border-radius: var(--tp-radius-lg);
  background: var(--tp-surface);
}
.gp-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  min-width: 480px;
}
.gp-table th {
  text-align: left;
  padding: 0.65rem 0.85rem;
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--tp-text);
  background: var(--tp-surface-muted);
  border-bottom: 1px solid var(--tp-border);
}
.gp-table__lang {
  margin-right: 0.4rem;
}
.gp-table__code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.68rem;
  padding: 0.05rem 0.4rem;
  border-radius: 4px;
  background: var(--tp-surface);
  color: var(--tp-text-muted);
  border: 1px solid var(--tp-border);
  text-transform: none;
}
.gp-table td {
  padding: 0.25rem 0.4rem;
  border-bottom: 1px solid var(--tp-border);
}
.gp-table tbody tr:last-child td {
  border-bottom: none;
}
.gp-table__actions-col {
  width: 44px;
  text-align: center;
}

.gp-cell-input {
  width: 100%;
  border: 1px solid transparent;
  border-radius: 6px;
  background: transparent;
  padding: 0.45rem 0.5rem;
  font-size: 0.875rem;
  color: var(--tp-text);
  transition: border-color 120ms ease, background-color 120ms ease, box-shadow 120ms ease;
}
.gp-cell-input:hover {
  background: var(--tp-surface-muted);
}
.gp-cell-input:focus {
  outline: none;
  background: var(--tp-surface);
  border-color: var(--tp-accent);
  box-shadow: 0 0 0 2px var(--tp-accent-ring);
}

.gp-row-remove {
  display: inline-grid;
  place-items: center;
  width: 26px;
  height: 26px;
  border-radius: 6px;
  border: none;
  background: transparent;
  color: var(--tp-text-muted);
  cursor: pointer;
  opacity: 0;
  transition: opacity 120ms ease, background-color 120ms ease, color 120ms ease;
}
.gp-row:hover .gp-row-remove,
.gp-row:focus-within .gp-row-remove {
  opacity: 1;
}
.gp-row-remove:hover {
  background: var(--tp-danger-bg);
  color: var(--tp-danger-text);
}
.gp-row-remove svg {
  width: 12px;
  height: 12px;
  stroke: currentColor;
  fill: none;
}

.gp-muted {
  padding: 0.85rem 1rem;
  margin: 0;
  color: var(--tp-text-muted);
  font-size: 0.85rem;
}

.gp-styles {
  display: grid;
  grid-template-rows: repeat(2, minmax(0, auto));
  grid-auto-flow: column;
  grid-auto-columns: minmax(320px, 1fr);
  gap: 0.85rem;
  align-items: start;
}
@media (max-width: 720px) {
  .gp-styles {
    grid-template-rows: none;
    grid-auto-flow: row;
    grid-auto-columns: unset;
    grid-template-columns: 1fr;
  }
}
.gp-style-card {
  background: var(--tp-surface);
  border: 1px solid var(--tp-border);
  border-radius: var(--tp-radius-lg);
  padding: 1rem 1.1rem 1.1rem;
  box-shadow: var(--tp-shadow);
}
.gp-style-card__head {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.65rem;
  flex-wrap: wrap;
}
.gp-style-card__head h3 {
  font-size: 0.95rem;
  font-weight: 600;
  margin: 0;
  color: var(--tp-text);
}

.gp-textarea {
  width: 100%;
  resize: vertical;
  min-height: 96px;
  padding: 0.65rem 0.8rem;
  border-radius: var(--tp-radius);
  border: 1px solid var(--tp-border-strong);
  background: var(--tp-surface);
  font-size: 0.875rem;
  line-height: 1.45;
  color: var(--tp-text);
  font-family: inherit;
  transition: border-color 120ms ease, box-shadow 120ms ease;
}
.gp-textarea:focus {
  outline: none;
  border-color: var(--tp-accent);
  box-shadow: 0 0 0 2px var(--tp-accent-ring);
}

.gp-style-list {
  list-style: none;
  margin: 0 0 0.6rem;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.gp-style-item {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
}
.gp-style-item__num {
  flex-shrink: 0;
  width: 20px;
  height: 20px;
  margin-top: 0.55rem;
  display: grid;
  place-items: center;
  border-radius: 999px;
  background: var(--tp-surface-muted);
  border: 1px solid var(--tp-border);
  color: var(--tp-text-muted);
  font-size: 0.7rem;
  font-weight: 600;
  line-height: 1;
}
.gp-style-item__input {
  flex: 1 1 auto;
  min-height: 52px;
}
.gp-style-item__remove {
  flex-shrink: 0;
  display: grid;
  place-items: center;
  width: 26px;
  height: 26px;
  margin-top: 0.4rem;
  border-radius: 6px;
  border: none;
  background: transparent;
  color: var(--tp-text-muted);
  cursor: pointer;
  transition: background-color 120ms ease, color 120ms ease;
}
.gp-style-item__remove:hover {
  background: var(--tp-danger-bg);
  color: var(--tp-danger-text);
}
.gp-style-item__remove svg {
  width: 13px;
  height: 13px;
  stroke: currentColor;
  fill: none;
}
.gp-style-add {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.4rem 0.7rem;
  border-radius: 6px;
  border: 1px dashed var(--tp-border-strong);
  background: transparent;
  color: var(--tp-text-muted);
  font-size: 0.8rem;
  font-weight: 500;
  cursor: pointer;
  transition: color 120ms ease, border-color 120ms ease, background-color 120ms ease;
}
.gp-style-add:hover {
  color: var(--tp-accent);
  border-color: var(--tp-accent);
  background: var(--tp-accent-soft);
}
.gp-style-add svg {
  width: 13px;
  height: 13px;
  stroke: currentColor;
  fill: none;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
