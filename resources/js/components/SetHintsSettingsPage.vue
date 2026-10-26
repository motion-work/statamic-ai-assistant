<template>
  <div class="translation-page translation-page--wide">
    <header class="translation-page__hero translation-page__hero--agent">
      <div class="translation-page__hero-main">
        <div class="translation-page__hero-icon" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="14" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" />
          </svg>
        </div>
        <div class="translation-page__hero-text">
          <p class="translation-page__eyebrow">{{ __('BOLD agent') }}</p>
          <h1 class="translation-page__title">{{ __('BOLD agent settings') }}</h1>
          <p class="translation-page__subtitle">
            {{ __('Tell the AI what each page-builder block and blueprint field is for and when to use it. Hints are passed to the model whenever content is generated.') }}
          </p>
        </div>
      </div>
      <div class="translation-page__hero-actions">
        <span v-if="dirty" class="sh-dirty-pill">{{ __('Unsaved changes') }}</span>
        <button
          v-if="!loading && !loadError"
          type="button"
          class="sh-btn sh-btn--ai"
          :disabled="saving || generatingAll || totalUnconfiguredCount === 0"
          :title="totalUnconfiguredCount === 0 ? __('Every block and field is already configured.') : __('Draft AI descriptions for all unconfigured blocks and fields')"
          @click="generateAllUnconfigured"
        >
          <svg v-if="generatingAll" class="sh-spinner" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke-width="3" fill="none" stroke-linecap="round" />
          </svg>
          <svg v-else class="sh-btn__ai-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 3l1.8 4.6L18 9l-4.2 1.6L12 15l-1.8-4.4L6 9l4.2-1.4L12 3z" fill="currentColor" opacity=".9" />
            <path d="M19 14l.9 2.2L22 17l-2.1.8L19 20l-.9-2.2L16 17l2.1-.8L19 14z" fill="currentColor" opacity=".6" />
          </svg>
          <span v-if="generatingAll">{{ __('Drafting :current of :total…', { current: generatingAllProgress.current, total: generatingAllProgress.total }) }}</span>
          <span v-else>{{ __('AI fill unconfigured') }}</span>
          <span v-if="!generatingAll && totalUnconfiguredCount > 0" class="sh-btn__count">{{ totalUnconfiguredCount }}</span>
        </button>
        <button
          type="button"
          class="sh-btn sh-btn--primary"
          :disabled="saving || !dirty"
          @click="save"
        >
          <svg v-if="saving" class="sh-spinner" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke-width="3" fill="none" stroke-linecap="round" />
          </svg>
          <span>{{ saving ? __('Saving…') : __('Save changes') }}</span>
        </button>
      </div>
    </header>

    <div class="sh-body">
      <!-- Figma OAuth: fetch design context when prompts include figma.com links -->
      <section class="sh-figma translation-page__panel" aria-labelledby="sh-figma-heading">
        <div v-if="figmaLoading" class="sh-figma__state">
          <p>{{ __('Loading Figma status…') }}</p>
        </div>
        <div v-else-if="figmaError" class="sh-figma__state sh-figma__state--error">
          <p>{{ figmaError }}</p>
          <button type="button" class="sh-btn" @click="loadFigmaStatus">{{ __('Retry') }}</button>
        </div>
        <template v-else-if="figmaStatus">
          <h2 id="sh-figma-heading" class="translation-page__panel-title">{{ __('Figma integration') }}</h2>
          <p class="sh-figma__intro">
            {{ __('When you paste a Figma file or frame link in the entry generator, we fetch frame names and text layers via the official Figma REST API (OAuth). Register a redirect URL in your Figma app that matches the value below.') }}
          </p>
          <p class="sh-figma__credentials-hint">
            {{ __('Create or open your OAuth app and copy the Client ID and Client secret from') }}
            <a
              href="https://www.figma.com/developers/apps"
              target="_blank"
              rel="noopener noreferrer"
              class="sh-figma__credentials-link"
            >https://www.figma.com/developers/apps</a>
            — {{ __('then add them to .env on the server (not in the Control Panel).') }}
          </p>

          <div v-if="figmaStatus.redirect_uri" class="sh-figma__field">
            <label class="sh-field__label">{{ __('OAuth redirect URL (register this exact URL in your Figma app)') }}</label>
            <input
              type="text"
              readonly
              class="sh-figma__readonly"
              :value="figmaStatus.redirect_uri"
              @click="$event.target.select()"
            />
          </div>

          <div
            v-if="!figmaStatus.app_configured && figmaStatus.is_super"
            class="sh-figma__env"
          >
            <p class="sh-figma__env-lead">
              {{ __('Set these variables in your project’s .env file (never commit real values):') }}
            </p>
            <pre class="sh-figma__env-code" tabindex="0" @click="$event.currentTarget.select()">STATAMIC_AI_ASSISTANT_FIGMA_OAUTH_CLIENT_ID=
