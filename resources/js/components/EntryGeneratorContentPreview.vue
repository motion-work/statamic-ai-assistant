<template>
  <div class="eg-content-preview" :class="{ 'eg-content-preview--chat': variant === 'chat' }">
    <div v-if="heroTitle" class="eg-content-preview__hero">
      <p class="eg-content-preview__hero-label">{{ __('Title') }}</p>
      <h3 class="eg-content-preview__title">{{ heroTitle }}</h3>
    </div>

    <div
      v-for="row in bodyRows"
      :key="row.handle"
      class="eg-content-preview__block"
    >
      <span class="eg-content-preview__label">{{ row.label }}</span>

      <div
        v-if="row.kind === 'html'"
        class="eg-content-preview__html prose prose-sm dark:prose-invert max-w-none"
        v-html="row.value"
      />

      <p v-else-if="row.kind === 'text'" class="eg-content-preview__text">{{ row.value }}</p>

      <!-- Structured: replicator / grid / components — render as visual blocks -->
      <div v-else-if="row.kind === 'structured'" class="eg-content-preview__sets">
        <div
          v-for="(block, blockIdx) in row.blocks"
          :key="blockIdx"
          class="eg-content-preview__set"
        >
          <span class="eg-content-preview__set-type">{{ block.typeLabel }}</span>
          <div
            v-for="sub in block.fields"
            :key="sub.key"
            class="eg-content-preview__sub"
          >
            <span class="eg-content-preview__sub-label">{{ sub.label }}</span>
            <div
              v-if="sub.isHtml"
              class="eg-content-preview__sub-html prose prose-sm dark:prose-invert max-w-none"
              v-html="sub.value"
            />
            <span v-else class="eg-content-preview__sub-value">{{ sub.value }}</span>
          </div>
        </div>
      </div>
    </div>

    <p v-if="!heroTitle && bodyRows.length === 0" class="eg-content-preview__empty">
      {{ __('Content was generated — open the entry in the editor to review all fields.') }}
    </p>
  </div>
</template>

<script>
export default {
  name: 'EntryGeneratorContentPreview',

  props: {
    fieldPreview: { type: Object, default: null },
    displayData: { type: Object, default: null },
    variant: { type: String, default: 'page' },
  },

  computed: {
    titleHandle() {
      const fp = this.fieldPreview;
      if (!fp) return null;
      if ('title' in fp) return 'title';
      const found = Object.keys(fp).find((h) => (fp[h]?.label || '').toLowerCase() === 'title');
      return found || null;
    },

    heroTitle() {
      const dd = this.displayData;
      const th = this.titleHandle;
      if (!dd || !th) return '';
      const v = dd[th];
      if (v == null || v === '') return '';
      return (typeof v === 'string' ? v.trim() : String(v)) || '';
    },

    bodyRows() {
      const fp = this.fieldPreview;
      const dd = this.displayData;
      if (!fp || !dd) return [];

      const handles = Object.keys(fp);
      const priority = (a, b) => {
        const order = ['title', 'slug'];
        const ia = order.indexOf(a);
        const ib = order.indexOf(b);
        if (ia !== -1 || ib !== -1) return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
        return a.localeCompare(b);
      };
      handles.sort(priority);

      const rows = [];
      const th = this.titleHandle;

      for (const handle of handles) {
        const field = fp[handle];
        if (!field) continue;
        if (th && handle === th && this.heroTitle) continue;

        const raw = dd[handle];
        if (raw === undefined || raw === null || raw === '') continue;

        const label = field.label || handle;
        const type = field.type || 'text';

        if (type === 'asset_description' || type === 'asset_descriptions') {
          const paths = Array.isArray(raw) ? raw.join(', ') : String(raw);
          if (!paths.trim()) continue;
          rows.push({ handle, label, kind: 'text', value: paths });
          continue;
        }

        if (type === 'html') {
          rows.push({ handle, label, kind: 'html', value: String(raw) });
          continue;
        }

        if (type === 'boolean') {
          rows.push({ handle, label, kind: 'text', value: raw ? this.__('Yes') : this.__('No') });
          continue;
        }

        if (type === 'structured') {
          const blocks = this.parseStructuredBlocks(raw, field);
          if (blocks.length) {
            rows.push({ handle, label, kind: 'structured', blocks });
          }
          continue;
        }

        rows.push({ handle, label, kind: 'text', value: typeof raw === 'string' ? raw : String(raw) });
      }

      return rows;
    },
  },

  methods: {
    parseStructuredBlocks(raw, field) {
      const arr = Array.isArray(raw) ? raw : this.tryParseJson(raw);
      if (!Array.isArray(arr)) return [];

      const sets = field.sets || {};

      return arr.map((item) => {
        const setType = item.type || '—';
        const setSchema = sets[setType] || {};

        const typeLabel = this.humanize(setType);

        const fields = [];
        for (const [key, val] of Object.entries(item)) {
          if (key === 'type' || key === 'enabled' || val === undefined || val === null || val === '') continue;

          const subSchema = setSchema[key];
          const subLabel = subSchema?.label || this.humanize(key);
          const isHtml = subSchema?.type === 'html' || (typeof val === 'string' && /<[a-z][\s\S]*>/i.test(val) && val.length > 30);

          let displayVal;
          if (typeof val === 'boolean') {
            displayVal = val ? this.__('Yes') : this.__('No');
          } else if (Array.isArray(val) || (typeof val === 'object' && val !== null)) {
            displayVal = this.flattenNested(val);
          } else {
            displayVal = String(val);
          }

          if (!displayVal.trim()) continue;
          fields.push({ key, label: subLabel, value: displayVal, isHtml: isHtml && typeof val === 'string' });
        }

        return { typeLabel, fields };
      }).filter((b) => b.fields.length > 0);
    },

    flattenNested(val) {
      if (Array.isArray(val)) {
        return val.map((item, i) => {
          if (typeof item === 'object' && item !== null) {
            const pairs = Object.entries(item)
              .filter(([k]) => k !== 'type' && k !== 'enabled')
              .map(([k, v]) => `${this.humanize(k)}: ${typeof v === 'object' ? JSON.stringify(v) : v}`)
              .join(' · ');
            return pairs || `[${i + 1}]`;
          }
          return String(item);
        }).join('\n');
      }
      if (typeof val === 'object' && val !== null) {
        return Object.entries(val)
          .filter(([k]) => k !== 'type' && k !== 'enabled')
          .map(([k, v]) => `${this.humanize(k)}: ${typeof v === 'object' ? JSON.stringify(v) : v}`)
          .join(' · ');
      }
      return String(val);
    },

    humanize(handle) {
      return handle
        .replace(/[-_]/g, ' ')
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/\b\w/g, (c) => c.toUpperCase());
    },

    tryParseJson(val) {
      if (typeof val !== 'string') return val;
      try {
        return JSON.parse(val);
      } catch {
        return val;
      }
    },
  },
};
</script>
