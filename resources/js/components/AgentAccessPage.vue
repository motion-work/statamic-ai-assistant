<template>
  <div class="translation-page translation-page--wide">
    <header class="translation-page__hero translation-page__hero--agent">
      <div class="translation-page__hero-main">
        <div class="translation-page__hero-icon" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round">
            <rect x="5" y="11" width="14" height="10" rx="2" />
            <path d="M8 11V7a4 4 0 0 1 8 0v4" />
            <circle cx="12" cy="16" r="1.4" fill="currentColor" stroke="none" />
          </svg>
        </div>
        <div class="translation-page__hero-text">
          <p class="translation-page__eyebrow">{{ __('AI agent') }}</p>
          <h1 class="translation-page__title">{{ __('Who has access') }}</h1>
          <p class="translation-page__subtitle">
            {{ __('Choose who can use each capability. Everyone with a selected role gets access; add individual users for exceptions. Super admins always have full access.') }}
          </p>
        </div>
      </div>
      <div class="translation-page__hero-actions">
        <span v-if="dirty" class="sh-dirty-pill">{{ __('Unsaved changes') }}</span>
        <button
          type="button"
          class="sh-btn sh-btn--primary"
          :disabled="saving || !dirty || loading"
          @click="save"
        >
          {{ saving ? __('Saving…') : __('Save access') }}
        </button>
      </div>
    </header>

    <div class="ax">
      <!-- Loading / error -->
      <div v-if="loading" class="ax-state">
        <svg class="sh-spinner" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke-width="3" fill="none" stroke-linecap="round" /></svg>
        <p>{{ __('Loading…') }}</p>
      </div>
      <div v-else-if="loadError" class="ax-state ax-state--error">
        <p>{{ loadError }}</p>
        <button type="button" class="sh-btn" @click="load">{{ __('Retry') }}</button>
      </div>

      <template v-else>
        <section v-for="feat in features" :key="feat.key" class="ax-card">
          <div class="ax-card__head">
            <h2 class="ax-card__title">{{ feat.label }}</h2>
            <p class="ax-card__desc">{{ feat.description }}</p>
          </div>

          <div v-if="feat.key === 'agent'" class="ax-default">
            <label class="ax-default__label" :for="'ax-default'">{{ __('Default entries per request') }}</label>
            <input
              id="ax-default"
              type="number" min="1" :max="ceiling" class="ax-num"
              :value="access.agent.limits.default"
              @input="setDefaultLimit($event.target.value)"
            />
            <span class="ax-hint">{{ __('Applies to granted users without a specific limit. Super admins are never limited.') }}</span>
          </div>

          <!-- Roles -->
          <div class="ax-group">
            <div class="ax-group__label">{{ __('Roles') }}</div>
            <p v-if="roles.length === 0" class="ax-hint">{{ __('No roles found.') }}</p>
            <div v-else class="ax-chips">
              <label
                v-for="role in roles"
                :key="role.handle"
                class="ax-chip"
                :class="{ 'ax-chip--on': hasRole(feat.key, role.handle) }"
              >
                <input type="checkbox" class="ax-chip__box" :checked="hasRole(feat.key, role.handle)" @change="toggleRole(feat.key, role.handle)" />
                <span class="ax-chip__text">{{ role.title || role.handle }}</span>
                <input
                  v-if="feat.key === 'agent' && hasRole('agent', role.handle)"
                  type="number" min="1" :max="ceiling" class="ax-num ax-num--inline"
                  :value="access.agent.limits.roles[role.handle] || ''"
                  :placeholder="String(access.agent.limits.default)"
                  :title="__('Max entries per request for this role (blank = default)')"
                  @click.prevent.stop
                  @input="setRoleLimit(role.handle, $event.target.value)"
                />
              </label>
            </div>
          </div>

          <!-- Individual users -->
          <div class="ax-group">
            <div class="ax-group__label">
              {{ __('Individual users') }}
              <span v-if="selectedUserCount(feat.key) > 0" class="ax-count">{{ selectedUserCount(feat.key) }}</span>
            </div>
            <div class="ax-search">
              <svg class="ax-search__icon" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="11" cy="11" r="7" fill="none" stroke-width="2" />
                <path d="M20 20l-3.5-3.5" fill="none" stroke-width="2" stroke-linecap="round" />
              </svg>
              <input v-model="userFilter" type="search" class="ax-search__input" :placeholder="__('Filter users by name or email…')" />
            </div>
            <p v-if="filteredUsers.length === 0" class="ax-hint">{{ __('No users match.') }}</p>
            <ul v-else class="ax-users">
              <li v-for="user in filteredUsers" :key="user.id">
                <label class="ax-user" :class="{ 'ax-user--on': hasUser(feat.key, user.id) }">
                  <input type="checkbox" class="ax-user__box" :checked="hasUser(feat.key, user.id)" @change="toggleUser(feat.key, user.id)" />
                  <span class="ax-user__main">
                    <span class="ax-user__name">{{ user.name }}</span>
                    <span v-if="user.email && user.email !== user.name" class="ax-user__email">{{ user.email }}</span>
                  </span>
                  <input
                    v-if="feat.key === 'agent' && hasUser('agent', user.id)"
                    type="number" min="1" :max="ceiling" class="ax-num ax-num--inline"
                    :value="access.agent.limits.users[user.id] || ''"
                    :placeholder="String(access.agent.limits.default)"
                    :title="__('Max entries per request for this user (blank = default)')"
                    @click.prevent.stop
                    @input="setUserLimit(user.id, $event.target.value)"
                  />
                </label>
              </li>
            </ul>
          </div>
        </section>
      </template>
    </div>
  </div>