STATAMIC_AI_ASSISTANT_FIGMA_OAUTH_CLIENT_SECRET=</pre>
            <p class="sh-figma__env-foot">
              {{ __('After saving .env, run :cmd on the server so Laravel picks up the new values.', { cmd: 'php artisan config:clear' }) }}
            </p>
            <p
              v-if="figmaStatus.has_client_id !== figmaStatus.has_client_secret"
              class="sh-figma__env-warn"
            >
              {{ __('Only one of the two variables appears to be set; both are required.') }}
            </p>
            <p class="sh-figma__env-foot sh-figma__env-foot--muted">
              {{ __('If you previously saved credentials in the CP, remove the legacy file :path.', { path: 'storage/app/statamic-ai-assistant/figma-app.yaml' }) }}
            </p>
          </div>

          <p v-else-if="!figmaStatus.app_configured" class="sh-figma__hint">
            {{ __('Figma OAuth must be configured in the server environment by an administrator before you can connect.') }}
          </p>

          <div v-if="figmaStatus.app_configured" class="sh-figma__connect">
            <template v-if="figmaStatus.connected">
              <p class="sh-figma__connected">
                {{ __('Connected as :handle', { handle: figmaConnectedLabel }) }}
              </p>
              <button
                type="button"
                class="sh-btn"
                :disabled="figmaDisconnecting"
                @click="disconnectFigma"
              >
                {{ figmaDisconnecting ? __('Saving…') : __('Disconnect Figma') }}
              </button>
            </template>
            <template v-else>
              <p class="sh-figma__muted">{{ __('Not connected to Figma.') }}</p>
              <a class="sh-btn sh-btn--primary" href="/cp/ai-block-hints/figma/connect">{{ __('Connect Figma') }}</a>
            </template>
          </div>
        </template>
      </section>

      <!-- Loading -->
      <div v-if="loading" class="sh-state">
        <div class="sh-state__spinner" aria-hidden="true"></div>
        <p>{{ __('Loading blocks…') }}</p>
      </div>

      <!-- Load error -->
      <div v-else-if="loadError" class="sh-state sh-state--error">
        <p>{{ loadError }}</p>
        <button type="button" class="sh-btn" @click="load">{{ __('Retry') }}</button>
      </div>

      <!-- List -->
      <template v-else>
        <!-- Blocks / Fields tabs -->
        <nav class="sh-tabs" role="tablist">
          <button
            type="button"
            class="sh-tab"
            role="tab"
            :class="{ 'sh-tab--active': activeTab === 'blocks' }"
            :aria-selected="activeTab === 'blocks'"
            @click="activeTab = 'blocks'"
          >
            {{ __('Blocks') }}
            <span class="sh-tab__count">{{ sets.length }}</span>
          </button>
          <button
            type="button"
            class="sh-tab"
            role="tab"
            :class="{ 'sh-tab--active': activeTab === 'fields' }"
            :aria-selected="activeTab === 'fields'"
            @click="activeTab = 'fields'"
          >
            {{ __('Fields') }}
            <span class="sh-tab__count">{{ fields.length }}</span>
          </button>
        </nav>

        <p v-if="activeTab === 'fields'" class="sh-tab-hint">
          {{ __('Field hints describe what belongs in a blueprint field (hero title, lead, …) and add writing guidelines like length or tone. A hint applies to every field with that handle, wherever it appears.') }}
        </p>

        <!-- Empty -->
        <div v-if="activeRows.length === 0" class="sh-state">
          <p v-if="activeTab === 'blocks'">{{ __('No replicator or components fields were found in any blueprint.') }}</p>
          <p v-else>{{ __('No hintable fields were found in any blueprint.') }}</p>
        </div>

        <template v-else>
        <!-- Toolbar -->
        <div class="sh-toolbar">
          <div class="sh-search">
            <svg class="sh-search__icon" viewBox="0 0 24 24" aria-hidden="true">
              <circle cx="11" cy="11" r="7" fill="none" stroke-width="2" />
              <path d="M20 20l-3.5-3.5" fill="none" stroke-width="2" stroke-linecap="round" />
            </svg>
            <input
              v-model="search"
              type="search"
              class="sh-search__input"
              :placeholder="activeTab === 'blocks' ? __('Filter blocks by handle, title or location…') : __('Filter fields by handle, title or location…')"
            />
          </div>
          <button
            type="button"
            class="sh-filter-toggle"
            :class="{ 'sh-filter-toggle--active': onlyUnconfigured }"
            :aria-pressed="onlyUnconfigured"
            @click="onlyUnconfigured = !onlyUnconfigured"
          >
            <svg class="sh-filter-toggle__icon" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M4 5h16M7 12h10M10 19h4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            </svg>
            <span>{{ __('Only unconfigured') }}</span>
            <span v-if="unconfiguredCount > 0" class="sh-filter-toggle__count">{{ unconfiguredCount }}</span>
          </button>

          <div class="sh-toolbar__meta">
            <span>{{ countLabel }}</span>
          </div>
        </div>

        <!-- Filtered to nothing -->
        <div v-if="filteredRows.length === 0" class="sh-state">
          <p v-if="onlyUnconfigured && unconfiguredCount === 0">
            {{ activeTab === 'blocks' ? __('Every block has a description and tips. Nice work.') : __('Every field has a description and guidelines. Nice work.') }}
          </p>
          <p v-else>{{ activeTab === 'blocks' ? __('No blocks match the current filters.') : __('No fields match the current filters.') }}</p>
          <button
            v-if="onlyUnconfigured || search"
            type="button"
            class="sh-btn"
            @click="clearFilters"
          >
            {{ __('Clear filters') }}
          </button>
        </div>

        <!-- Cards -->
        <div v-else class="sh-cards">
          <article
            v-for="row in filteredRows"
            :key="activeTab + ':' + row.handle"
            :data-handle="row.handle"
            class="sh-card"
            :class="{ 'sh-card--configured': isConfigured(row.handle) }"
          >
            <header class="sh-card__head">
              <div class="sh-card__title">
                <h2>{{ row.title }}</h2>
                <code class="sh-card__handle">{{ row.handle }}</code>
                <code v-if="activeTab === 'fields' && row.type" class="sh-card__handle sh-card__handle--type">{{ row.type }}</code>
                <span v-if="isConfigured(row.handle)" class="sh-card__badge">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M5 12l4 4 10-10" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                  {{ __('Configured') }}
                </span>
              </div>

              <div class="sh-card__head-right">
                <button
                  type="button"
                  class="sh-ai-fill"
                  :disabled="!!generating[row.handle]"
                  :title="activeTab === 'blocks' ? __('Draft a description and tips based on this block\'s fields') : __('Draft a description and writing guidelines for this field')"
                  @click="generateForRow(row.handle)"
                >
                  <svg v-if="!generating[row.handle]" class="sh-ai-fill__icon" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 3l1.8 4.6L18 9l-4.2 1.6L12 15l-1.8-4.4L6 9l4.2-1.4L12 3z" fill="currentColor" opacity=".9" />
                    <path d="M19 14l.9 2.2L22 17l-2.1.8L19 20l-.9-2.2L16 17l2.1-.8L19 14z" fill="currentColor" opacity=".6" />
                  </svg>
                  <svg v-else class="sh-ai-fill__icon sh-ai-fill__icon--spin" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round" stroke-dasharray="40" stroke-dashoffset="20" />
                  </svg>
                  <span>{{ generating[row.handle] ? __('Drafting…') : __('AI fill') }}</span>
                </button>
              </div>
            </header>

            <!-- AI description -->
            <div class="sh-field">
              <label class="sh-field__label">
                {{ __('AI description') }}
                <span class="sh-field__hint">{{ activeTab === 'blocks' ? __('What is this block? What does it look like?') : __('What content belongs in this field? How is it shown?') }}</span>
              </label>
              <textarea
                v-model="currentDraft[row.handle].ai_description"
                class="sh-textarea"
                rows="3"
                :placeholder="activeTab === 'blocks'
                  ? __('e.g. Large, visually prominent introductory paragraph that appears immediately after the hero. Provides context. 2–4 sentences, 100–300 words.')
                  : __('e.g. Main page headline shown large over the hero image. One short, benefit-driven sentence.')"
                @input="markDirty"
              ></textarea>
            </div>

            <!-- When to use / writing guidelines -->
            <div class="sh-field">
              <label class="sh-field__label">
                {{ activeTab === 'blocks' ? __('When to use') : __('Writing guidelines') }}
                <span class="sh-field__hint">{{ activeTab === 'blocks' ? __('Short trigger phrases the AI should match against.') : __('Concrete rules: length, tone, structure.') }}</span>
              </label>

              <ul class="sh-tips">
                <li
                  v-for="(tip, idx) in currentDraft[row.handle].when_to_use"
                  :key="idx"
                  class="sh-tip"
                >
                  <span class="sh-tip__bullet" aria-hidden="true">•</span>
                  <input
                    type="text"
                    class="sh-tip__input"
                    :value="tip"
                    :placeholder="activeTab === 'blocks' ? __('e.g. Executive summary or overview') : __('e.g. Keep under 60 characters')"
                    @input="updateTip(row.handle, idx, $event.target.value)"
                    @keydown.enter.prevent="handleTipEnter(row.handle, idx)"
                    @keydown.backspace="handleTipBackspace(row.handle, idx, $event)"
                  />
                  <button
                    type="button"
                    class="sh-tip__remove"
                    :aria-label="__('Remove tip')"
                    @click="removeTip(row.handle, idx)"
                  >
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M6 6l12 12M18 6l-12 12" fill="none" stroke-width="2" stroke-linecap="round" />
                    </svg>
                  </button>
                </li>
              </ul>

              <button
                type="button"
                class="sh-add-tip"
                @click="addTip(row.handle)"
              >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M12 5v14M5 12h14" fill="none" stroke-width="2" stroke-linecap="round" />
                </svg>
                {{ __('Add tip') }}
              </button>
            </div>
          </article>
        </div>
        </template>
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
      activeTab: "blocks",
      sets: [],
      fields: [],
      // Shape: { [handle]: { ai_description: string, when_to_use: string[] } }
      draft: {},
      originalDraft: {},
      fieldDraft: {},
      originalFieldDraft: {},
      dirty: false,
      search: "",
      // When true, only blocks without ai_description AND without any when_to_use tip are shown.
      onlyUnconfigured: false,
      // Per-handle AI-fill state: { [handle]: boolean }
      generating: {},
      generatingAll: false,
      generatingAllProgress: { current: 0, total: 0 },
      figmaLoading: true,
      figmaError: null,
      figmaStatus: null,
      figmaDisconnecting: false,
    };
  },

  computed: {
    figmaConnectedLabel() {
      const u = this.figmaStatus && this.figmaStatus.figma_user;
      if (!u) {
        return "—";
      }
      const s = (u.handle || u.email || "").trim();
      return s || "—";
    },

    activeRows() {
      return this.activeTab === "blocks" ? this.sets : this.fields;
    },

    currentDraft() {
      return this.activeTab === "blocks" ? this.draft : this.fieldDraft;
    },

    unconfiguredCount() {
      return this.activeRows.filter((s) => !this.isConfigured(s.handle)).length;
    },

    totalUnconfiguredCount() {
      const blocks = this.sets.filter((s) => !this.isConfiguredIn(this.draft, s.handle)).length;
      const fields = this.fields.filter((f) => !this.isConfiguredIn(this.fieldDraft, f.handle)).length;

      return blocks + fields;
    },

    filteredRows() {
      const q = this.search.trim().toLowerCase();
      const onlyUnconfigured = this.onlyUnconfigured;

      return this.activeRows.filter((s) => {
        if (onlyUnconfigured && this.isConfigured(s.handle)) return false;

        if (!q) return true;

        if (s.handle.toLowerCase().includes(q)) return true;
        if ((s.title || "").toLowerCase().includes(q)) return true;
        if ((s.type || "").toLowerCase().includes(q)) return true;
        return (s.locations || []).some((l) =>
          [l.collection, l.blueprint, l.field]
            .filter(Boolean)
            .some((v) => String(v).toLowerCase().includes(q))
        );
      });
    },

    countLabel() {
      const total = this.activeRows.length;
      const shown = this.filteredRows.length;
      const configured = this.activeRows.filter((s) => this.isConfigured(s.handle)).length;
      if (shown === total) {
        return this.activeTab === "blocks"
          ? this.__n(
              ':configured of :total block configured',
              ':configured of :total blocks configured',
              total,
              { configured, total }
            )
          : this.__n(
              ':configured of :total field configured',
              ':configured of :total fields configured',
              total,
              { configured, total }
            );
      }
      return this.__(':shown of :total shown', { shown, total });
    },
  },

  mounted() {
    this.load();
    this.loadFigmaStatus();
    this.$nextTick(() => {
      const ok = document.querySelector("[data-figma-flash-success]");
      const err = document.querySelector("[data-figma-flash-error]");
      if (ok && this.$toast) {
        this.$toast.success(ok.textContent.trim());
      }
      if (err && this.$toast) {
        this.$toast.error(err.textContent.trim());
      }
    });
  },

  methods: {
    async loadFigmaStatus() {
      this.figmaLoading = true;
      this.figmaError = null;
      try {
        const { data } = await this.$axios.get("/cp/ai-block-hints/figma/status");
        this.figmaStatus = data;
      } catch (e) {
        this.figmaError =
          (e && e.response && e.response.data && e.response.data.error) ||
          this.__("Could not load Figma status.");
        this.figmaStatus = null;
      } finally {
        this.figmaLoading = false;
      }
    },

    async disconnectFigma() {
      this.figmaDisconnecting = true;
      try {
        await this.$axios.post("/cp/ai-block-hints/figma/disconnect");
        this.$toast.success(this.__("Disconnected from Figma."));
        await this.loadFigmaStatus();
      } catch (e) {
        const msg =
          (e && e.response && e.response.data && e.response.data.error) ||
          this.__("Could not disconnect from Figma.");
        this.$toast.error(msg);
      } finally {
        this.figmaDisconnecting = false;
      }
    },

    clearFilters() {
      this.search = "";
      this.onlyUnconfigured = false;
    },

    async load() {
      this.loading = true;
      this.loadError = null;
      try {
        const { data } = await this.$axios.get("/cp/ai-block-hints/list");
        this.applyServerState(data.sets || [], data.fields || []);
      } catch (e) {
        this.loadError =
          (e && e.response && e.response.data && e.response.data.error) ||
          this.__("Could not load blocks.");
      } finally {
        this.loading = false;
      }
    },

    applyServerState(sets, fields) {
      this.sets = sets;
      this.fields = fields;
      this.draft = this.buildDraft(sets);
      this.fieldDraft = this.buildDraft(fields);
      this.originalDraft = this.cloneDraft(this.draft);
      this.originalFieldDraft = this.cloneDraft(this.fieldDraft);
      this.dirty = false;
    },

    buildDraft(rows) {
      const draft = {};
      for (const s of rows) {
        draft[s.handle] = {
          ai_description: s.ai_description || "",
          when_to_use: Array.isArray(s.when_to_use) ? [...s.when_to_use] : [],
        };
      }
      return draft;
    },

    cloneDraft(draft) {
      const out = {};
      for (const k of Object.keys(draft)) {
        out[k] = {
          ai_description: draft[k].ai_description || "",
          when_to_use: [...(draft[k].when_to_use || [])],
        };
      }
      return out;
    },

    isConfigured(handle) {
      return this.isConfiguredIn(this.currentDraft, handle);
    },

    isConfiguredIn(draft, handle) {
      const d = draft[handle];
      if (!d) return false;
      if ((d.ai_description || "").trim() !== "") return true;
      return (d.when_to_use || []).some((t) => (t || "").trim() !== "");
    },

    markDirty() {
      this.dirty = this.computeDirty();
    },

    computeDirty() {
      return (
        this.draftsDiffer(this.draft, this.originalDraft) ||
        this.draftsDiffer(this.fieldDraft, this.originalFieldDraft)
      );
    },

    draftsDiffer(draft, original) {
      const keys = new Set([...Object.keys(draft), ...Object.keys(original)]);
      for (const k of keys) {
        const a = draft[k] || { ai_description: "", when_to_use: [] };
        const b = original[k] || { ai_description: "", when_to_use: [] };
        if ((a.ai_description || "").trim() !== (b.ai_description || "").trim()) {
          return true;
        }
        const at = (a.when_to_use || []).map((x) => (x || "").trim()).filter(Boolean);
        const bt = (b.when_to_use || []).map((x) => (x || "").trim()).filter(Boolean);
        if (at.length !== bt.length) return true;
        for (let i = 0; i < at.length; i++) {
          if (at[i] !== bt[i]) return true;
        }
      }
      return false;
    },

    addTip(handle) {
      if (!this.currentDraft[handle]) return;
      this.currentDraft[handle].when_to_use.push("");
      this.markDirty();
      this.$nextTick(() => {
        // Focus the new input
        const inputs = this.$el.querySelectorAll(
          `.sh-card[data-handle="${handle}"] .sh-tip__input`
        );
        const last = inputs[inputs.length - 1];
        if (last) last.focus();
      });
    },

    updateTip(handle, idx, value) {
      if (!this.currentDraft[handle]) return;
      this.currentDraft[handle].when_to_use.splice(idx, 1, value);
      this.markDirty();
    },

    removeTip(handle, idx) {
      if (!this.currentDraft[handle]) return;
      this.currentDraft[handle].when_to_use.splice(idx, 1);
      this.markDirty();
    },

    handleTipEnter(handle, idx) {
      const list = this.currentDraft[handle].when_to_use;
      // Only add a new tip if the current one has content
      if ((list[idx] || "").trim() === "") return;
      list.splice(idx + 1, 0, "");
      this.markDirty();
      this.$nextTick(() => {
        const inputs = this.$el.querySelectorAll(".sh-tip__input");
        // Locate input for this handle at idx+1 — best-effort
        const allInputs = Array.from(inputs);
        // Fallback: focus the immediately next input
        const current = document.activeElement;
        if (current && current.classList.contains("sh-tip__input")) {
          const pos = allInputs.indexOf(current);
          if (pos !== -1 && allInputs[pos + 1]) {
            allInputs[pos + 1].focus();
          }
        }
      });
    },

    handleTipBackspace(handle, idx, event) {
      const list = this.currentDraft[handle].when_to_use;
      // Remove the tip if it's empty and user presses backspace
      if ((list[idx] || "") === "" && list.length > 0) {
        event.preventDefault();
        list.splice(idx, 1);
        this.markDirty();
      }
    },

    serializeDraft(draft) {
      const payload = {};
      for (const handle of Object.keys(draft)) {
        const d = draft[handle];
        const desc = (d.ai_description || "").trim();
        const tips = (d.when_to_use || [])
          .map((t) => (t || "").trim())
          .filter(Boolean);
        // Always send an entry so the server can remove empty ones
        payload[handle] = {
          ai_description: desc,
          when_to_use: tips,
        };
      }
      return payload;
    },

    async save() {
      if (this.saving) return;
      this.saving = true;
      try {
        const { data } = await this.$axios.post("/cp/ai-block-hints/save", {
          hints: this.serializeDraft(this.draft),
          field_hints: this.serializeDraft(this.fieldDraft),
        });
        this.applyServerState(data.sets || this.sets, data.fields || this.fields);
        this.$toast.success(this.__("BOLD agent settings saved."));
      } catch (e) {
        const msg =
          (e && e.response && e.response.data && e.response.data.error) ||
          this.__("Could not save hints.");
        this.$toast.error(msg);
      } finally {
        this.saving = false;
      }
    },

    async generateForRow(handle) {
      if (!handle || this.generating[handle]) return;

      const draft = this.currentDraft;
      const kind = this.activeTab === "blocks" ? "set" : "field";

      // Confirm overwrite if there's existing content the user might lose.
      const d = draft[handle];
      const hasExisting =
        d &&
        ((d.ai_description || "").trim() !== "" ||
          (d.when_to_use || []).some((t) => (t || "").trim() !== ""));

      if (hasExisting) {
        const ok = window.confirm(
          this.__("Replace the current description and tips with an AI-generated draft?")
        );
        if (!ok) return;
      }

      this.generating = { ...this.generating, [handle]: true };

      try {
        const applied = await this.fetchGeneratedHint(handle, kind, draft);
        if (applied) {
          this.markDirty();
          this.$toast.success(this.__("AI draft applied. Review and save when ready."));
        }
      } catch (e) {
        const msg =
          (e && e.response && e.response.data && e.response.data.error) ||
          this.__("Could not generate hints. Please try again.");
        this.$toast.error(msg);
      } finally {
        this.generating = { ...this.generating, [handle]: false };
      }
    },

    async fetchGeneratedHint(handle, kind, draft) {
      const { data } = await this.$axios.post("/cp/ai-block-hints/generate", {
        handle,
        kind,
      });

      if (!draft[handle]) {
        draft[handle] = { ai_description: "", when_to_use: [] };
      }

      draft[handle].ai_description = data.ai_description || "";
      draft[handle].when_to_use = Array.isArray(data.when_to_use)
        ? [...data.when_to_use]
        : [];

      return true;
    },

    async generateAllUnconfigured() {
      if (this.generatingAll || this.totalUnconfiguredCount === 0) return;

      const jobs = [
        ...this.sets
          .filter((s) => !this.isConfiguredIn(this.draft, s.handle))
          .map((s) => ({ kind: "set", handle: s.handle, draft: this.draft })),
        ...this.fields
          .filter((f) => !this.isConfiguredIn(this.fieldDraft, f.handle))
          .map((f) => ({ kind: "field", handle: f.handle, draft: this.fieldDraft })),
      ];

      const ok = window.confirm(
        this.__n(
          "Draft AI descriptions for :count unconfigured block?",
          "Draft AI descriptions for :count unconfigured blocks and fields?",
          jobs.length,
          { count: jobs.length }
        )
      );
      if (!ok) return;

      this.generatingAll = true;
      this.generatingAllProgress = { current: 0, total: jobs.length };

      let succeeded = 0;
      let failed = 0;

      for (const job of jobs) {
        this.generatingAllProgress = {
          current: this.generatingAllProgress.current + 1,
          total: jobs.length,
        };
        this.generating = { ...this.generating, [job.handle]: true };

        try {
          await this.fetchGeneratedHint(job.handle, job.kind, job.draft);
          succeeded++;
        } catch {
          failed++;
        } finally {
          this.generating = { ...this.generating, [job.handle]: false };
        }
      }

      this.generatingAll = false;
      this.generatingAllProgress = { current: 0, total: 0 };

      if (succeeded > 0) {
        this.markDirty();
      }

      if (failed === 0 && succeeded > 0) {
        this.$toast.success(
          this.__n(
            "Filled :count hint with an AI draft. Review and save when ready.",
            "Filled :count hints with AI drafts. Review and save when ready.",
            succeeded,
            { count: succeeded }
          )
        );
      } else if (succeeded > 0 && failed > 0) {
        this.$toast.error(
          this.__(":succeeded filled, :failed failed. Review drafts and retry failed items.", {
            succeeded,
            failed,
          })
        );
      } else if (failed > 0) {
        this.$toast.error(this.__("Could not generate hints. Please try again."));
      }
    },

    __n(singular, plural, n, replacements) {
      // Fallback trans_choice — Statamic's translator handles :placeholders
      const key = n === 1 ? singular : plural;
      return this.__(key, replacements);
    },
  },
};
</script>

