<template>
  <section class="aiu-card aiu-semantic">
    <div class="aiu-card__head aiu-semantic__head">
      <div>
        <h2 class="aiu-card__title">{{ __('Semantic search') }}</h2>
        <p class="aiu-card__desc">{{ __('Finds entries by meaning for the AI agent and the Claude connector. While it cannot run, the keyword search is used.') }}</p>
      </div>
      <button
        v-if="status && status.available"
        type="button"
        class="aiu-semantic__rebuild"
        :disabled="rebuilding"
        @click="rebuild"
      >
        {{ rebuilding ? __('Queuing…') : __('Rebuild index') }}
      </button>
    </div>

    <p v-if="loadError" class="aiu-muted">{{ __('The index status could not be loaded.') }}</p>
    <p v-else-if="!status" class="aiu-muted">{{ __('Loading…') }}</p>
    <p v-else-if="!status.available" class="aiu-muted">{{ reasonText(status.reason) }}</p>
    <template v-else>
      <table class="aiu-table aiu-semantic__table">
        <thead>
          <tr>
            <th>{{ __('Site') }}</th>
            <th class="aiu-num">{{ __('Entries indexed') }}</th>
            <th>{{ __('Updated') }}</th>
            <th>{{ __('Status') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="site in status.sites" :key="site.site">
            <td>{{ site.name }}</td>
            <td class="aiu-num">{{ __(':indexed of :total', { indexed: site.indexed, total: site.entries }) }}</td>
            <td class="aiu-nowrap">{{ site.updated_at ? dateTime(site.updated_at) : '—' }}</td>
            <td>
              <span class="aiu-semantic__state" :class="{ 'aiu-semantic__state--ready': !site.reason, 'aiu-semantic__state--muted': site.reason === 'no_entries' }">{{ reasonText(site.reason) }}</span>
            </td>
          </tr>
        </tbody>
      </table>
      <p class="aiu-muted aiu-semantic__model">{{ __('Model: :model', { model: status.model || '—' }) }}</p>
    </template>
  </section>
</template>

<script>
import axios from 'axios';

const REASONS = {
  disabled: 'Switched off in the configuration (STATAMIC_AI_ASSISTANT_SEMANTIC_SEARCH).',
  provider: 'Needs the OpenRouter provider with an API key (OPENROUTER_API_KEY).',
  no_model: 'No embedding model is configured.',
  not_built: 'Not built yet',
  no_entries: 'No entries on this site',
  outdated: 'Built with another model: rebuild the index',
  too_many: 'Too many entries for semantic search: keyword search only',
  unreadable: 'The index could not be read: rebuild it',
};

export default {
  name: 'SemanticSearchCard',

  data() {
    return { status: null, loadError: false, rebuilding: false };
  },

  mounted() {
    this.load();
  },

  methods: {
    async load() {
      try {
        const { data } = await axios.get('/cp/ai-usage/semantic-search', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        this.status = data;
      } catch (e) {
        this.loadError = true;
      }
    },

    async rebuild() {
      this.rebuilding = true;
      try {
        await axios.post('/cp/ai-usage/semantic-search/rebuild', {}, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        Statamic.$toast?.success?.(this.__('The index is being rebuilt on the queue. Only changed entries are sent to the model.'));
      } catch (e) {
        Statamic.$toast?.error?.(e.response?.data?.message || this.__('The index could not be rebuilt.'));
      } finally {
        this.rebuilding = false;
      }
    },

    reasonText(reason) {
      return reason ? this.__(REASONS[reason] || reason) : this.__('Ready for search');
    },

    dateTime(value) {
      return new Date(value).toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' });
    },
  },
};
</script>
