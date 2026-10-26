<template>
  <div v-if="showLauncher" class="eg-cp-launcher" aria-live="polite">
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
            :aria-label="`${__('BOLD agent')} (${__('Beta')})`"
            @click.stop
          >
            <header class="eg-cp-launcher__head">
              <span class="eg-cp-launcher__head-brand" aria-hidden="true">
                <svg class="eg-cp-launcher__mark" xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 1024 1024">
                  <g>
                    <path d="M512,1023.5c-69.1,0-136-13.5-199.1-40.2-60.9-25.8-115.6-62.6-162.6-109.6-47-47-83.9-101.7-109.6-162.6C14,648,.5,581.1.5,512s13.5-136,40.2-199.1c25.8-60.9,62.6-115.6,109.6-162.6,47-47,101.7-83.9,162.6-109.6C376,14,442.9.5,512,.5s136,13.5,199.1,40.2c60.9,25.8,115.6,62.6,162.6,109.6,47,47,83.9,101.7,109.6,162.6,26.7,63.1,40.2,130,40.2,199.1s-13.5,136-40.2,199.1c-25.8,60.9-62.6,115.6-109.6,162.6-47,47-101.7,83.9-162.6,109.6-63.1,26.7-130,40.2-199.1,40.2Z"/>
                    <path d="M512,1c69,0,135.9,13.5,198.9,40.2,60.8,25.7,115.5,62.6,162.4,109.5,46.9,46.9,83.8,101.6,109.5,162.4,26.6,63,40.2,129.9,40.2,198.9s-13.5,135.9-40.2,198.9c-25.7,60.8-62.6,115.5-109.5,162.4-46.9,46.9-101.6,83.8-162.4,109.5-63,26.6-129.9,40.2-198.9,40.2s-135.9-13.5-198.9-40.2c-60.8-25.7-115.5-62.6-162.4-109.5-46.9-46.9-83.8-101.6-109.5-162.4C14.5,647.9,1,581,1,512s13.5-135.9,40.2-198.9c25.7-60.8,62.6-115.5,109.5-162.4,46.9-46.9,101.6-83.8,162.4-109.5C376.1,14.5,443,1,512,1M512,0C229.2,0,0,229.2,0,512s229.2,512,512,512,512-229.2,512-512S794.8,0,512,0h0Z" fill="#fff"/>
                  </g>
                  <path d="M682,495c56.6,26.7,92,80.5,92,146.3,0,97.5-84.2,171.1-195.1,171.1H250V211.6h323.4c106.7,0,184,64.9,184,153.7s-29.9,102.1-75.9,129.7h.5ZM610.2,621.5c0-31.7-23.5-54.3-59.3-54.3h-142.2v109h142.2c35.9,0,59.3-22.5,59.3-54.7ZM601,392.8c0-31.3-21.2-52.4-56.1-52.4h-135.7v104.4h135.7c34.5,0,56.1-21.2,56.1-52.4h0v.5Z" fill="#fff"/>
                </svg>
    
              </span>
              <div class="eg-cp-launcher__head-title-row">
                <span class="eg-cp-launcher__head-title">{{ __('BOLD agent') }}</span>
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
import { state as storeState, setI18n, setToaster, getActivity } from '../store/entryGeneratorStore.js';

export default {
  name: 'EntryGeneratorCpLauncher',
  components: { EntryGeneratorPage },

  data() {
    return {
      open: false,
      hasOpenedOnce: false,
      scrollLockPrev: null,
      escHandler: null,
      /** CP pathname; updated on navigation (Inertia does not always fire popstate). */
      currentPath: typeof window !== 'undefined' ? window.location.pathname : '',
      pathPollId: null,
      // Subscribe to the store so our computeds re-render when it changes.
      store: storeState,
    };
  },

  computed: {
    /** Collections area only (list + entries, etc.), not other CP sections. */
    showOnCollections() {
      return this.cpPathIsCollectionsArea(this.currentPath);
    },

    activity() {
      return getActivity();
    },

    /**
     * The launcher is rendered when the user is in the collections area, OR
     * whenever there is background activity / unsaved entries — so the user
     * can return to in-progress work from anywhere in the CP.
     */
    showLauncher() {
      return this.showOnCollections
        || this.activity.busy
        || this.activity.ready > 0
        || !!this.store.generationError;
    },

    bubbleTitle() {
      if (this.activity.busy) return this.__('BOLD agent — working in the background…');
      if (this.activity.ready > 0) {
        return this.__(':n entries ready to review', { n: this.activity.ready });
      }
      return this.__('Open BOLD agent');
    },
  },

  watch: {
    showLauncher(visible) {
      if (!visible && this.open) {
        this.closeDrawer();
      }
    },
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
    this.syncCpPath();
    this.pathPollId = setInterval(this.syncCpPath, 400);
    window.addEventListener('popstate', this.syncCpPath);

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
    if (this.pathPollId) {
      clearInterval(this.pathPollId);
      this.pathPollId = null;
    }
    window.removeEventListener('popstate', this.syncCpPath);
    document.body.style.overflow = this.scrollLockPrev || '';
    if (this.escHandler) {
      document.removeEventListener('keydown', this.escHandler);
    }
  },

  methods: {
    cpPathIsCollectionsArea(pathname) {
      if (!pathname || typeof pathname !== 'string') {
        return false;
      }
      return /\/cp\/collections(\/|$)/.test(pathname);
    },

    syncCpPath() {
      if (typeof window === 'undefined') return;
      const next = window.location.pathname || '';
      if (next !== this.currentPath) {
        this.currentPath = next;
      }
    },

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