<style scoped>
/* Uses --tp-* tokens from resources/css/app.css (same as DeepL translation page) */

.sh-dirty-pill {
  font-size: 0.75rem;
  font-weight: 500;
  padding: 0.25rem 0.65rem;
  border-radius: 999px;
  background: var(--tp-warning-bg);
  color: var(--tp-warning-text);
  border: 1px solid var(--tp-warning-border);
}

.sh-btn {
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
.sh-btn:hover:not(:disabled) {
  background: var(--tp-surface-muted);
}
.sh-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.sh-btn--primary {
  background: var(--tp-accent);
  border-color: var(--tp-accent);
  color: #fff;
}
.sh-btn--primary:hover:not(:disabled) {
  background: var(--tp-accent-bright);
  border-color: var(--tp-accent-bright);
}
.sh-btn--primary:disabled {
  background: var(--tp-surface-muted);
  border-color: var(--tp-border);
  color: var(--tp-text-muted);
  opacity: 0.7;
}
.sh-btn--ai {
  border-color: var(--tp-accent);
  color: var(--tp-accent);
  background: var(--tp-accent-soft, var(--tp-surface));
}
.sh-btn--ai:hover:not(:disabled) {
  background: var(--tp-accent-soft, var(--tp-surface-muted));
  border-color: var(--tp-accent-bright, var(--tp-accent));
}
.sh-btn--ai:disabled {
  opacity: 0.55;
}
.sh-btn__ai-icon {
  width: 14px;
  height: 14px;
  flex-shrink: 0;
}
.sh-btn__count {
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
.sh-btn--ai .sh-btn__count {
  background: var(--tp-accent);
  color: #fff;
}
.sh-btn--ai:disabled .sh-btn__count {
  background: rgba(0, 0, 0, 0.08);
  color: inherit;
}

.sh-spinner {
  width: 14px;
  height: 14px;
  animation: sh-spin 0.9s linear infinite;
  stroke: currentColor;
  stroke-dasharray: 40;
  stroke-dashoffset: 20;
}
@keyframes sh-spin {
  to {
    transform: rotate(360deg);
  }
}

.sh-body {
  padding-bottom: 3rem;
}

.sh-figma {
  margin-bottom: 1.25rem;
}

.sh-figma .translation-page__panel-title {
  margin-bottom: 0.75rem;
}

.sh-figma__intro {
  font-size: 0.875rem;
  color: var(--tp-text-muted);
  line-height: 1.5;
  margin: 0 0 1rem;
}
.sh-figma__credentials-hint {
  font-size: 0.875rem;
  color: var(--tp-text-muted);
  line-height: 1.5;
  margin: 0 0 1rem;
}
.sh-figma__credentials-link {
  color: var(--color-primary, #3b82f6);
  word-break: break-all;
}
.sh-figma__credentials-link:hover {
  text-decoration: underline;
}
.sh-figma__env {
  margin-bottom: 1rem;
  padding: 1rem 1.1rem;
  border-radius: var(--tp-radius);
  border: 1px solid var(--tp-border-strong);
  background: var(--tp-surface-muted);
}
.sh-figma__env-lead {
  font-size: 0.875rem;
  color: var(--tp-text);
  margin: 0 0 0.65rem;
  line-height: 1.45;
}
.sh-figma__env-code {
  display: block;
  margin: 0 0 0.75rem;
  padding: 0.65rem 0.75rem;
  border-radius: var(--tp-radius);
  border: 1px solid var(--tp-border);
  background: var(--tp-surface);
  font-size: 0.75rem;
  line-height: 1.5;
  color: var(--tp-text);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  white-space: pre-wrap;
  word-break: break-all;
  cursor: text;
  user-select: all;
}
.sh-figma__env-foot {
  font-size: 0.8125rem;
  color: var(--tp-text-muted);
  margin: 0 0 0.5rem;
  line-height: 1.45;
}
.sh-figma__env-foot--muted {
  margin-bottom: 0;
  font-size: 0.75rem;
}
.sh-figma__env-warn {
  font-size: 0.8125rem;
  color: var(--tp-danger-text);
  margin: 0 0 0.5rem;
  line-height: 1.45;
}
.sh-figma__state {
  font-size: 0.875rem;
  color: var(--tp-text-muted);
}
.sh-figma__state--error {
  color: var(--tp-danger-text);
}
.sh-figma__field {
  margin-bottom: 1rem;
}
.sh-figma__readonly,
.sh-figma__input {
  width: 100%;
  max-width: 100%;
  padding: 0.55rem 0.75rem;
  border-radius: var(--tp-radius);
  border: 1px solid var(--tp-border-strong);
  background: var(--tp-surface-muted);
  font-size: 0.8125rem;
  color: var(--tp-text);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
.sh-figma__input {
  background: var(--tp-surface);
  font-family: inherit;
}
.sh-figma__app {
  margin-bottom: 1rem;
}
.sh-figma__app .sh-field + .sh-field {
  margin-top: 0.9rem;
}
.sh-figma__connect {
  padding-top: 0.75rem;
  border-top: 1px solid var(--tp-border);
}
.sh-figma__hint {
  color: var(--tp-text-muted);
  font-size: 0.875rem;
  margin: 0 0 0.75rem;
}
.sh-figma__muted {
  color: var(--tp-text-muted);
  font-size: 0.875rem;
  margin: 0 0 0.5rem;
}
.sh-figma__connected {
  font-size: 0.875rem;
  color: var(--tp-text);
  margin: 0 0 0.5rem;
}
a.sh-btn {
  text-decoration: none;
  display: inline-flex;
}

.sh-state {
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
.sh-state--error {
  border-color: var(--tp-danger-border);
  color: var(--tp-danger-text);
  background: var(--tp-danger-bg);
}
.sh-state__spinner {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 3px solid var(--tp-border);
  border-top-color: var(--tp-accent);
  animation: sh-spin 0.9s linear infinite;
}

.sh-tabs {
  display: inline-flex;
  gap: 0.25rem;
  padding: 0.25rem;
  margin-bottom: 0.9rem;
  border: 1px solid var(--tp-border);
  border-radius: 10px;
  background: var(--tp-surface-muted);
}
.sh-tab {
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
.sh-tab:hover {
  color: var(--tp-text);
}
.sh-tab--active {
  background: var(--tp-surface);
  color: var(--tp-text);
  box-shadow: var(--tp-shadow);
  font-weight: 600;
}
.sh-tab__count {
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

.sh-tab-hint {
  font-size: 0.8125rem;
  color: var(--tp-text-muted);
  line-height: 1.5;
  margin: 0 0 1rem;
  max-width: 72ch;
}

.sh-card__handle--type {
  background: var(--tp-accent-soft, var(--tp-surface-muted));
  color: var(--tp-accent, var(--tp-text-muted));
}

.sh-toolbar {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}
.sh-search {
  position: relative;
  flex: 1 1 280px;
  max-width: 440px;
}
.sh-search__icon {
  position: absolute;
  left: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  width: 16px;
  height: 16px;
  stroke: var(--tp-text-muted);
  fill: none;
}
.sh-search__input {
  width: 100%;
  padding: 0.55rem 0.85rem 0.55rem 2.25rem;
  border-radius: var(--tp-radius);
  border: 1px solid var(--tp-border-strong);
  background: var(--tp-surface);
  font-size: 0.875rem;
  color: var(--tp-text);
  transition: border-color 120ms ease, box-shadow 120ms ease;
}
.sh-search__input:focus {
  outline: none;
  border-color: var(--tp-accent);
  box-shadow: 0 0 0 2px var(--tp-accent-ring);
}
.sh-toolbar__meta {
  color: var(--tp-text-muted);
  font-size: 0.8125rem;
}

.sh-filter-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.5rem 0.85rem;
  border-radius: 8px;
  border: 1px solid var(--tp-border);
  background: var(--tp-surface);
  color: var(--tp-text-muted);
  font-size: 0.8125rem;
  font-weight: 500;
  cursor: pointer;
  line-height: 1;
  transition: background-color 120ms ease, border-color 120ms ease, color 120ms ease, box-shadow 120ms ease;
}
.sh-filter-toggle:hover {
  background: var(--tp-surface-muted, var(--tp-surface));
  color: var(--tp-text, inherit);
  border-color: var(--tp-border-strong, var(--tp-border));
}
.sh-filter-toggle--active {
  background: var(--tp-accent-soft, var(--tp-accent));
  border-color: var(--tp-accent);
  color: var(--tp-accent-contrast, #fff);
  box-shadow: 0 0 0 2px var(--tp-accent-ring, transparent);
}
.sh-filter-toggle--active:hover {
  background: var(--tp-accent);
  border-color: var(--tp-accent);
  color: var(--tp-accent-contrast, #fff);
}
.sh-filter-toggle__icon {
  width: 14px;
  height: 14px;
  flex-shrink: 0;
}
.sh-filter-toggle__count {
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
.sh-filter-toggle--active .sh-filter-toggle__count {
  background: rgba(255, 255, 255, 0.2);
}

.sh-cards {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}
.sh-card {
  background: var(--tp-surface);
  border: 1px solid var(--tp-border);
  border-radius: var(--tp-radius-lg);
  padding: 1.1rem 1.25rem 1.25rem;
  box-shadow: var(--tp-shadow);
  transition: border-color 120ms ease, box-shadow 120ms ease;
}
.sh-card:hover {
  border-color: var(--tp-border-strong);
}
.sh-card--configured {
  border-left: 3px solid var(--tp-success);
}

.sh-card__head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 0.9rem;
  flex-wrap: wrap;
}
.sh-card__title {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  flex-wrap: wrap;
}
.sh-card__title h2 {
  font-size: 1rem;
  font-weight: 600;
  margin: 0;
  letter-spacing: -0.005em;
  color: var(--tp-text);
}
.sh-card__handle {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.76rem;
  padding: 0.1rem 0.5rem;
  border-radius: 4px;
  background: var(--tp-surface-muted);
  color: var(--tp-text-muted);
  border: 1px solid var(--tp-border);
}
.sh-card__badge {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  font-size: 0.72rem;
  font-weight: 500;
  padding: 0.15rem 0.55rem;
  border-radius: 999px;
  background: var(--tp-success-soft);
  color: var(--tp-success);
}
.sh-card__badge svg {
  width: 10px;
  height: 10px;
  stroke: currentColor;
  fill: none;
}

.sh-card__head-right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.55rem;
  max-width: 60%;
}

.sh-card__locations {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  justify-content: flex-end;
}

.sh-ai-fill {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 0.7rem;
  border-radius: 999px;
  border: 1px solid var(--tp-border);
  background: var(--tp-surface-muted);
  color: var(--tp-text);
  font-size: 0.78rem;
  font-weight: 500;
  cursor: pointer;
  line-height: 1;
  transition: background-color 120ms ease, border-color 120ms ease, color 120ms ease;
}
.sh-ai-fill:hover:not(:disabled) {
  background: var(--tp-surface-elevated);
  border-color: var(--tp-border-strong);
  color: var(--tp-accent);
}
.sh-ai-fill:active:not(:disabled) {
  transform: translateY(1px);
}
.sh-ai-fill:disabled {
  opacity: 0.7;
  cursor: progress;
}
.sh-ai-fill__icon {
  width: 14px;
  height: 14px;
  flex-shrink: 0;
}
.sh-ai-fill__icon--spin {
  animation: sh-spin 0.9s linear infinite;
}

.sh-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.72rem;
  padding: 0.2rem 0.55rem;
  border-radius: 6px;
  background: var(--tp-surface-muted);
  color: var(--tp-text-muted);
  border: 1px solid var(--tp-border);
  white-space: nowrap;
}
.sh-chip__sep {
  opacity: 0.5;
}
.sh-chip__part--muted {
  color: var(--tp-text-faint);
}

.sh-field + .sh-field {
  margin-top: 0.9rem;
}
.sh-field__label {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--tp-text);
  margin-bottom: 0.4rem;
}
.sh-field__hint {
  font-weight: 400;
  color: var(--tp-text-muted);
  font-size: 0.76rem;
}

.sh-textarea {
  width: 100%;
  resize: vertical;
  min-height: 72px;
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
.sh-textarea:focus {
  outline: none;
  border-color: var(--tp-accent);
  box-shadow: 0 0 0 2px var(--tp-accent-ring);
}

.sh-tips {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.sh-tip {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.25rem 0.55rem;
  border-radius: 7px;
  border: 1px solid var(--tp-border);
  background: var(--tp-surface);
  transition: border-color 120ms ease, background-color 120ms ease;
}
.sh-tip:focus-within {
  border-color: var(--tp-accent);
  background: var(--tp-surface-muted);
  box-shadow: 0 0 0 2px var(--tp-accent-ring);
}
.sh-tip__bullet {
  color: var(--tp-text-muted);
  font-weight: 700;
  line-height: 1;
  padding-left: 0.2rem;
}
.sh-tip__input {
  flex: 1 1 auto;
  border: none;
  background: transparent;
  padding: 0.4rem 0;
  font-size: 0.875rem;
  color: var(--tp-text);
  min-width: 0;
}
.sh-tip__input:focus {
  outline: none;
}

.sh-tip__remove {
  flex-shrink: 0;
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  border-radius: 5px;
  border: none;
  background: transparent;
  color: var(--tp-text-muted);
  cursor: pointer;
  opacity: 0;
  transition: opacity 120ms ease, background-color 120ms ease, color 120ms ease;
}
.sh-tip:hover .sh-tip__remove,
.sh-tip:focus-within .sh-tip__remove {
  opacity: 1;
}
.sh-tip__remove:hover {
  background: var(--tp-danger-bg);
  color: var(--tp-danger-text);
}
.sh-tip__remove svg {
  width: 12px;
  height: 12px;
  stroke: currentColor;
  fill: none;
}

.sh-add-tip {
  margin-top: 0.5rem;
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.35rem 0.65rem;
  border-radius: 6px;
  border: 1px dashed var(--tp-border-strong);
  background: transparent;
  color: var(--tp-text-muted);
  font-size: 0.8rem;
  font-weight: 500;
  cursor: pointer;
  transition: color 120ms ease, border-color 120ms ease, background-color 120ms ease;
}
.sh-add-tip:hover {
  color: var(--tp-accent);
  border-color: var(--tp-accent);
  background: var(--tp-accent-soft);
}
.sh-add-tip svg {
  width: 12px;
  height: 12px;
  stroke: currentColor;
  fill: none;
}

@media (max-width: 720px) {
  .sh-card__locations {
    max-width: 100%;
    justify-content: flex-start;
  }
}
</style>
