<template>
  <div class="translation-page translation-page--wide">
    <header class="translation-page__hero translation-page__hero--agent">
      <div class="translation-page__hero-main">
        <div class="translation-page__hero-icon" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />
          </svg>
        </div>
        <div class="translation-page__hero-text">
          <p class="translation-page__eyebrow">{{ __('AI assistant') }}</p>
          <h1 class="translation-page__title">{{ __('AI usage') }}</h1>
          <p class="translation-page__subtitle">
            {{ __('What the AI assistant was used for and what it cost, per task, model and person.') }}
          </p>
        </div>
      </div>
      <div v-if="months.length" class="translation-page__hero-actions">
        <label class="aiu-month">
          <span class="sr-only">{{ __('Month') }}</span>
          <select v-model="month" class="aiu-month__select" :disabled="loading" @change="load">
            <option v-for="m in months" :key="m" :value="m">{{ monthLabel(m) }}</option>
          </select>
        </label>
      </div>
    </header>

    <div class="aiu">
      <div v-if="loading && !report" class="aiu-state">
        <svg class="sh-spinner" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke-width="3" fill="none" stroke-linecap="round" /></svg>
        <p>{{ __('Loading…') }}</p>
      </div>
      <div v-else-if="loadError" class="aiu-state aiu-state--error">
        <p>{{ loadError }}</p>
        <button type="button" class="sh-btn" @click="load">{{ __('Retry') }}</button>
      </div>

      <template v-else-if="report">
        <p v-if="!tracking" class="aiu-notice">
          {{ __('Usage tracking is switched off (STATAMIC_AI_ASSISTANT_USAGE_TRACKING), so no new calls are recorded.') }}
        </p>

        <!-- OpenRouter key: spending limit of this site's key -->
        <section v-if="budget" class="aiu-card aiu-budget">
          <div class="aiu-card__head">
            <h2 class="aiu-card__title">{{ __('OpenRouter key') }}</h2>
            <p v-if="budget.available && budget.data.label" class="aiu-card__desc">{{ budget.data.label }}</p>
          </div>
          <p v-if="!budget.available" class="aiu-muted">{{ __('The spending limit could not be loaded from OpenRouter. Try again later.') }}</p>
          <template v-else-if="budget.data.limit !== null && budget.data.limit !== undefined">
            <p class="aiu-budget__figure">
              <strong>{{ money(budget.data.limit_remaining) }}</strong>
              <span class="aiu-muted">{{ __('left of :limit', { limit: money(budget.data.limit) }) }}</span>
            </p>
            <div
              class="aiu-meter aiu-meter--lg"
              role="meter"
              :aria-valuenow="budgetUsedPercent"
              aria-valuemin="0"
              aria-valuemax="100"
              :aria-label="__('Share of the limit used')"
            >
              <span class="aiu-meter__fill" :class="{ 'aiu-meter__fill--warn': budgetUsedPercent >= 80 }" :style="{ width: budgetUsedPercent + '%' }" />
            </div>
            <p class="aiu-muted">{{ budgetResetText }}</p>
          </template>
          <p v-else class="aiu-muted">{{ __('No spending limit is set on this key. Set one on openrouter.ai to cap what this site can spend.') }}</p>
          <dl v-if="budget.available" class="aiu-budget__periods">
            <div><dt>{{ __('Today') }}</dt><dd>{{ money(budget.data.usage_daily) }}</dd></div>
            <div><dt>{{ __('This week') }}</dt><dd>{{ money(budget.data.usage_weekly) }}</dd></div>
            <div><dt>{{ __('This month') }}</dt><dd>{{ money(budget.data.usage_monthly) }}</dd></div>
          </dl>
        </section>

        <!-- Semantic search: index status per site, rebuild -->
        <SemanticSearchCard />

        <div v-if="totals.calls === 0" class="aiu-card aiu-empty">
          <p class="aiu-empty__title">{{ __('No AI calls recorded in :month.', { month: monthLabel(month) }) }}</p>
          <p class="aiu-muted">{{ __('Calls show up here as soon as someone uses the AI agent, an AI field button or an AI translation.') }}</p>
        </div>

        <template v-else>
          <!-- Headline numbers -->
          <div class="aiu-tiles">
            <div class="aiu-tile">
              <span class="aiu-tile__label">{{ __('Cost') }}</span>
              <span class="aiu-tile__value">{{ totals.priced_calls ? money(totals.cost) : '—' }}</span>
              <span class="aiu-tile__hint">{{ costHint }}</span>
            </div>
            <div class="aiu-tile">
              <span class="aiu-tile__label">{{ __('AI calls') }}</span>
              <span class="aiu-tile__value">{{ number(totals.calls) }}</span>
              <span class="aiu-tile__hint">
                {{ __('avg. :seconds s each', { seconds: seconds(totals.duration_ms / totals.calls) }) }}<template v-if="totals.web_searches"> · {{ __(':n web searches', { n: number(totals.web_searches) }) }}</template>
              </span>
            </div>
            <div class="aiu-tile">
              <span class="aiu-tile__label">{{ __('Tokens') }}</span>
              <span class="aiu-tile__value">{{ compact(totals.prompt_tokens + totals.completion_tokens) }}</span>
              <span class="aiu-tile__hint">{{ __(':in in · :out out', { in: compact(totals.prompt_tokens), out: compact(totals.completion_tokens) }) }}</span>
            </div>
            <div class="aiu-tile">
              <span class="aiu-tile__label">{{ __('Fallback model used') }}</span>
              <span class="aiu-tile__value">{{ percent(totals.fallback_rate) }}</span>
              <span class="aiu-tile__hint">{{ __(':n of :total calls', { n: number(totals.fallbacks), total: number(totals.calls) }) }}</span>
            </div>
          </div>

          <!-- Cost (or calls) per day -->
          <section class="aiu-card">
            <div class="aiu-card__head">
              <h2 class="aiu-card__title">{{ totals.priced_calls ? __('Cost per day') : __('Calls per day') }}</h2>
            </div>
            <div class="aiu-days" role="img" :aria-label="dayChartLabel">
              <div
                v-for="day in dayBars"
                :key="day.key"
                class="aiu-days__col"
                tabindex="0"
                :aria-label="day.tooltip"
              >
                <span class="aiu-days__bar" :style="{ height: day.height + '%' }" />
                <span class="aiu-days__tip" role="tooltip">{{ day.tooltip }}</span>
              </div>
            </div>
            <div class="aiu-days__axis" aria-hidden="true">
              <span>{{ dayAxis.first }}</span>
              <span>{{ dayAxis.last }}</span>
            </div>
          </section>

          <!-- Breakdowns -->
          <div class="aiu-grid">
            <section v-for="group in breakdowns" :key="group.id" class="aiu-card aiu-card--table">
              <div class="aiu-card__head">
                <h2 class="aiu-card__title">{{ group.title }}</h2>
              </div>
              <table class="aiu-table">
                <thead>
                  <tr>
                    <th scope="col">{{ group.column }}</th>
                    <th scope="col" class="aiu-num">{{ __('Calls') }}</th>
                    <th scope="col" class="aiu-num">{{ totals.priced_calls ? __('Cost') : __('Tokens') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in group.rows" :key="row.key">
                    <th scope="row">
                      <span class="aiu-table__name" :title="row.label">{{ row.label }}</span>
                      <span class="aiu-meter" aria-hidden="true">
                        <span class="aiu-meter__fill" :style="{ width: row.share + '%' }" />
                      </span>
                    </th>
                    <td class="aiu-num">{{ number(row.calls) }}</td>
                    <td class="aiu-num">{{ totals.priced_calls ? money(row.cost) : compact(row.prompt_tokens + row.completion_tokens) }}</td>
                  </tr>
                </tbody>
              </table>
            </section>
          </div>

          <!-- Most recent calls -->
          <section class="aiu-card aiu-card--table">
            <div class="aiu-card__head">
              <h2 class="aiu-card__title">{{ __('Recent calls') }}</h2>
              <p class="aiu-card__desc">{{ __('The last :n calls this month.', { n: recent.length }) }}</p>
            </div>
            <div class="aiu-table-wrap">
              <table class="aiu-table aiu-table--recent">
                <thead>
                  <tr>
                    <th scope="col">{{ __('Time') }}</th>
                    <th scope="col">{{ __('Task') }}</th>
                    <th scope="col">{{ __('Model') }}</th>
                    <th scope="col">{{ __('Person') }}</th>
                    <th scope="col" class="aiu-num">{{ __('Tokens') }}</th>
                    <th scope="col" class="aiu-num">{{ __('Duration') }}</th>
                    <th scope="col" class="aiu-num">{{ __('Cost') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(call, i) in recent" :key="call.generation_id || i">
                    <td class="aiu-nowrap">{{ dateTime(call.ts) }}</td>
                    <td>{{ taskLabel(call.task) }}</td>
                    <td>
                      <span class="aiu-model">{{ call.model || '—' }}</span>
                      <span v-if="call.fallback" class="aiu-badge" :title="__('Requested: :models', { models: (call.requested || []).join(', ') })">{{ __('fallback') }}</span>
                      <span v-if="call.web_searches" class="aiu-badge aiu-badge--info">{{ __(':n web searches', { n: call.web_searches }) }}</span>
                    </td>
                    <td>{{ userLabel(call.user_id) }}</td>
                    <td class="aiu-num">{{ compact((call.prompt_tokens || 0) + (call.completion_tokens || 0)) }}</td>
                    <td class="aiu-num">{{ seconds(call.duration_ms) }} s</td>
                    <td class="aiu-num">{{ call.cost === null || call.cost === undefined ? '—' : money(call.cost) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <p class="aiu-footnote">
            {{ __('Costs are in US dollars, as OpenRouter bills them. Logs older than :n months are deleted.', { n: retentionMonths }) }}
          </p>
        </template>
      </template>
    </div>
  </div>
</template>

<script>
import axios from 'axios';
import SemanticSearchCard from './SemanticSearchCard.vue';

const TASK_LABELS = {
  agent: 'AI agent',
  light: 'Quick helper tasks',
  field: 'AI field buttons',
  translate: 'AI translations',
  vision: 'Image analysis',
  image: 'Image generation',
  embedding: 'Semantic search',
};

export default {
  name: 'AiUsagePage',

  components: { SemanticSearchCard },

  data() {
    return {
      loading: true,
      loadError: null,
      month: null,
      months: [],
      report: null,
      users: {},
      budget: null,
      provider: null,
      tracking: true,
      retentionMonths: 12,
    };
  },

  computed: {
    totals() {
      return this.report?.totals || {};
    },

    recent() {
      return this.report?.recent || [];
    },

    costHint() {
      if (!this.totals.priced_calls) {
        return this.__('This provider does not report cost.');
      }
      if (this.totals.priced_calls < this.totals.calls) {
        return this.__(':n calls without a reported cost', { n: this.number(this.totals.calls - this.totals.priced_calls) });
      }
      return this.__('as billed by OpenRouter');
    },

    budgetUsedPercent() {
      const data = this.budget?.data;
      if (!data || !data.limit) return 0;
      const used = data.limit - (data.limit_remaining ?? data.limit);
      return Math.max(0, Math.min(100, Math.round((used / data.limit) * 100)));
    },

    budgetResetText() {
      const reset = this.budget?.data?.limit_reset;
      const labels = {
        daily: this.__('The limit resets every day (UTC).'),
        weekly: this.__('The limit resets every Monday (UTC).'),
        monthly: this.__('The limit resets on the 1st of every month (UTC).'),
      };
      return labels[reset] || this.__('The limit does not reset; raise it on openrouter.ai when it runs out.');
    },

    /** One bar per day of the month; days without calls stay empty. */
    dayBars() {
      const priced = this.totals.priced_calls > 0;
      const byKey = Object.fromEntries((this.report?.by_day || []).map((d) => [d.key, d]));
      const [year, month] = (this.month || '').split('-').map(Number);
      const daysInMonth = year ? new Date(year, month, 0).getDate() : 0;
      const value = (d) => (d ? (priced ? d.cost : d.calls) : 0);
      const max = Math.max(...Object.values(byKey).map(value), 0);

      return Array.from({ length: daysInMonth }, (_, i) => {
        const key = `${this.month}-${String(i + 1).padStart(2, '0')}`;
        const day = byKey[key];
        const v = value(day);
        const date = this.dayLabel(key);
        const tooltip = day
          ? `${date}: ${priced ? this.money(day.cost) + ' · ' : ''}${this.__(':n calls', { n: this.number(day.calls) })}`
          : `${date}: ${this.__('no calls')}`;
        return { key, height: max > 0 ? Math.max(v > 0 ? 3 : 0, (v / max) * 100) : 0, tooltip };
      });
    },

    dayAxis() {
      const bars = this.dayBars;
      return bars.length ? { first: this.dayLabel(bars[0].key), last: this.dayLabel(bars[bars.length - 1].key) } : { first: '', last: '' };
    },

    dayChartLabel() {
      return this.totals.priced_calls
        ? this.__('Cost per day in :month', { month: this.monthLabel(this.month) })
        : this.__('Calls per day in :month', { month: this.monthLabel(this.month) });
    },

    breakdowns() {
      return [
        { id: 'task', title: this.__('By task'), column: this.__('Task'), rows: this.rows('by_task', (key) => this.taskLabel(key)) },
        { id: 'model', title: this.__('By model'), column: this.__('Model'), rows: this.rows('by_model', (key) => key) },
        { id: 'user', title: this.__('By person'), column: this.__('Person'), rows: this.rows('by_user', (key) => this.userLabel(key)) },
      ];
    },
  },

  mounted() {
    this.load();
  },

  methods: {
    async load() {
      this.loading = true;
      this.loadError = null;
      try {
        const { data } = await axios.get('/cp/ai-usage/data', {
          params: this.month ? { month: this.month } : {},
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        this.month = data.month;
        this.months = data.months || [];
        this.report = data.summary;
        this.users = data.users || {};
        this.budget = data.budget;
        this.provider = data.provider;
        this.tracking = data.tracking;
        this.retentionMonths = data.retention_months;
      } catch (e) {
        this.loadError = e?.response?.data?.message || this.__('The usage data could not be loaded.');
      } finally {
        this.loading = false;
      }
    },

    /** Rows of a breakdown with their share of the month (cost, else calls). */
    rows(group, label) {
      const priced = this.totals.priced_calls > 0;
      const rows = this.report?.[group] || [];
      const total = priced ? this.totals.cost : this.totals.calls;
      return rows.map((row) => ({
        ...row,
        label: label(row.key),
        share: total > 0 ? Math.round(((priced ? row.cost : row.calls) / total) * 100) : 0,
      }));
    },

    taskLabel(task) {
      return this.__(TASK_LABELS[task] || task || '—');
    },

    userLabel(id) {
      if (!id) return this.__('System');
      return this.users[id] || id;
    },

    locale() {
      return document.documentElement.lang || undefined;
    },

    money(value) {
      const amount = Number(value) || 0;
      const digits = amount !== 0 && Math.abs(amount) < 1 ? 4 : 2;
      return new Intl.NumberFormat(this.locale(), { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: digits }).format(amount);
    },

    number(value) {
      return new Intl.NumberFormat(this.locale()).format(Number(value) || 0);
    },

    compact(value) {
      return new Intl.NumberFormat(this.locale(), { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value) || 0);
    },

    percent(value) {
      return new Intl.NumberFormat(this.locale(), { style: 'percent', maximumFractionDigits: 1 }).format(Number(value) || 0);
    },

    seconds(ms) {
      return new Intl.NumberFormat(this.locale(), { maximumFractionDigits: 1 }).format((Number(ms) || 0) / 1000);
    },

    monthLabel(month) {
      if (!month) return '';
      const [y, m] = month.split('-').map(Number);
      return new Intl.DateTimeFormat(this.locale(), { month: 'long', year: 'numeric' }).format(new Date(y, m - 1, 1));
    },

    dayLabel(key) {
      const [y, m, d] = key.split('-').map(Number);
      return new Intl.DateTimeFormat(this.locale(), { day: 'numeric', month: 'short' }).format(new Date(y, m - 1, d));
    },

    dateTime(iso) {
      if (!iso) return '—';
      return new Intl.DateTimeFormat(this.locale(), { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
    },
  },
};
</script>
