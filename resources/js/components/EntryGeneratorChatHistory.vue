<template>
  <div class="eg-history" role="dialog" :aria-label="__('Chat history')">
    <div class="eg-history__head">
      <span class="eg-history__title">{{ __('Chats') }}</span>
      <button
        type="button"
        class="eg-history__close"
        :title="__('Close')"
        :aria-label="__('Close')"
        @click="$emit('close')"
      >
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true">
          <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
    </div>

    <div v-if="chats.length" class="eg-history__search">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" width="14" height="14" aria-hidden="true">
        <circle cx="11" cy="11" r="7" />
        <path d="M21 21l-4.3-4.3" />
      </svg>
      <input
        ref="searchInput"
        v-model="query"
        type="text"
        class="eg-history__search-input"
        :placeholder="__('Search chats…')"
        :aria-label="__('Search chats')"
      />
    </div>

    <div class="eg-history__list">
      <p v-if="!chats.length" class="eg-history__empty">
        {{ __('No saved chats yet. Conversations are kept in this browser and appear here automatically.') }}
      </p>
      <p v-else-if="!groups.length" class="eg-history__empty">
        {{ __('No chats match your search.') }}
      </p>

      <template v-for="group in groups" :key="group.label">
        <div class="eg-history__group">{{ group.label }}</div>
        <div
          v-for="chat in group.chats"
          :key="chat.id"
          class="eg-history__item"
          :class="{ 'eg-history__item--active': chat.id === activeChatId }"
        >
          <button type="button" class="eg-history__item-main" @click="$emit('select', chat.id)">
            <span class="eg-history__item-title">{{ chat.title || __('Untitled chat') }}</span>
            <span class="eg-history__item-meta">
              {{ formatTime(chat.updatedAt) }} · {{ turnCountLabel(chat) }}
            </span>
          </button>
          <button
            type="button"
            class="eg-history__item-delete"
            :class="{ 'eg-history__item-delete--armed': confirmDeleteId === chat.id }"
            :title="confirmDeleteId === chat.id ? __('Click again to delete') : __('Delete chat')"
            :aria-label="confirmDeleteId === chat.id ? __('Click again to delete') : __('Delete chat')"
            @click.stop="onDelete(chat.id)"
            @mouseleave="disarmDelete(chat.id)"
          >
            <svg v-if="confirmDeleteId !== chat.id" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
              <path d="M3 6h18M8 6V4a1 1 0 011-1h6a1 1 0 011 1v2M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6" />
            </svg>
            <span v-else class="eg-history__item-delete-confirm">{{ __('Delete?') }}</span>
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<script>
/**
 * Slide-over list of locally saved agent conversations (per CP user).
 * Pure presentation: the parent owns the data (props in, events out).
 */
export default {
  name: 'EntryGeneratorChatHistory',

  props: {
    /** Saved chats, newest first (see chatHistoryStorage record shape). */
    chats: { type: Array, default: () => [] },
    /** Local id of the conversation currently open in the panel. */
    activeChatId: { type: String, default: null },
  },

  emits: ['select', 'delete', 'close'],

  data() {
    return {
      query: '',
      confirmDeleteId: null,
    };
  },

  computed: {
    filteredChats() {
      const q = this.query.trim().toLowerCase();
      if (!q) return this.chats;
      return this.chats.filter((c) => (c.title || '').toLowerCase().includes(q));
    },

    /** Chats bucketed by recency, in display order. */
    groups() {
      const startOfToday = new Date().setHours(0, 0, 0, 0);
      const day = 24 * 60 * 60 * 1000;
      const buckets = [
        { label: this.__('Today'), min: startOfToday },
        { label: this.__('Yesterday'), min: startOfToday - day },
        { label: this.__('Previous 7 days'), min: startOfToday - 7 * day },
        { label: this.__('Older'), min: -Infinity },
      ];

      const groups = buckets.map((b) => ({ label: b.label, chats: [] }));
      for (const chat of this.filteredChats) {
        const idx = buckets.findIndex((b) => (chat.updatedAt || 0) >= b.min);
        groups[idx === -1 ? groups.length - 1 : idx].chats.push(chat);
      }
      return groups.filter((g) => g.chats.length);
    },
  },

  mounted() {
    this.$refs.searchInput?.focus?.();
  },

  methods: {
    formatTime(ts) {
      if (!ts) return '';
      const d = new Date(ts);
      const sameDay = d.setHours(0, 0, 0, 0) === new Date().setHours(0, 0, 0, 0);
      return sameDay
        ? new Date(ts).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
        : new Date(ts).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
    },

    turnCountLabel(chat) {
      const n = (chat.transcript || []).length;
      return n === 1 ? this.__('1 message') : this.__(':n messages', { n });
    },

    /** Two-step delete: first click arms, second click confirms. */
    onDelete(id) {
      if (this.confirmDeleteId !== id) {
        this.confirmDeleteId = id;
        return;
      }
      this.confirmDeleteId = null;
      this.$emit('delete', id);
    },

    disarmDelete(id) {
      if (this.confirmDeleteId === id) this.confirmDeleteId = null;
    },
  },
};
</script>
