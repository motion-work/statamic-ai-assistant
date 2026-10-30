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
          <p class="translation-page__eyebrow">{{ __('AI assistant') }}</p>
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
        <!-- First: it decides whether Claude follows the role alone. -->
        <section class="ax-card">
          <div class="ax-card__head">
            <h2 class="ax-card__title">{{ __('Claude app') }}</h2>
            <p class="ax-card__desc">{{ __('Who can work on this site through the Claude app, and how far. The Control Panel role always applies as well: Claude never does more than the account may do in the Control Panel.') }}</p>
          </div>

          <div class="ax-modes" role="radiogroup" :aria-label="__('Claude app')">
            <label class="ax-mode" :class="{ 'ax-mode--on': !access.claude_restricted }">
              <input type="radio" class="ax-box" name="ax-claude-mode" :checked="!access.claude_restricted" @change="access.claude_restricted = false" />
              <span class="ax-mode__text">
                <span class="ax-mode__title">{{ __('Same as the Control Panel') }}</span>
                <span class="ax-mode__desc">{{ __('Everyone with a Control Panel account can use Claude, as far as their role allows.') }}</span>
              </span>
            </label>
            <label class="ax-mode" :class="{ 'ax-mode--on': access.claude_restricted }">
              <input type="radio" class="ax-box" name="ax-claude-mode" :checked="access.claude_restricted" @change="access.claude_restricted = true" />
              <span class="ax-mode__text">
                <span class="ax-mode__title">{{ __('Set per role and user') }}</span>
                <span class="ax-mode__desc">{{ __('Each role and user gets a Claude level in the table below. Without one, Claude cannot be used.') }}</span>
              </span>
            </label>
          </div>
        </section>

        <!-- One table: who may do what. Roles set the defaults, users the exceptions. -->
        <section v-if="columns.length" class="ax-card ax-card--table">
          <div class="ax-card__head">
            <h2 class="ax-card__title">{{ __('Who may do what') }}</h2>
            <p class="ax-card__desc">{{ __('Roles set what their users may do. Change a single user only for exceptions. Super admins may always do everything and are not listed.') }}</p>
          </div>

          <div class="ax-toolbar">
            <div class="ax-search">
              <svg class="ax-search__icon" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="11" cy="11" r="7" fill="none" stroke-width="2" />
                <path d="M20 20l-3.5-3.5" fill="none" stroke-width="2" stroke-linecap="round" />
              </svg>
              <input v-model="userFilter" type="search" class="ax-search__input" :placeholder="__('Filter users by name or email…')" />
            </div>
            <label v-if="aiEnabled" class="ax-inline">
              {{ __('AI agent: default max.') }}
              <input type="number" min="1" :max="ceiling" class="ax-num" :value="access.agent.limits.default" @input="setDefaultLimit($event.target.value)" />
              {{ __('entries per request') }}
            </label>
          </div>

          <div class="ax-table-wrap">
            <table class="ax-table">
              <thead>
                <tr>
                  <th scope="col" class="ax-table__who"><span class="sr-only">{{ __('Who') }}</span></th>
                  <th v-for="col in columns" :key="col.key" scope="col" :title="col.description">{{ col.label }}</th>
                </tr>
              </thead>
              <tbody>
                <tr class="ax-table__group">
                  <th :colspan="columns.length + 1" scope="colgroup">{{ __('Roles') }}</th>
                </tr>
                <tr v-if="roles.length === 0">
                  <td :colspan="columns.length + 1" class="ax-hint">{{ __('No roles found.') }}</td>
                </tr>
                <tr v-for="role in roles" :key="'r-' + role.handle">
                  <th scope="row" class="ax-table__who">
                    <span class="ax-who__name">{{ role.title || role.handle }}</span>
                  </th>
                  <td v-for="col in columns" :key="col.key">
                    <select
                      v-if="col.key === 'claude'"
                      class="ax-select" :class="{ 'ax-select--none': roleLevel(role.handle) === 'none' }"
                      :value="roleLevel(role.handle)" :aria-label="col.label + ': ' + (role.title || role.handle)"
                      @change="setRoleLevel(role.handle, $event.target.value)"
                    >
                      <option v-for="level in claudeLevels" :key="level.key" :value="level.key">{{ level.label }}</option>
                    </select>
                    <span v-else class="ax-cell">
                      <input
                        type="checkbox" class="ax-box" :checked="hasRole(col.key, role.handle)"
                        :aria-label="col.label + ': ' + (role.title || role.handle)"
                        @change="toggleRole(col.key, role.handle)"
                      />
                      <label v-if="col.key === 'agent' && hasRole('agent', role.handle)" class="ax-limit">
                        {{ __('max.') }}
                        <input
                          type="number" min="1" :max="ceiling" class="ax-num ax-num--sm"
                          :value="access.agent.limits.roles[role.handle] || ''"
                          :placeholder="String(access.agent.limits.default)"
                          :title="__('Max entries per request for this role (blank = default)')"
                          @input="setRoleLimit(role.handle, $event.target.value)"
                        />
                      </label>
                    </span>
                  </td>
                </tr>

                <tr class="ax-table__group">
                  <th :colspan="columns.length + 1" scope="colgroup">{{ __('Users') }}</th>
                </tr>
                <tr v-if="filteredUsers.length === 0">
                  <td :colspan="columns.length + 1" class="ax-hint">{{ __('No users match.') }}</td>
                </tr>
                <tr v-for="user in filteredUsers" :key="'u-' + user.id">
                  <th scope="row" class="ax-table__who">
                    <span class="ax-who__name">{{ user.name }}</span>
                    <span v-if="user.email && user.email !== user.name" class="ax-who__email">{{ user.email }}</span>
                    <span class="ax-who__roles">
                      <span v-for="handle in user.roles || []" :key="handle" class="ax-tag">{{ roleTitle(handle) }}</span>
                      <span v-if="!(user.roles || []).length" class="ax-who__email">{{ __('No role') }}</span>
                    </span>
                  </th>
                  <td v-for="col in columns" :key="col.key">
                    <select
                      v-if="col.key === 'claude'"
                      class="ax-select"
                      :class="{ 'ax-select--own': userLevel(user.id) !== 'role', 'ax-select--none': effectiveClaude(user) === 'none' }"
                      :value="userLevel(user.id)" :aria-label="col.label + ': ' + user.name"
                      @change="setUserLevel(user.id, $event.target.value)"
                    >
                      <option value="role">{{ __('Like role') }} ({{ levelLabel(roleLevelForUser(user)) }})</option>
                      <option v-for="level in claudeLevels" :key="level.key" :value="level.key">{{ level.label }}</option>
                    </select>
                    <span v-else-if="viaRole(col.key, user)" class="ax-cell ax-cell--via" :title="__('Granted through the user\'s role. Change it on the role.')">
                      <input type="checkbox" class="ax-box" checked disabled :aria-label="col.label + ': ' + user.name" />
                      <span class="ax-via">{{ __('via role') }}</span>
                      <label v-if="col.key === 'agent'" class="ax-limit">
                        {{ __('max.') }}
                        <input
                          type="number" min="1" :max="ceiling" class="ax-num ax-num--sm"
                          :value="access.agent.limits.users[user.id] || ''" :placeholder="String(limitFromRoles(user))"
                          :title="__('Max entries per request for this user (blank = default)')"
                          @input="setUserLimit(user.id, $event.target.value)"
                        />
                      </label>
                    </span>
                    <span v-else class="ax-cell">
                      <input
                        type="checkbox" class="ax-box" :checked="hasUser(col.key, user.id)"
                        :aria-label="col.label + ': ' + user.name"
                        @change="toggleUser(col.key, user.id)"
                      />
                      <label v-if="col.key === 'agent' && hasUser('agent', user.id)" class="ax-limit">
                        {{ __('max.') }}
                        <input
                          type="number" min="1" :max="ceiling" class="ax-num ax-num--sm"
                          :value="access.agent.limits.users[user.id] || ''" :placeholder="String(limitFromRoles(user))"
                          :title="__('Max entries per request for this user (blank = default)')"
                          @input="setUserLimit(user.id, $event.target.value)"
                        />
                      </label>
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <dl class="ax-legend">
            <div v-for="col in columns" :key="col.key">
              <dt>{{ col.label }}</dt>
              <dd>{{ col.description }}</dd>
            </div>
          </dl>
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
      aiEnabled: true,
      access: this.emptyAccess(),
      original: '{}',
    };
  },

  computed: {
    // Table columns: the Claude level (only when set per role), then the AI features.
    columns() {
      const claude = this.access.claude_restricted
        ? [{ key: 'claude', label: this.__('Claude app'), description: this.claudeLevels.slice(1).map((l) => l.label + ': ' + l.description).join(' ') }]
        : [];
      return [...claude, ...this.features];
    },

    features() {
      const agent = [
        { key: 'agent', label: this.__('AI agent'), description: this.__('Generate and chat with the agent to create or update entries.') },
        { key: 'bulk_translations', label: this.__('Bulk translations'), description: this.__('Translate many entries at once from the Bulk translations tool.') },
        { key: 'agent_settings', label: this.__('AI instructions'), description: this.__('Edit the site instructions and the block and field hints on the AI instructions page.') },
        { key: 'advanced_tools', label: this.__('Structure tools'), description: this.__('Let the agent create and modify collections, blueprints and taxonomies. Structural changes are applied immediately — grant with care.') },
      ];
      return this.aiEnabled ? agent : [];
    },

    claudeLevels() {
      return [
        { key: 'none', label: this.__('No access') },
        { key: 'read', label: this.__('Read'), description: this.__('Find and read entries, the content model, the asset library, the glossary and style rules. Change nothing.') },
        { key: 'edit', label: this.__('Edit'), description: this.__('Also edit and translate entries, compose and duplicate them, upload images, and change the glossary and style rules.') },
        { key: 'publish', label: this.__('Publish'), description: this.__('Also publish and unpublish entries.') },
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
        claude_restricted: false,
        claude_none: { roles: [], users: [] },
        claude_read: { roles: [], users: [] },
        claude_edit: { roles: [], users: [] },
        claude_publish: { roles: [], users: [] },
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
        claude_restricted: raw?.claude_restricted === true,
        claude_none: feat('claude_none'),
        claude_read: feat('claude_read'),
        claude_edit: feat('claude_edit'),
        claude_publish: feat('claude_publish'),
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
        this.aiEnabled = data.aiEnabled !== false;
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

    // Claude: one level per role and per user, stored as claude_<level> lists.
    roleLevel(handle) {
      return ['publish', 'edit', 'read'].find((l) => this.access['claude_' + l].roles.includes(handle)) || 'none';
    },

    setRoleLevel(handle, level) {
      ['read', 'edit', 'publish'].forEach((l) => {
        this.access['claude_' + l].roles = this.access['claude_' + l].roles.filter((h) => h !== handle);
      });
      if (level !== 'none') this.access['claude_' + level].roles.push(handle);
    },

    userLevel(id) {
      return ['none', 'read', 'edit', 'publish'].find((l) => this.access['claude_' + l].users.includes(id)) || 'role';
    },

    setUserLevel(id, level) {
      ['none', 'read', 'edit', 'publish'].forEach((l) => {
        this.access['claude_' + l].users = this.access['claude_' + l].users.filter((u) => u !== id);
      });
      if (level !== 'role') this.access['claude_' + level].users.push(id);
    },

    roleLevelForUser(user) {
      const order = ['none', 'read', 'edit', 'publish'];
      return (user.roles || []).map((h) => this.roleLevel(h)).reduce((best, l) => (order.indexOf(l) > order.indexOf(best) ? l : best), 'none');
    },

    effectiveClaude(user) {
      const own = this.userLevel(user.id);
      return own === 'role' ? this.roleLevelForUser(user) : own;
    },

    // A feature the user has through one of their roles (not editable on the user).
    viaRole(feature, user) {
      return (user.roles || []).some((h) => this.hasRole(feature, h));
    },

    // The limit a user gets without one of their own: their roles' highest, else the default.
    limitFromRoles(user) {
      const limits = (user.roles || []).map((h) => this.access.agent.limits.roles[h]).filter((n) => n > 0);
      return limits.length ? Math.max(...limits) : this.access.agent.limits.default;
    },

    roleTitle(handle) {
      const role = this.roles.find((r) => r.handle === handle);
      return role ? role.title || role.handle : handle;
    },

    levelLabel(key) {
      return (this.claudeLevels.find((l) => l.key === key) || {}).label || key;
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
  max-width: 72rem;
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

.ax-card--table {
  padding-bottom: 1rem;
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

.ax-box {
  accent-color: var(--tp-accent, #3b82f6);
  margin: 0;
}

/* Claude mode switch */
.ax-modes {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
  gap: 0.75rem;
}

.ax-mode {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--tp-border, #e5e7eb);
  border-radius: 0.6rem;
  cursor: pointer;
}

.ax-mode input {
  margin-top: 0.2rem;
}

.ax-mode--on {
  background: var(--tp-accent-soft, #eff6ff);
  border-color: var(--tp-accent, #3b82f6);
}

.ax-mode__text {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.ax-mode__title {
  font-weight: 600;
  font-size: 0.9rem;
}

.ax-mode__desc {
  font-size: 0.8rem;
  color: var(--tp-text-muted, #6b7280);
}

/* Toolbar above the table */
.ax-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem 1.5rem;
  margin-bottom: 0.9rem;
}

.ax-search {
  position: relative;
  flex: 1 1 16rem;
  max-width: 22rem;
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
.ax-num,
.ax-select {
  border: 1px solid var(--tp-border, #e5e7eb);
  border-radius: 0.5rem;
  font-size: 0.875rem;
  background: var(--tp-surface, #fff);
  color: var(--tp-text, inherit);
}

.ax-search__input {
  width: 100%;
  padding: 0.45rem 0.6rem 0.45rem 2rem;
}

.ax-inline {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  color: var(--tp-text-muted, #6b7280);
}

.ax-num {
  width: 4.5rem;
  padding: 0.3rem 0.45rem;
  text-align: center;
}

.ax-num--sm {
  width: 3.5rem;
  padding: 0.2rem 0.3rem;
  font-size: 0.8rem;
}

/* The table */
.ax-table-wrap {
  overflow-x: auto;
  border: 1px solid var(--tp-border, #e5e7eb);
  border-radius: 0.6rem;
  max-height: 34rem;
  overflow-y: auto;
}

.ax-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.875rem;
}

.ax-table th,
.ax-table td {
  padding: 0.55rem 0.75rem;
  text-align: left;
  vertical-align: middle;
  border-top: 1px solid var(--tp-border, #f0f1f3);
  white-space: nowrap;
}

.ax-table thead th {
  position: sticky;
  top: 0;
  z-index: 1;
  background: var(--tp-surface-muted, #f9fafb);
  border-top: 0;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--tp-text-muted, #6b7280);
  cursor: help;
}

.ax-table__group th {
  background: var(--tp-surface-muted, #f9fafb);
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-weight: 600;
  color: var(--tp-text-muted, #6b7280);
  padding-top: 0.4rem;
  padding-bottom: 0.4rem;
}

.ax-table tbody tr:not(.ax-table__group):hover {
  background: var(--tp-surface-muted, #fafafa);
}

.ax-table__who {
  width: 1%;
  font-weight: 400;
  white-space: normal !important;
  min-width: 13rem;
}

.ax-who__name {
  display: block;
  font-weight: 500;
}

.ax-who__email {
  display: block;
  font-size: 0.75rem;
  color: var(--tp-text-muted, #6b7280);
}

.ax-who__roles {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  margin-top: 0.25rem;
}

.ax-tag {
  font-size: 0.7rem;
  padding: 0.05rem 0.45rem;
  border-radius: 999px;
  background: var(--tp-surface-muted, #f3f4f6);
  border: 1px solid var(--tp-border, #e5e7eb);
  color: var(--tp-text-muted, #4b5563);
}

.ax-cell {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
}

.ax-cell--via {
  color: var(--tp-text-muted, #6b7280);
}

.ax-via {
  font-size: 0.75rem;
}

.ax-limit {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.75rem;
  color: var(--tp-text-muted, #6b7280);
}

.ax-select {
  padding: 0.3rem 0.5rem;
  min-width: 9rem;
}

.ax-select--own {
  border-color: var(--tp-accent, #3b82f6);
  background: var(--tp-accent-soft, #eff6ff);
  font-weight: 500;
}

.ax-select--none {
  color: var(--tp-text-muted, #9ca3af);
}

.ax-hint {
  font-size: 0.8rem;
  color: var(--tp-text-muted, #6b7280);
}

/* Column explanations under the table */
.ax-legend {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin: 1rem 0 0;
  font-size: 0.8rem;
}

.ax-legend dt {
  font-weight: 600;
}

.ax-legend dd {
  margin: 0.1rem 0 0;
  color: var(--tp-text-muted, #6b7280);
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}
</style>
