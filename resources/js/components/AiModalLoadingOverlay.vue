<template>
  <Transition name="ai-modal-loading">
    <div
      v-if="show"
      class="ai-modal-loading"
      :class="{ 'ai-modal-loading--compact': compact }"
      role="status"
      aria-live="polite"
      aria-busy="true"
    >
      <div
        class="ai-modal-loading__panel"
        :class="{ 'ai-modal-loading__panel--compact': compact }"
      >
        <span class="ai-modal-loading__spinner" aria-hidden="true" />
        <span class="ai-modal-loading__label">{{ label }}</span>
      </div>
    </div>
  </Transition>
</template>

<script>
export default {
  name: 'AiModalLoadingOverlay',
  props: {
    show: {
      type: Boolean,
      default: false,
    },
    label: {
      type: String,
      default: 'Generating…',
    },
    /** Smaller horizontal layout for inline field overlays (e.g. translating). */
    compact: {
      type: Boolean,
      default: false,
    },
  },
};
</script>
