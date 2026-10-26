<template>
  <div ref="root" class="ai-translate-menu" :class="{ 'opacity-50': disabled }" @keydown="onKeydown">
    <button
      ref="trigger"
      type="button"
      :class="triggerClass"
      :disabled="disabled"
      :title="disabled ? disabledReason : __('Translate')"
      :aria-label="__('Translate')"
      aria-haspopup="menu"
      :aria-expanded="open ? 'true' : 'false'"
      @click="toggle"
    >
      <Translation class="h-4 w-4 text-green-600" />
    </button>

    <div v-if="open" class="ai-translate-dropdown" role="menu">
      <p class="ai-translate-dropdown__heading">{{ __('Translate into') }}</p>
      <button
        v-for="lang in languages"
        :key="lang.code"
        type="button"
        role="menuitem"
        class="ai-translate-dropdown__item"
        @click="select(lang)"
      >
        {{ lang.label }}
      </button>
    </div>
  </div>
</template>

<script>
import Translation from './icons/TranslationIcon.vue';

export default {
  name: 'AiTranslateMenu',
  components: { Translation },
  props: {
    languages: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
    disabledReason: { type: String, default: '' },
    triggerClass: { type: [String, Array, Object], default: '' },
  },
  emits: ['select'],
  data() {
    return { open: false };
  },
  mounted() {
    document.addEventListener('click', this.onDocumentClick);
  },
  beforeUnmount() {
    document.removeEventListener('click', this.onDocumentClick);
  },
  methods: {
    toggle() {
      this.open = !this.open;
      if (this.open) this.$nextTick(() => this.items()[0]?.focus());
    },
    close(focusTrigger = false) {
      this.open = false;
      if (focusTrigger) this.$refs.trigger?.focus();
    },
    select(lang) {
      this.close();
      this.$emit('select', lang);
    },
    items() {
      return [...(this.$refs.root?.querySelectorAll('.ai-translate-dropdown__item') || [])];
    },
    onKeydown(event) {
      if (!this.open) return;
      const items = this.items();
      const index = items.indexOf(document.activeElement);
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        this.close(true);
      } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        items[(index + 1) % items.length]?.focus();
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        items[(index - 1 + items.length) % items.length]?.focus();
      }
    },
    onDocumentClick(event) {
      if (this.open && !this.$refs.root?.contains(event.target)) this.close();
    },
  },
};
</script>
