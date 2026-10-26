<template>
  <div class="eg-cp-launcher" aria-live="polite">
    <button
      v-if="!open"
      type="button"
      class="eg-cp-launcher__bubble"
      :class="{
        'eg-cp-launcher__bubble--busy': activity.busy,
        'eg-cp-launcher__bubble--ready': !activity.busy && activity.ready > 0,
      }"
      :title="bubbleTitle"
      :aria-label="bubbleTitle"
      @click="openDrawer"
    >
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M15 4V2M15 16v-2M8 9h2M20 9h2M17.8 11.8L19 13M17.8 6.2L19 5M3 21l9-9M12.2 6.2L11 5" />
      </svg>
      <span v-if="activity.busy" class="eg-cp-launcher__bubble-pulse" aria-hidden="true" />
      <span v-else-if="activity.ready > 0" class="eg-cp-launcher__bubble-badge" aria-hidden="true">{{ activity.ready }}</span>
    </button>

    <Teleport to="body">
      <Transition name="eg-cp-fade">
        <div
          v-if="open"
          class="eg-cp-launcher__backdrop"
          aria-hidden="true"
          @click="closeDrawer"
        />
      </Transition>
      <!--
        The panel mounts lazily on first open and then stays alive (v-show) so the
        EntryGeneratorPage component instance, its DOM, and any in-flight scroll
        position survive a close/reopen cycle. The actual generation state lives
        in the module-scope store, so even if this whole launcher were torn down
        the work would continue.
      -->
      <Transition name="eg-cp-sheet">
        <div
          v-show="open"
          v-if="hasOpenedOnce"
          class="eg-cp-launcher__dock"
          role="presentation"
        >
          <div
            class="eg-cp-launcher__panel"
            role="dialog"
            tabindex="-1"
            :aria-label="`${__('AI agent')} (${__('Beta')})`"
            @click.stop
          >
            <header class="eg-cp-launcher__head">
              <span class="eg-cp-launcher__head-brand" aria-hidden="true">
                <svg class="eg-cp-launcher__mark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M12 2.5c.4 0 .73.28.8.67.62 3.4 1.88 5.24 5.8 6.03a.82.82 0 0 1 0 1.6c-3.92.8-5.18 2.63-5.8 6.03a.82.82 0 0 1-1.6 0c-.62-3.4-1.88-5.24-5.8-6.03a.82.82 0 0 1 0-1.6c3.92-.8 5.18-2.63 5.8-6.03.07-.39.4-.67.8-.67Z" />
                  <path d="M18.5 15c.24 0 .44.17.48.4.25 1.36.75 2.07 2.27 2.38a.49.49 0 0 1 0 .96c-1.52.31-2.02 1.02-2.27 2.38a.49.49 0 0 1-.96 0c-.25-1.36-.75-2.07-2.27-2.38a.49.49 0 0 1 0-.96c1.52-.31 2.02-1.02 2.27-2.38.04-.23.24-.4.48-.4Z" />
                </svg>
              </span>
              <div class="eg-cp-launcher__head-title-row">
                <span class="eg-cp-launcher__head-title">{{ __('AI agent') }}</span>
                <span class="eg-cp-launcher__beta">{{ __('Beta') }}</span>
              </div>
              <button
                type="button"
                class="eg-cp-launcher__close"
                :title="__('Minimize')"
                :aria-label="__('Minimize')"
                @click="closeDrawer"
              >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                  <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
              </button>
            </header>
            <div class="eg-cp-launcher__body">
              <EntryGeneratorPage :drawer="true" :active="open" />
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script>
import EntryGeneratorPage from './EntryGeneratorPage.vue';
import { setI18n, setToaster, getActivity } from '../store/entryGeneratorStore.js';

export default {
  name: 'EntryGeneratorCpLauncher',
  components: { EntryGeneratorPage },

  data() {
    return {
      open: false,
      hasOpenedOnce: false,
      scrollLockPrev: null,
      escHandler: null,
    };
  },

  computed: {
    activity() {
      return getActivity();
    },

    bubbleTitle() {
      if (this.activity.busy) return this.__('AI agent — working in the background…');
      if (this.activity.ready > 0) {
        return this.__(':n entries ready to review', { n: this.activity.ready });
      }
      return this.__('Open AI agent');
    },
  },

  watch: {
    open(val) {
      if (val) {
        this.scrollLockPrev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        this.escHandler = (e) => {
          if (e.key === 'Escape') {
            this.closeDrawer();
          }
        };
        document.addEventListener('keydown', this.escHandler);
      } else {
        document.body.style.overflow = this.scrollLockPrev || '';
        if (this.escHandler) {
          document.removeEventListener('keydown', this.escHandler);
          this.escHandler = null;
        }
      }
    },
  },

  mounted() {
    // Wire the store to Vue's translator and the global toast singleton so the
    // store can show localized completion toasts even when no page component is
    // currently mounted (e.g. user navigated away to /cp/dashboard).
    setI18n((s, opts) => this.__(s, opts || {}));
    setToaster({
      success: (m) => Statamic.$toast?.success?.(m),
      error: (m) => Statamic.$toast?.error?.(m),
    });
  },

  beforeUnmount() {
    document.body.style.overflow = this.scrollLockPrev || '';
    if (this.escHandler) {
      document.removeEventListener('keydown', this.escHandler);
    }
  },

  methods: {
    openDrawer() {
      this.hasOpenedOnce = true;
      this.open = true;
    },
    closeDrawer() {
      this.open = false;
    },
  },
};
</script>
