/**
 * Mirrors Statamic's Fieldtype.vue mixin without importing from vendor/statamic
 * (those imports pull the whole CP UI into the addon bundle and break the Vite build).
 */
import { isRef, markRaw } from 'vue';

const PUBLISH_CONTAINER_KEY = 'PublishContainerContext';

function debounce(func, wait) {
  let timeout;
  const debounced = function (...args) {
    clearTimeout(timeout);
    timeout = setTimeout(() => {
      func.apply(this, args);
    }, wait);
  };
  debounced.cancel = () => {
    clearTimeout(timeout);
  };
  return debounced;
}

const props = {
  value: {
    required: true,
  },
  config: {
    type: Object,
    default: () => ({}),
  },
  handle: {
    type: String,
    required: true,
  },
  meta: {
    type: Object,
    default: () => ({}),
  },
  readOnly: {
    type: Boolean,
    default: false,
  },
  showFieldPreviews: {
    type: Boolean,
    default: false,
  },
  namePrefix: String,
  fieldPathPrefix: String,
  metaPathPrefix: String,
  id: String,
};

const emits = [
  'update:value',
  'update:meta',
  'focus',
  'blur',
  'replicator-preview-updated',
];

export default {
  emits,

  inject: {
    injectedPublishContainer: {
      from: PUBLISH_CONTAINER_KEY,
      default: null,
    },
  },

  props,

  methods: {
    update(value) {
      this.$emit('update:value', value);
    },

    updateMeta(value) {
      this.$emit('update:meta', value);
    },
  },

  created() {
    this.updateDebounced = markRaw(
      debounce((value) => {
        this.update(value);
      }, 150)
    );
  },

  computed: {
    publishContainer() {
      if (!this.injectedPublishContainer) {
        return {};
      }
      return Object.fromEntries(
        Object.entries(this.injectedPublishContainer).map(([key, value]) => [
          key,
          isRef(value) ? value.value : value,
        ])
      );
    },

    name() {
      if (this.namePrefix) {
        return `${this.namePrefix}[${this.handle}]`;
      }
      return this.handle;
    },

    isReadOnly() {
      return (
        this.readOnly ||
        this.config.visibility === 'read_only' ||
        this.config.visibility === 'computed' ||
        false
      );
    },

    replicatorPreview() {
      if (!this.showFieldPreviews) return;
      return this.value;
    },

    fieldPathKeys() {
      const prefix = this.fieldPathPrefix || this.handle;
      return prefix.split('.');
    },

    fieldId() {
      return this.id;
    },

    fieldActionPayload() {
      return {
        vm: this,
        fieldPathPrefix: this.fieldPathPrefix,
        handle: this.handle,
        value: this.value,
        config: this.config,
        meta: this.meta,
        update: this.update,
        updateMeta: this.updateMeta,
        isReadOnly: this.isReadOnly,
      };
    },

    /** Field actions are registered on the Statamic side; keep an empty default. */
    fieldActions() {
      return [];
    },
  },

  watch: {
    replicatorPreview: {
      immediate: true,
      handler(text) {
        if (!this.showFieldPreviews) return;
        this.$emit('replicator-preview-updated', text);
      },
    },
  },
};