</template>

<script>
import axios from 'axios';

export default {
  name: 'AgentAccessPage',

  data() {
    return {
      loading: true,
      saving: false,
      loadError: null,
      roles: [],
      users: [],
      ceiling: 100,
      userFilter: '',
      access: this.emptyAccess(),
      original: '{}',
    };
  },

  computed: {
    features() {
      return [
        { key: 'agent', label: this.__('AI agent'), description: this.__('Generate and chat with the agent to create or update entries.') },
        { key: 'bulk_translations', label: this.__('Bulk translations'), description: this.__('Translate many entries at once from the Bulk translations tool.') },
        { key: 'agent_settings', label: this.__('AI instructions'), description: this.__('Edit the site instructions and the block and field hints on the AI instructions page.') },
        { key: 'advanced_tools', label: this.__('Advanced structure tools'), description: this.__('Let the agent create and modify collections, blueprints and taxonomies. Structural changes are applied immediately — grant with care.') },
      ];
    },

    filteredUsers() {
      const q = this.userFilter.trim().toLowerCase();
      if (!q) return this.users;
      return this.users.filter(
        (u) => (u.name || '').toLowerCase().includes(q) || (u.email || '').toLowerCase().includes(q),
      );
    },

    dirty() {
      return JSON.stringify(this.access) !== this.original;
    },
  },

  mounted() {
    this.load();
  },

  methods: {
    emptyAccess() {
      return {
        agent: { roles: [], users: [], limits: { default: 1, roles: {}, users: {} } },
        bulk_translations: { roles: [], users: [] },
        agent_settings: { roles: [], users: [] },
        advanced_tools: { roles: [], users: [] },
      };
    },

    normalize(raw) {
      const feat = (f) => ({
        roles: Array.isArray(raw?.[f]?.roles) ? [...raw[f].roles] : [],
        users: Array.isArray(raw?.[f]?.users) ? [...raw[f].users] : [],
      });
      const limits = raw?.agent?.limits || {};
      return {
        agent: {
          ...feat('agent'),
          limits: {
            default: Number(limits.default) > 0 ? Number(limits.default) : 1,
            roles: { ...(limits.roles || {}) },
            users: { ...(limits.users || {}) },
          },
        },
        bulk_translations: feat('bulk_translations'),
        agent_settings: feat('agent_settings'),
        advanced_tools: feat('advanced_tools'),
      };
    },

    async load() {
      this.loading = true;
      this.loadError = null;
      try {
        const { data } = await axios.get('/cp/ai-agent-access/data', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        this.roles = data.roles || [];
        this.users = data.users || [];
        this.ceiling = data.ceiling || 100;
        this.access = this.normalize(data.access || {});
        this.original = JSON.stringify(this.access);
      } catch (e) {
        this.loadError =
          (e && e.response && e.response.data && e.response.data.error) ||
          this.__('Could not load the access configuration.');
      } finally {
        this.loading = false;
      }
    },

    hasRole(feature, handle) {
      return this.access[feature].roles.includes(handle);
    },

    toggleRole(feature, handle) {
      const list = this.access[feature].roles;
      const i = list.indexOf(handle);
      if (i === -1) list.push(handle);
      else {
        list.splice(i, 1);
        if (feature === 'agent') delete this.access.agent.limits.roles[handle];
      }
    },

    hasUser(feature, id) {
      return this.access[feature].users.includes(id);
    },

    toggleUser(feature, id) {
      const list = this.access[feature].users;
      const i = list.indexOf(id);
      if (i === -1) list.push(id);
      else {
        list.splice(i, 1);
        if (feature === 'agent') delete this.access.agent.limits.users[id];
      }
    },

    selectedUserCount(feature) {
      return this.access[feature].users.length;
    },

    setDefaultLimit(value) {
      const n = parseInt(value, 10);
      this.access.agent.limits.default = n > 0 ? Math.min(n, this.ceiling) : 1;
    },

    setRoleLimit(handle, value) {
      const n = parseInt(value, 10);
      if (n > 0) this.access.agent.limits.roles[handle] = Math.min(n, this.ceiling);
      else delete this.access.agent.limits.roles[handle];
    },

    setUserLimit(id, value) {
      const n = parseInt(value, 10);
      if (n > 0) this.access.agent.limits.users[id] = Math.min(n, this.ceiling);
      else delete this.access.agent.limits.users[id];
    },

    async save() {
      this.saving = true;
      try {
        const headers = { 'X-Requested-With': 'XMLHttpRequest' };
        const { data } = await axios.post('/cp/ai-agent-access/save', { access: this.access }, { headers });
        this.access = this.normalize(data.access || {});
        this.original = JSON.stringify(this.access);
        if (window.Statamic) Statamic.$toast.success(this.__('Access saved.'));
      } catch (e) {
        const msg = (e && e.response && e.response.data && e.response.data.error) || this.__('Could not save access.');
        if (window.Statamic) Statamic.$toast.error(msg);
      } finally {
        this.saving = false;
      }
    },
  },
};
</script>

<style scoped>
/* Hero actions — same button chrome as SetHintsSettingsPage / TranslationGlossaryPage */
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

.sh-spinner {
  width: 1.25rem;
  height: 1.25rem;
  animation: ax-spin 0.9s linear infinite;
  stroke: currentColor;
  stroke-dasharray: 40;
  stroke-dashoffset: 20;
}

@keyframes ax-spin {
  to {
    transform: rotate(360deg);
  }
}

.ax {
  max-width: 60rem;
}

.ax-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  padding: 3rem 1rem;
  color: var(--tp-text-muted, #6b7280);
}

.ax-state--error {
  color: var(--tp-danger, #b91c1c);
}

.ax-card {
  background: var(--tp-surface, #fff);
  border: 1px solid var(--tp-border, #e5e7eb);
  border-radius: 0.85rem;
  padding: 1.35rem 1.5rem;
  margin-bottom: 1.25rem;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}

.ax-card__head {
  margin-bottom: 1.1rem;
}

.ax-card__title {
  font-size: 1.05rem;
  font-weight: 600;
  margin: 0 0 0.2rem;
}

.ax-card__desc {
  font-size: 0.85rem;
  color: var(--tp-text-muted, #6b7280);
  margin: 0;
}

.ax-default {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem 0.75rem;
  padding: 0.75rem 0.9rem;
  margin-bottom: 1.1rem;
  background: var(--tp-surface-muted, #f9fafb);
  border-radius: 0.6rem;
}

.ax-default__label {
  font-size: 0.85rem;
  font-weight: 500;
}

.ax-group {
  margin-top: 1.1rem;
}

.ax-group__label {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-weight: 600;
  color: var(--tp-text-muted, #6b7280);
  margin-bottom: 0.6rem;
}

.ax-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1.15rem;
  height: 1.15rem;
  padding: 0 0.35rem;
  border-radius: 999px;
  background: var(--tp-accent, #3b82f6);
  color: #fff;
  font-size: 0.7rem;
  letter-spacing: 0;
}

.ax-hint {
  font-size: 0.8rem;
  color: var(--tp-text-muted, #6b7280);
  margin: 0.25rem 0 0;
}

.ax-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.ax-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.4rem 0.7rem;
  border: 1px solid var(--tp-border, #e5e7eb);
  border-radius: 999px;
  cursor: pointer;
  font-size: 0.85rem;
  user-select: none;
  transition: background 0.12s, border-color 0.12s;
}

.ax-chip:hover {
  border-color: var(--tp-accent, #3b82f6);
}

.ax-chip--on {
  background: var(--tp-accent-soft, #eff6ff);
  border-color: var(--tp-accent, #3b82f6);
}

.ax-chip__box,
.ax-user__box {
  accent-color: var(--tp-accent, #3b82f6);
  margin: 0;
}

.ax-search {
  position: relative;
  max-width: 22rem;
  margin-bottom: 0.6rem;
}

.ax-search__icon {
  position: absolute;
  left: 0.6rem;
  top: 50%;
  transform: translateY(-50%);
  width: 1rem;
  height: 1rem;
  stroke: var(--tp-text-muted, #9ca3af);
  pointer-events: none;
}

.ax-search__input,
.ax-num {
  width: 100%;
  border: 1px solid var(--tp-border, #e5e7eb);
  border-radius: 0.5rem;
  font-size: 0.875rem;
  background: var(--tp-surface, #fff);
}

.ax-search__input {
  padding: 0.45rem 0.6rem 0.45rem 2rem;
}

.ax-num {
  width: 5rem;
  padding: 0.3rem 0.45rem;
  text-align: center;
}

.ax-num--inline {
  margin-left: auto;
}

.ax-users {
  list-style: none;
  margin: 0;
  padding: 0;
  max-height: 20rem;
  overflow-y: auto;
  border: 1px solid var(--tp-border, #e5e7eb);
  border-radius: 0.6rem;
}

.ax-users li + li {
  border-top: 1px solid var(--tp-border, #f0f1f3);
}

.ax-user {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem 0.75rem;
  cursor: pointer;
  font-size: 0.875rem;
}

.ax-user:hover {
  background: var(--tp-surface-muted, #f9fafb);
}

.ax-user--on {
  background: var(--tp-accent-soft, #eff6ff);
}

.ax-user__main {
  display: flex;
  flex-direction: column;
  line-height: 1.3;
  min-width: 0;
}

.ax-user__email {
  font-size: 0.75rem;
  color: var(--tp-text-muted, #6b7280);
}
</style>
