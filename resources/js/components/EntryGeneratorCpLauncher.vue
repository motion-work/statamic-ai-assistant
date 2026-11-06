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

    <!-- After minimising: a small way back to the chat, next to the bubble. -->
    <button
      v-if="showBackPill"
      type="button"
      class="eg-cp-launcher__pill"
      @click="openDrawer"
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
        <path d="M15 18l-6-6 6-6" />
      </svg>
      {{ __('Back to the AI agent') }}
    </button>

    <Teleport to="body">
      <!--
        Full-screen workspace: covers the whole Control Panel so the agent is
        the place work happens. It mounts lazily on first open and then stays
        alive (v-show) so the EntryGeneratorPage instance, its DOM and scroll
        position survive a close/reopen cycle. Generation state lives in the
        module-scope store, so work continues even while it is closed.
        z-index stays on the CP "above" layer, below the portal layer, so native
        Stacks (asset browser), dropdowns and toasts still render on top.
      -->
      <Transition name="eg-cp-screen">
        <div
          v-show="open"
          v-if="hasOpenedOnce"
          class="eg-cp-screen"
          role="dialog"
          aria-modal="true"
          tabindex="-1"
          :aria-label="`${__('AI agent')} (${__('Beta')})`"
        >
          <EntryGeneratorPage :drawer="true" :active="open" @minimize="minimizeDrawer">
            <template #topbar-start>
              <span class="eg-cp-screen__brand">
                <svg class="eg-cp-screen__mark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                  <path d="M12 2.5c.4 0 .73.28.8.67.62 3.4 1.88 5.24 5.8 6.03a.82.82 0 0 1 0 1.6c-3.92.8-5.18 2.63-5.8 6.03a.82.82 0 0 1-1.6 0c-.62-3.4-1.88-5.24-5.8-6.03a.82.82 0 0 1 0-1.6c3.92-.8 5.18-2.63 5.8-6.03.07-.39.4-.67.8-.67Z" />
                  <path d="M18.5 15c.24 0 .44.17.48.4.25 1.36.75 2.07 2.27 2.38a.49.49 0 0 1 0 .96c-1.52.31-2.02 1.02-2.27 2.38a.49.49 0 0 1-.96 0c-.25-1.36-.75-2.07-2.27-2.38a.49.49 0 0 1 0-.96c1.52-.31 2.02-1.02 2.27-2.38.04-.23.24-.4.48-.4Z" />
                </svg>
                <span class="eg-cp-screen__title">{{ __('AI agent') }}</span>
                <span class="eg-cp-screen__beta">{{ __('Beta') }}</span>
              </span>
              <span class="eg-cp-screen__divider" aria-hidden="true" />
            </template>
            <template #topbar-end>
              <button
                type="button"
                class="eg-cp-screen__minimize"
                :title="__('Minimise: see the Control Panel, the chat stays as it is')"
                @click="minimizeDrawer"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true">
                  <path d="M5 12h14" />
                </svg>
                <span class="eg-cp-screen__minimize-label">{{ __('Minimise') }}</span>
              </button>
              <button
                type="button"
                class="eg-cp-screen__close"
                :title="__('Back to the Control Panel (Esc)')"
                @click="closeDrawer"
              >
                <span class="eg-cp-screen__close-label">{{ __('Close') }}</span>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true">
                  <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
              </button>
            </template>
          </EntryGeneratorPage>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script>
import EntryGeneratorPage from './EntryGeneratorPage.vue';
import { setI18n, setToaster, getActivity, resumeActiveRun, state as agentState } from '../store/entryGeneratorStore.js';

export default {
  name: 'EntryGeneratorCpLauncher',
  components: { EntryGeneratorPage },

  data() {
    return {
      open: false,
      minimized: false,
      hasOpenedOnce: false,
      scrollLockPrev: null,
      escHandler: null,
    };
  },

  computed: {
    activity() {
      return getActivity();
    },

    /** The pill shows after minimising, while there is a chat to go back to. */
    showBackPill() {
      return !this.open && this.minimized && agentState.transcript.length > 0;
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
    // A request still running when the previous page unloaded: reattach, so
    // the bubble shows it as busy and the agent shows its progress/result.
    resumeActiveRun();
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
      this.minimized = false;
      this.open = true;
    },
    /** Close the full-screen agent but keep a way back (the pill) to the chat. */
    minimizeDrawer() {
      this.open = false;
      this.minimized = true;
    },
    closeDrawer() {
      this.open = false;
    },
  },
};
</script>
