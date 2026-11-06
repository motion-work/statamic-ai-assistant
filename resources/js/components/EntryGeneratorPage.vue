<template>
  <!-- ═══ Agentic chat (drawer) — Claude-style flat layout ═══ -->
  <div v-if="drawer" ref="chatRoot" class="eg-chat" :class="{ 'eg-chat--empty': renderedStep === 2 }">
    <!-- Top bar: brand (slot) + history / new chat on the left, copy +
         advanced-tools + close (slot) on the right -->
    <div class="eg-chat__topbar">
      <div class="eg-chat__topbar-left">
        <slot name="topbar-start" />
        <button
          type="button"
          class="eg-topbar-btn"
          :class="{ 'eg-topbar-btn--active': historyOpen }"
          :title="__('Chat history')"
          :aria-label="__('Chat history')"
          :aria-expanded="historyOpen ? 'true' : 'false'"
          @click="toggleHistory"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true">
            <path d="M3 3v5h5" />
            <path d="M3.05 13a9 9 0 102.13-7.36L3 8" />
            <path d="M12 7v5l4 2" />
          </svg>
        </button>
        <button
          type="button"
          class="eg-topbar-btn"
          :disabled="store.generating || store.planning"
          :title="__('New chat')"
          :aria-label="__('New chat')"
          @click="startNewChat"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true">
            <path d="M12 5v14M5 12h14" />
          </svg>
        </button>
      </div>
      <button
        type="button"
        class="eg-topbar-btn"
        :title="__('Copy conversation')"
        :aria-label="__('Copy conversation')"
        @click="copyConversation"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true">
          <rect x="9" y="9" width="12" height="12" rx="2" />
          <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" />
        </svg>
      </button>
      <label
        v-if="advancedTools.granted"
        class="eg-topbar-toggle"
        :class="{
          'eg-topbar-toggle--on': advancedTools.enabled,
          'eg-topbar-toggle--disabled': advancedTools.saving,
        }"
      >
        <input
          type="checkbox"
          class="eg-topbar-toggle__input sr-only"
          :checked="advancedTools.enabled"
          :disabled="advancedTools.saving"
          @change="onAdvancedToolsToggle($event)"
        />
        <span class="eg-topbar-toggle__switch" aria-hidden="true">
          <span class="eg-topbar-toggle__thumb" />
        </span>
        <span class="eg-topbar-toggle__label">{{ __('Advanced structure tools') }}</span>
        <span v-if="advancedTools.enabled" class="eg-topbar-toggle__badge">{{ __('active') }}</span>
      </label>
      <slot name="topbar-end" />
    </div>

    <!-- Scrim behind the history sidebar / template sheet: dims the workspace
         and closes whichever panel is open when clicked. -->
    <Transition name="eg-scrim">
      <div
        v-if="historyOpen || (templatesOpen && activeTemplate)"
        class="eg-chat__scrim"
        aria-hidden="true"
        @click="closePanels"
      />
    </Transition>

    <!-- Chat history: slide-over list of locally saved conversations -->
    <Transition name="eg-history">
      <EntryGeneratorChatHistory
        v-if="historyOpen"
        :chats="historyChats"
        :active-chat-id="store.currentChatId"
        @select="onHistorySelect"
        @delete="onHistoryDelete"
        @close="historyOpen = false"
      />
    </Transition>

    <!-- Prompt templates: when a template with clarifying questions is picked
         from the inline cards, this form slide-over collects the answers.
         Self-contained module (resources/js/promptTemplates/). -->
    <Transition name="eg-history">
      <PromptTemplateForm
        v-if="templatesOpen && activeTemplate"
        :template="activeTemplate"
        @apply="onTemplateApply"
        @back="activeTemplate = null"
        @close="templatesOpen = false; activeTemplate = null"
      />
    </Transition>

    <!-- Enable confirmation: informed consent before arming structural tools -->
    <div
      v-if="advancedToolsModalOpen"
      class="translation-page__modal-backdrop"
      role="dialog"
      aria-modal="true"
      @click.self="cancelAdvancedTools"
    >
      <div class="translation-page__modal">
        <h3 class="translation-page__modal-title">{{ __('Enable advanced structure tools?') }}</h3>
        <p class="translation-page__modal-text">
          {{ __('This gives the AI agent direct access to create and modify collections, blueprints, fieldsets and taxonomies.') }}
        </p>
        <ul class="translation-page__modal-list">
          <li>{{ __('Structural changes apply immediately — there is no draft or review step.') }}</li>
          <li>{{ __('The AI can make mistakes. Always verify what was changed before committing the code changes.') }}</li>
          <li>{{ __('Not recommended on production sites — test structural changes locally first.') }}</li>
        </ul>
        <div class="translation-page__modal-actions">
          <Button
            variant="default"
            :disabled="advancedTools.saving"
            :text="__('Cancel')"
            @click="cancelAdvancedTools"
          />
          <Button
            variant="primary"
            :disabled="advancedTools.saving"
            :text="__('Enable advanced tools')"
            @click="confirmAdvancedTools"
          />
        </div>
      </div>
    </div>

    <!-- Preview modal for "@asset:" / "@folder:" chips (click to open). Rendered
         inline in the drawer branch — the same proven pattern as the advanced-
         tools modal above (a body Teleport here would live in the v-else root
         and never render while the drawer is showing). -->
    <div
      v-if="assetPreview.open"
      class="eg-asset-modal__backdrop"
      role="dialog"
      aria-modal="true"
      @click.self="closeAssetPreview"
    >
      <div class="eg-asset-modal">
        <div class="eg-asset-modal__head">
          <div class="eg-asset-modal__heading">
            <span class="eg-asset-modal__title">{{ assetPreview.data?.name || (assetPreview.kind === 'folder' ? __('Folder') : __('Asset')) }}</span>
            <span v-if="assetPreview.data?.meta" class="eg-asset-modal__subtitle">{{ assetPreview.data.meta }}</span>
          </div>
          <button type="button" class="eg-asset-modal__close" :aria-label="__('Close')" @click="closeAssetPreview">&times;</button>
        </div>

        <div v-if="assetPreview.loading" class="eg-asset-modal__loading">
          <span class="eg-chat__mention-spinner" aria-hidden="true"></span>
        </div>

        <template v-else-if="assetPreview.data && assetPreview.data.ok">
          <!-- Folder: thumbnail grid + item count -->
          <template v-if="assetPreview.data.kind === 'folder'">
            <div v-if="assetPreview.data.thumbnails.length" class="eg-asset-modal__grid">
              <img v-for="(t, i) in assetPreview.data.thumbnails" :key="i" :src="t" alt="" loading="lazy" />
            </div>
            <div v-else class="eg-asset-modal__media eg-asset-modal__media--file">
              <span class="eg-asset-modal__ext">{{ __('No images') }}</span>
            </div>
            <div class="eg-asset-modal__body">
              <p class="eg-asset-modal__meta">{{ assetPreview.data.count }} {{ __('items') }}</p>
            </div>
          </template>

          <!-- Single asset, dispatched by preview capability: images get a real
               thumbnail; documents (PDF, TXT, …) get a type badge + size, with
               Statamic's own asset editor as the primary action. -->
          <template v-else>
            <div v-if="assetPreview.data.thumbnail" class="eg-asset-modal__media">
              <img :src="assetPreview.data.thumbnail" :alt="assetPreview.data.alt || assetPreview.data.name" />
            </div>
            <div v-else class="eg-asset-modal__media eg-asset-modal__media--file">
              <span class="eg-asset-modal__ext">{{ assetPreview.data.extension || __('File') }}</span>
            </div>
            <div class="eg-asset-modal__body">
              <p v-if="assetPreview.data.width" class="eg-asset-modal__meta">
                {{ assetPreview.data.width }} × {{ assetPreview.data.height }} px · {{ assetPreview.data.extension }}
              </p>
              <p v-else-if="assetPreview.data.size" class="eg-asset-modal__meta">
                {{ assetPreview.data.extension }} · {{ formatFileSize(assetPreview.data.size) }}
              </p>
              <p v-if="assetPreview.data.alt" class="eg-asset-modal__alt">“{{ assetPreview.data.alt }}”</p>
            </div>
          </template>

          <div class="eg-asset-modal__foot">
            <template v-if="assetPreviewIsDocument">
              <Button variant="default" size="sm" :text="__('Reference in chat')" @click="insertPreviewedAssetRef" />
              <Button variant="primary" size="sm" :text="__('Open in Statamic')" @click="openPreviewedAssetInCp" />
            </template>
            <template v-else>
              <a :href="assetBrowseUrl" class="eg-asset-modal__link" target="_blank" rel="noopener">
                {{ __('Open in assets') }} ↗
              </a>
              <Button
                variant="primary"
                size="sm"
                :text="__('Reference in chat')"
                @click="insertPreviewedAssetRef"
              />
            </template>
          </div>
        </template>

        <div v-else class="eg-asset-modal__body">
          <p class="eg-asset-modal__meta">{{ __('Preview unavailable.') }}</p>
        </div>
      </div>
    </div>

    <!-- Native Statamic asset browser (Stack) for folder navigation + picking
         refs into the chat. Opened from folder chips and the composer button. -->
    <AgentAssetSelectorStack
      v-if="assetBrowser.container"
      v-model:open="assetBrowser.open"
      :container="assetBrowser.container"
      :columns="assetBrowser.columns"
      :folder="assetBrowser.folder"
      @insert="insertRefTokens"
    />

    <!-- Main column. Empty chat: greeting, composer and template cards are
         centred as one hero group. Conversation: the stream scrolls and the
         composer docks at the bottom. Same DOM in both states, so the composer
         (TipTap) never remounts when the first message is sent. -->
    <div class="eg-chat__main">
    <div
      ref="chatStream"
      class="eg-chat__stream"
      @scroll.passive="onChatStreamScroll"
      @click="onChatStreamClick"
    >

      <!-- Empty chat: greeting above the composer. -->
      <div v-if="renderedStep === 2" class="eg-chat__hero">
        <h1 class="eg-chat__hero-title">{{ heroGreeting }}</h1>
        <p class="eg-chat__hero-lead">{{ __('Describe what you need, or start from a template below.') }}</p>
      </div>

      <!-- Conversation history: one block per turn. Assistant turns render their
           entry cards inline beneath the reply. -->
      <template v-for="turn in chatTurns" :key="turn.key">
        <div v-if="turn.role === 'user'" class="eg-chat__msg eg-chat__msg--user">
          <div class="eg-chat__user-wrap">
            <span class="sr-only">{{ __('You') }}</span>
            <div class="eg-chat__bubble eg-chat__bubble--user">
              <p v-html="chatHtml(turn.text, turn.mention_titles, turn.attachments)"></p>
              <!-- Legacy single-attachment pill (old saved chats — new turns
                   render their attachments as inline "@file:" chips). -->
              <div v-if="turn.attachment" class="eg-chat__bubble-attach">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
                  <path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48" />
                </svg>
                <span class="eg-chat__bubble-attach-name">{{ turn.attachment.name }}</span>
                <span v-if="turn.attachment.size" class="eg-chat__bubble-attach-meta">{{ formatFileSize(turn.attachment.size) }}</span>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="eg-chat__msg eg-chat__msg--agent">
          <span class="sr-only">{{ __('AI agent') }}</span>
          <!-- How long the agent worked on this reply, and its steps (like "Thought for 13s"). -->
          <div v-if="turn.work" class="eg-work eg-work--done">
            <button
              type="button"
              class="eg-work__row"
              :aria-expanded="openWork[turn.key] ? 'true' : 'false'"
              @click="toggleWork(turn.key)"
            >
              <span class="eg-work__label">{{ __('Worked for :time', { time: formatDuration(turn.work.ms) }) }}</span>
              <svg class="eg-work__caret" viewBox="0 0 20 20" fill="currentColor" width="12" height="12" aria-hidden="true"><path d="M7.3 4.3a1 1 0 011.4 0l5 5a1 1 0 010 1.4l-5 5a1 1 0 01-1.4-1.4L11.6 10 7.3 5.7a1 1 0 010-1.4z" /></svg>
            </button>
            <ol v-if="openWork[turn.key]" class="eg-work__steps">
              <li v-for="(step, i) in turn.work.steps" :key="i" class="eg-work__step">
                <span class="eg-work__check" aria-hidden="true">✓</span>
                <span>{{ step }}</span>
              </li>
              <li v-if="!turn.work.steps.length" class="eg-work__step eg-work__step--muted">{{ __('No steps recorded.') }}</li>
            </ol>
          </div>

          <div v-if="turn.kind === 'error'" class="eg-chat__notice eg-chat__notice--soft" role="status">
            <span class="eg-chat__notice-icon" aria-hidden="true">!</span>
            <div class="eg-chat__notice-body">
              <p>{{ errorHint(turn.text).message }}</p>
              <div v-if="turn.key === latestTurnKey && !turnInFlight && errorHint(turn.text).action" class="eg-chat__notice-actions">
                <button type="button" class="eg-chat__notice-btn" @click="onErrorAction(errorHint(turn.text).action)">
                  {{ errorHint(turn.text).action === 'new-chat' ? __('New chat') : __('Try again') }}
                </button>
              </div>
            </div>
          </div>
          <div v-else-if="turn.kind === 'plan'" class="eg-chat__plan">
            <div class="eg-md" v-html="agentHtml(turn.text)"></div>
            <div
              v-if="turn.key === latestTurnKey && !store.generating && !store.planning"
              class="eg-chat__plan-actions"
            >
              <Button variant="primary" :text="planApproveLabel(turn)" @click="approvePlan(turn)" />
            </div>
            <p v-if="turn.key === latestTurnKey" class="eg-chat__plan-hint">
              {{ planAsksQuestion(turn) ? __('Or reply below to change something or answer differently.') : __('Or reply below to change something.') }}
            </p>
          </div>
          <div v-else class="eg-md eg-chat__reply" v-html="agentHtml(turn.text)"></div>

          <div v-if="turn.sources.length" class="eg-chat__sources">
            <span class="eg-chat__sources-label">{{ __('Sources') }}</span>
            <ul class="eg-chat__sources-list">
              <li v-for="src in turn.sources" :key="src.url">
                <a class="eg-chat__source" :href="src.url" target="_blank" rel="noopener noreferrer" :title="src.url">
                  <span class="eg-chat__source-title">{{ src.title }}</span>
                  <span class="eg-chat__source-host">{{ src.host }}</span>
                </a>
              </li>
            </ul>
          </div>

          <div v-if="turn.cards.length" class="eg-chat__cards">
            <EntryGeneratorEntryCard
              v-for="(entry, idx) in turn.cards"
              :key="entry.id"
              :entry="entry"
              :index="idx"
              :auto-expand="turn.cards.length === 1"
              @save="(mode) => handleCardSave(entry.id, mode)"
              @retry="handleCardRetry(entry.id)"
              @discard="handleCardDiscard(entry.id)"
              @view="viewEntry"
            />
          </div>

          <div v-if="turn.kind !== 'error' && turn.text && !(turnInFlight && turn.key === latestTurnKey)" class="eg-chat__reply-actions">
            <button
              type="button"
              class="eg-icon-btn"
              :title="copiedKey === turn.key ? __('Copied') : __('Copy')"
              :aria-label="__('Copy')"
              @click="copyReply(turn)"
            >
              <svg v-if="copiedKey !== turn.key" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" /></svg>
              <svg v-else viewBox="0 0 20 20" fill="currentColor" width="14" height="14" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 011.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
              <span v-if="copiedKey === turn.key" class="eg-icon-btn__label">{{ __('Copied') }}</span>
            </button>
            <button
              v-if="turn.key === latestTurnKey && !turnInFlight && lastUserText"
              type="button"
              class="eg-icon-btn"
              :title="__('Try again')"
              :aria-label="__('Try again')"
              @click="retryLastMessage"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true"><path d="M3 12a9 9 0 0115.5-6.3L21 8" /><path d="M21 3v5h-5" /><path d="M21 12a9 9 0 01-15.5 6.3L3 16" /><path d="M3 21v-5h5" /></svg>
            </button>
          </div>
        </div>
      </template>

      <div v-if="renderedStep === 3 && liveCards.length" class="eg-chat__msg eg-chat__msg--agent">
        <span class="sr-only">{{ __('AI agent') }}</span>
        <div class="eg-chat__cards">
          <EntryGeneratorEntryCard
            v-for="(entry, idx) in liveCards"
            :key="entry.id"
            :entry="entry"
            :index="idx"
            :auto-expand="liveCards.length === 1"
            @save="(mode) => handleCardSave(entry.id, mode)"
            @retry="handleCardRetry(entry.id)"
            @discard="handleCardDiscard(entry.id)"
            @view="viewEntry"
          />
        </div>
      </div>

      <!-- Live turn: one line with what the agent is doing now, a timer, and its steps. -->
      <div v-if="renderedStep === 3 && turnInFlight" class="eg-chat__msg eg-chat__msg--agent eg-chat__msg--working">
        <span class="sr-only">{{ __('AI agent') }}</span>
        <div class="eg-work eg-work--live" aria-live="polite">
          <button
            type="button"
            class="eg-work__row"
            :aria-expanded="liveWorkOpen ? 'true' : 'false'"
            @click="liveWorkOpen = !liveWorkOpen"
          >
            <span class="eg-work__dots" aria-hidden="true"><i></i><i></i><i></i></span>
            <span class="eg-work__label">{{ currentStepText }}</span>
            <span v-if="elapsedLabel" class="eg-work__time" aria-hidden="true">{{ elapsedLabel }}</span>
            <svg class="eg-work__caret" viewBox="0 0 20 20" fill="currentColor" width="12" height="12" aria-hidden="true"><path d="M7.3 4.3a1 1 0 011.4 0l5 5a1 1 0 010 1.4l-5 5a1 1 0 01-1.4-1.4L11.6 10 7.3 5.7a1 1 0 010-1.4z" /></svg>
          </button>
          <ol v-if="liveWorkOpen" class="eg-work__steps">
            <li
              v-for="(step, i) in liveSteps"
              :key="i"
              class="eg-work__step"
              :class="{ 'eg-work__step--current': i === liveSteps.length - 1 && store.planning }"
            >
              <span v-if="i === liveSteps.length - 1 && store.planning" class="eg-work__spinner" aria-hidden="true"></span>
              <span v-else class="eg-work__check" aria-hidden="true">✓</span>
              <span>{{ step }}</span>
            </li>
            <li v-if="!liveSteps.length" class="eg-work__step eg-work__step--current">
              <span class="eg-work__spinner" aria-hidden="true"></span>
              <span>{{ currentStepText }}</span>
            </li>
          </ol>

          <!-- Non-entry operations already completed this turn (structural changes etc.). -->
          <ul v-if="store.operations && store.operations.length" class="eg-chat__ops" :aria-label="__('Changes applied')">
            <li v-for="op in store.operations" :key="op.id" class="eg-chat__ops-line">
              <span class="eg-chat__ops-check" aria-hidden="true">✓</span>
              <span>{{ op.label }}</span>
            </li>
          </ul>

          <p v-if="queueLooksStuck" class="eg-chat__stream-panel__queue" role="status">
            {{ __('The request is still waiting to start. Usually no queue worker is running on the server: please tell your administrator (php artisan queue:work). It starts as soon as a worker runs, and you can close this window in the meantime.') }}
          </p>
          <p v-if="store.connectionLost" class="eg-work__hint" role="status">{{ __('Connection lost – I keep trying…') }}</p>
        </div>
      </div>

      <!-- Current-turn error not yet folded into the transcript. -->
      <div v-if="renderedStep === 3 && showLiveGenerationError" class="eg-chat__msg eg-chat__msg--agent">
        <span class="sr-only">{{ __('AI agent') }}</span>
        <div class="eg-chat__notice eg-chat__notice--soft" role="status">
          <span class="eg-chat__notice-icon" aria-hidden="true">!</span>
          <div class="eg-chat__notice-body">
            <p>{{ errorHint(store.generationError).message }}</p>
            <div v-if="errorHint(store.generationError).action && !turnInFlight" class="eg-chat__notice-actions">
              <button type="button" class="eg-chat__notice-btn" @click="onErrorAction(errorHint(store.generationError).action)">
                {{ errorHint(store.generationError).action === 'new-chat' ? __('New chat') : __('Try again') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Stop confirmation (inline, above the composer) -->
      <div v-if="renderedStep === 3 && stopConfirmOpen" class="eg-chat__msg eg-chat__msg--agent">
        <span class="sr-only">{{ __('AI agent') }}</span>
        <div class="eg-chat__notice eg-chat__notice--warn eg-stop-confirm">
          <p>
            <strong>{{ __('Stop generation?') }}</strong>
            <template v-if="readyDraftedCount > 0">
              {{ __(':n entries are already drafted.', { n: readyDraftedCount }) }}
            </template>
          </p>
          <div class="eg-stop-confirm__actions">
            <Button variant="default" :text="__('Continue generating')" @click="cancelStopConfirm" />
            <Button variant="default" :text="__('Discard everything')" @click="confirmStop(false)" />
            <Button
              v-if="readyDraftedCount > 0"
              variant="primary"
              :text="__('Keep drafted (:n)', { n: readyDraftedCount })"
              @click="confirmStop(true)"
            />
          </div>
        </div>
      </div>

    </div>

    <!-- ── Bottom composer bar ── -->
    <div class="eg-chat__bar">
      <!-- Prompt input (step 2) -->
      <div v-if="renderedStep === 2 || renderedStep === 3" class="eg-chat__composer-wrap">
        <button
          v-if="showJumpToLatest"
          type="button"
          class="eg-chat__jump"
          :title="__('Jump to latest')"
          :aria-label="__('Jump to latest')"
          @click="jumpToLatest"
        >
          <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v9.6l3.3-3.3a1 1 0 111.4 1.4l-5 5a1 1 0 01-1.4 0l-5-5a1 1 0 111.4-1.4L9 13.6V4a1 1 0 011-1z" clip-rule="evenodd" /></svg>
        </button>
        <!-- @-mention entry picker -->
        <div v-if="mention.open" class="eg-chat__mention" role="listbox">
          <div class="eg-chat__mention-head">
            {{ __('Reference an entry') }}
          </div>
          <div v-if="mention.loading" class="eg-chat__mention-state">
            <span class="eg-chat__mention-spinner" aria-hidden="true" />
            {{ __('Searching…') }}
          </div>
          <template v-else>
            <ul v-if="mention.results.length" ref="mentionList" class="eg-chat__mention-list">
              <li
                v-for="(item, i) in mention.results"
                :key="item.ref || item.id"
                role="option"
                :aria-selected="i === mention.activeIndex"
                class="eg-chat__mention-item"
                :class="{
                  'is-active': i === mention.activeIndex,
                  'eg-chat__mention-item--asset': item.kind === 'asset',
                  'eg-chat__mention-item--folder': item.kind === 'folder',
                }"
                @mousedown.prevent="selectMention(item)"
                @mousemove="mention.activeIndex = i"
              >
                <span class="eg-chat__mention-title">{{ item.title }}</span>
                <span class="eg-chat__mention-meta">{{ item.collection_title }}</span>
              </li>
            </ul>
            <div v-else class="eg-chat__mention-state">
              {{ __('No entries found') }}
            </div>
          </template>
        </div>

        <div
          class="eg-chat__composer"
          :class="{ 'eg-chat__composer--drag': dragOver }"
          @dragover.prevent="dragOver = true"
          @dragleave="dragOver = false"
          @drop.prevent="handleDrop"
        >
          <AgentComposerInput
            ref="composer"
            v-model="store.pendingPrompt"
            class="eg-chat__composer-input"
            :placeholder="composerPlaceholder"
            :disabled="store.generating"
            :known-titles="store.mentionedTitles"
            :file-meta="pendingFileMeta"
            :keydown-interceptor="composerKeydownInterceptor"
            :autofocus="active"
            @send="handleComposerSend"
            @mention-query="onMentionQuery"
            @chip-click="onComposerChipClick"
            @focus="composerFocused = true"
            @blur="onComposerBlur(); composerFocused = false"
          />
          <button
            type="button"
            class="eg-chat__composer-attach"
            :disabled="store.generating"
            :title="__('Attach PDF or TXT')"
            :aria-label="__('Attach PDF or TXT')"
            @click="$refs.fileInput.click()"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" width="17" height="17" aria-hidden="true">
              <path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48" />
            </svg>
          </button>
          <input ref="fileInput" type="file" accept=".pdf,.txt" multiple class="sr-only" @change="handleFileSelect" />
          <button
            type="button"
            class="eg-chat__composer-assets"
            :disabled="store.generating"
            :title="__('Reference assets')"
            :aria-label="__('Reference assets')"
            @click="openAssetBrowser()"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" width="17" height="17" aria-hidden="true">
              <rect x="3" y="3" width="18" height="18" rx="2" />
              <circle cx="8.5" cy="8.5" r="1.5" />
              <path d="M21 15l-5-5L5 21" />
            </svg>
          </button>
          <button
            v-if="webSearchAvailable"
            type="button"
            class="eg-chat__composer-web"
            :class="{ 'eg-chat__composer-web--on': store.webSearch }"
            :disabled="store.generating"
            :aria-pressed="store.webSearch ? 'true' : 'false'"
            :title="store.webSearch ? __('Web search is on for this chat. Click to turn it off.') : __('Let the agent search the web for this chat')"
            @click="toggleWebSearch"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true">
              <circle cx="12" cy="12" r="9" />
              <path d="M3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18" />
            </svg>
            <span class="eg-chat__composer-web-label">{{ __('Web search') }}</span>
          </button>
          <button
            v-if="turnInFlight"
            type="button"
            class="eg-chat__composer-send eg-chat__composer-send--stop"
            :title="__('Stop')"
            :aria-label="__('Stop')"
            @click="requestStop"
          >
            <svg viewBox="0 0 20 20" fill="currentColor" width="12" height="12" aria-hidden="true"><rect x="3" y="3" width="14" height="14" rx="2.5" /></svg>
          </button>
          <button
            v-else
            type="button"
            class="eg-chat__composer-send"
            :disabled="!canGenerate"
            :title="__('Send')"
            :aria-label="__('Send')"
            @click="handleComposerSend"
          >
            <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M3.105 2.289a.75.75 0 00-.826.95l1.414 4.925A1.5 1.5 0 005.135 9.25h6.115a.75.75 0 010 1.5H5.135a1.5 1.5 0 00-1.442 1.086l-1.414 4.926a.75.75 0 00.826.95l14.095-5.638a.75.75 0 000-1.392L3.105 2.289z" /></svg>
          </button>
        </div>
      </div>

      <p v-if="(renderedStep === 2 || composerFocused) && !turnInFlight" class="eg-chat__composer-hint">{{ __('Enter to send · Shift+Enter for a new line') }}</p>

      <!-- Action buttons -->
      <div class="eg-chat__actions">
        <template v-if="renderedStep === 2 && !canGenerate">
          <span class="eg-chat__actions-hint">{{ __('Type at least 3 characters…') }}</span>
        </template>
        <template v-else-if="renderedStep === 3 && !turnInFlight">
          <span v-if="readyOrSavedSummary" class="eg-chat__actions-summary">{{ readyOrSavedSummary }}</span>
          <Button
            v-if="canSaveAll"
            variant="default"
            :disabled="store.bulkSaving"
            :text="store.bulkSaving ? __('Saving…') : __('Save all as drafts')"
            @click="handleSaveAll('draft')"
          />
        </template>
      </div>
    </div>

    <!-- Empty chat: prompt templates as a card grid under the composer.
         The first few surface immediately; "More templates" reveals the rest. -->
    <section v-if="renderedStep === 2" class="eg-chat__starters" :aria-label="__('Templates')">
      <h2 class="eg-chat__starters-label">{{ __('Start from a template') }}</h2>
      <div class="eg-chat__templates">
        <button
          v-for="tpl in visibleTemplates"
          :key="tpl.id"
          type="button"
          class="eg-templates__card"
          @click="onTemplateSelect(tpl)"
        >
          <span class="eg-templates__card-icon" aria-hidden="true" v-html="templateIconSvg(tpl.icon)"></span>
          <span class="eg-templates__card-body">
            <span class="eg-templates__card-title">{{ __(tpl.title) }}</span>
            <span class="eg-templates__card-summary">{{ __(tpl.summary) }}</span>
          </span>
          <span class="eg-templates__card-chevron" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
              <path d="M5 12h14M13 6l6 6-6 6" />
            </svg>
          </span>
        </button>
      </div>
      <button
        v-if="!showAllTemplates && allTemplates.length > initialTemplateCount"
        type="button"
        class="eg-chat__templates-more"
        @click="showAllTemplates = true"
      >
        {{ __('More templates') }}
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
          <path d="M6 9l6 6 6-6" />
        </svg>
      </button>
      <button
        v-else-if="showAllTemplates && allTemplates.length > initialTemplateCount"
        type="button"
        class="eg-chat__templates-more"
        @click="showAllTemplates = false"
      >
        {{ __('Show fewer') }}
      </button>
    </section>
    </div>
  </div>

  <!-- ═══ Classic full-page layout ═══ -->
  <div
    v-else
    class="entry-generator"
    :class="{ 'entry-generator--busy': store.generating }"
  >
    <header class="entry-generator__hero">
      <div class="entry-generator__hero-icon" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round">
          <path d="M15 4V2M15 16v-2M8 9h2M20 9h2M17.8 11.8L19 13M17.8 6.2L19 5M3 21l9-9M12.2 6.2L11 5" />
        </svg>
      </div>
      <div class="entry-generator__hero-text">
        <p class="entry-generator__eyebrow">{{ __('AI Assistant') }}</p>
        <h1 class="entry-generator__title">{{ __('Create entry with AI') }}</h1>
        <p class="entry-generator__subtitle">{{ __('Describe what you need and let AI generate the content for you') }}</p>
      </div>
    </header>

    <nav class="entry-generator__steps" :aria-label="__('Steps')">
      <div class="entry-generator__steps-track">
        <div
          v-for="(label, idx) in stepLabels"
          :key="idx"
          class="entry-generator__step"
          :class="{
            'entry-generator__step--active': step === idx + 1,
            'entry-generator__step--done': step > idx + 1,
          }"
        >
          <span class="entry-generator__step-number">
            <svg v-if="step > idx + 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="14" height="14">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
            <span v-else>{{ idx + 1 }}</span>
          </span>
          <span class="entry-generator__step-label">{{ label }}</span>
        </div>
      </div>
    </nav>

    <div v-if="step === 1" class="entry-generator__panel">
      <h2 class="entry-generator__panel-title">{{ __('Select a collection') }}</h2>
      <p class="entry-generator__panel-desc">
        {{ availableBlueprints.length > 1
          ? __('Choose the collection and blueprint for your new entry.')
          : __('Choose the collection for your new entry.') }}
      </p>
      <div class="entry-generator__form-group">
        <label class="entry-generator__label">{{ __('Collection') }}</label>
        <select v-model="selectedCollection" class="entry-generator__select" @change="onCollectionChange">
          <option value="">{{ __('Select a collection...') }}</option>
          <option v-for="col in collections" :key="col.handle" :value="col.handle">{{ col.title }}</option>
        </select>
      </div>
      <div v-if="availableBlueprints.length > 1" class="entry-generator__form-group">
        <label class="entry-generator__label">{{ __('Blueprint') }}</label>
        <select v-model="selectedBlueprint" class="entry-generator__select">
          <option v-for="bp in availableBlueprints" :key="bp.handle" :value="bp.handle">{{ bp.title }}</option>
        </select>
      </div>
      <div class="entry-generator__panel-footer">
        <Button variant="primary" :disabled="!canProceedToPrompt" :text="__('Continue')" @click="goToPrompt" />
      </div>
    </div>

    <div v-if="step === 2" class="entry-generator__panel">
      <h2 class="entry-generator__panel-title">{{ __('Describe your entry') }}</h2>
      <p class="entry-generator__panel-desc">{{ __('Tell the AI what content you want. Be as specific as you can. Paste a URL to copy a page — its text and images are pulled in for you.') }}</p>
      <div class="entry-generator__form-group">
        <label class="entry-generator__label">{{ __('Prompt') }}</label>
        <textarea ref="promptTextarea" v-model="store.pendingPrompt" class="entry-generator__textarea" :placeholder="__('e.g. Create a page about our company event last Friday. Include a summary, key highlights, and a call to action...')" rows="6" @keyup.enter.ctrl="handleGenerate" @keyup.enter.meta="handleGenerate" />
      </div>
      <div class="entry-generator__form-group">
        <label class="entry-generator__label">{{ __('Attachment (optional)') }}</label>
        <div class="entry-generator__dropzone" :class="{ 'entry-generator__dropzone--active': dragOver }" @dragover.prevent="dragOver = true" @dragleave="dragOver = false" @drop.prevent="handleDrop">
          <template v-if="!attachedFile">
            <p class="entry-generator__dropzone-text">
              {{ __('Drag & drop a PDF or text file, or') }}
              <button type="button" class="entry-generator__dropzone-browse" @click="$refs.fileInput.click()">{{ __('browse') }}</button>
            </p>
            <p class="entry-generator__dropzone-hint">{{ __('PDF, TXT — max 10 MB') }}</p>
          </template>
          <template v-else>
            <div class="entry-generator__file-chip">
              <span>{{ attachedFile.name }}</span>
              <span class="entry-generator__file-size">{{ formatFileSize(attachedFile.size) }}</span>
              <button type="button" class="entry-generator__file-remove" @click="removeFile" :title="__('Remove')">&times;</button>
            </div>
          </template>
          <input ref="fileInput" type="file" accept=".pdf,.txt" class="sr-only" @change="handleFileSelect" />
        </div>
      </div>
      <div class="entry-generator__panel-footer">
        <Button variant="default" :text="__('Back')" @click="step = 1" />
        <Button variant="primary" :disabled="!canGenerate || store.generating" :loading="store.generating" :text="__('Generate')" @click="handleGenerate" />
      </div>
    </div>

    <div v-if="step === 3" class="entry-generator__panel">
      <template v-if="store.planning && store.entries.length === 0">
        <div class="entry-generator__generating">
          <AiModalLoadingOverlay :show="true" :label="__('Working out a plan…')" />
          <div class="entry-generator__panel-footer entry-generator__panel-footer--center">
            <Button variant="default" :text="__('Stop')" @click="requestStop" />
          </div>
        </div>
      </template>
      <template v-else-if="store.generationError">
        <div class="entry-generator__error" role="alert">
          <p class="entry-generator__error-title">{{ __('Generation failed') }}</p>
          <p v-html="chatHtml(store.generationError)"></p>
          <Button variant="primary" :text="__('Try again')" @click="resetForNewRequest" />
        </div>
      </template>
      <template v-else-if="store.plannerAnswer && store.entries.length === 0">
        <h2 class="entry-generator__panel-title">{{ __('Answer') }}</h2>
        <div class="eg-chat__answer">
          <p v-html="chatHtml(store.plannerAnswer)"></p>
        </div>
        <div class="entry-generator__panel-footer">
          <Button variant="primary" :text="__('New chat')" @click="resetForNewRequest" />
        </div>
      </template>
      <template v-else>
        <h2 class="entry-generator__panel-title">{{ __('Preview') }}</h2>
        <p class="entry-generator__panel-desc">
          {{ store.entries.length > 1
            ? __('Review each generated entry. Save the ones you want; discard or retry the rest.')
            : __('Read-only snapshot of the generated content. Save to edit all fields in the Control Panel.') }}
        </p>

        <div v-if="store.plan && store.plan.warnings && store.plan.warnings.length" class="entry-generator__warnings" role="alert">
          <p class="entry-generator__warnings-title">{{ __('Notes') }}</p>
          <ul>
            <li v-for="(w, i) in store.plan.warnings" :key="i" v-html="chatHtml(w)"></li>
          </ul>
        </div>

        <div v-if="stopConfirmOpen" class="entry-generator__warnings eg-stop-confirm" role="alertdialog">
          <p class="entry-generator__warnings-title">{{ __('Stop generation?') }}</p>
          <p v-if="readyDraftedCount > 0">{{ __(':n entries are already drafted.', { n: readyDraftedCount }) }}</p>
          <p v-else>{{ __('No entries have been drafted yet.') }}</p>
          <div class="eg-stop-confirm__actions">
            <Button variant="default" :text="__('Continue generating')" @click="cancelStopConfirm" />
            <Button variant="default" :text="__('Discard everything')" @click="confirmStop(false)" />
            <Button
              v-if="readyDraftedCount > 0"
              variant="primary"
              :text="__('Keep drafted (:n)', { n: readyDraftedCount })"
              @click="confirmStop(true)"
            />
          </div>
        </div>

        <div class="entry-generator__cards">
          <EntryGeneratorEntryCard
            v-for="(entry, idx) in store.entries"
            :key="entry.id"
            :entry="entry"
            :index="idx"
            :auto-expand="store.entries.length === 1"
            @save="(mode) => handleCardSave(entry.id, mode)"
            @retry="handleCardRetry(entry.id)"
            @discard="handleCardDiscard(entry.id)"
            @view="viewEntry"
          />
        </div>

        <div class="entry-generator__panel-footer">
          <Button variant="default" :text="__('Back')" @click="resetForNewRequest" />
          <Button
            v-if="store.generating"
            variant="default"
            :disabled="stopConfirmOpen"
            :text="__('Stop')"
            @click="requestStop"
          />
          <Button
            v-if="canSaveAll"
            variant="default"
            :disabled="store.bulkSaving"
            :loading="store.bulkSaving"
            :text="__('Save all as drafts')"
            @click="handleSaveAll('draft')"
          />
        </div>
      </template>
    </div>

  </div>
</template>

<script>
import axios from 'axios';
import { Button } from '@statamic/cms/ui';
import AiModalLoadingOverlay from './AiModalLoadingOverlay.vue';
import AgentAssetSelectorStack from './AgentAssetSelectorStack.vue';
import EntryGeneratorEntryCard from './EntryGeneratorEntryCard.vue';
import { defineAsyncComponent } from 'vue';
import EntryGeneratorChatHistory from './EntryGeneratorChatHistory.vue';
import PromptTemplateForm from './PromptTemplateForm.vue';

// TipTap only loads when the drawer composer actually renders — it must not
// weigh down the CP-wide bundle (this addon's JS runs on every CP page).
const AgentComposerInput = defineAsyncComponent(() => import('./AgentComposerInput.vue'));
import { promptTemplates } from '../promptTemplates/index.js';
import {
  state,
  STATUS,
  setActive,
  startGeneration,
  continueGeneration,
  saveCard,
  saveAll,
  retryCard,
  discardCard,
  reset as storeReset,
  stopGeneration,
  stopChatTurn,
  registerMention,
  addPendingFile,
  removePendingFile,
  listChatHistory,
  openChatFromHistory,
  deleteChatFromHistory,
  setI18n,
  setToaster,
  setWebSearch,
  currentTurnSteps,
} from '../store/entryGeneratorStore.js';
import { formatChatMessageHtml, formatAgentReplyHtml } from '../formatChatUrls.js';
import { friendlyChatError, CHAT_ERROR_ACTION } from '../utils/chatErrors.js';
import { visitCp } from '../utils/cpNavigation.js';
import { formatFileSize } from '../composer/promptTokens.js';

/** Entry id from a Statamic CP publish URL, or null on listings and create screens. */
function entryIdFromCpPath(pathname) {
  const match = String(pathname || '').match(/\/collections\/[^/]+\/entries\/([^/?#]+)/);
  if (!match) return null;
  let id = match[1];
  try { id = decodeURIComponent(id); } catch { /* keep the raw segment */ }
  if (id === '' || id === 'create') return null;
  return id;
}

const cpLocationListeners = new Set();
let cpHistoryPatched = false;

function ensureCpHistoryPatch() {
  if (cpHistoryPatched || typeof history === 'undefined') return;
  cpHistoryPatched = true;
  for (const name of ['pushState', 'replaceState']) {
    const original = history[name];
    history[name] = function historyWithCpLocation(...args) {
      const result = original.apply(this, args);
      cpLocationListeners.forEach((fn) => fn());
      return result;
    };
  }
}

/** Fires on SPA navigations and browser back/forward. Returns an unsubscribe. */
function watchCpLocation(fn) {
  ensureCpHistoryPatch();
  cpLocationListeners.add(fn);
  window.addEventListener('popstate', fn);
  return () => {
    cpLocationListeners.delete(fn);
    window.removeEventListener('popstate', fn);
  };
}

export default {
  name: 'EntryGeneratorPage',

  emits: ['minimize'],
  components: { Button, AiModalLoadingOverlay, EntryGeneratorEntryCard, AgentAssetSelectorStack, EntryGeneratorChatHistory, AgentComposerInput, PromptTemplateForm },

  props: {
    drawer: { type: Boolean, default: false },
    /**
     * Drawer-only: whether the panel is currently visible to the user.
     * When false during generation, the store treats itself as "minimized" and
     * surfaces completion via toasts + bubble badge instead of in-panel UX.
     */
    active: { type: Boolean, default: true },
  },

  data() {
    return {
      // Local UI-only state. Generation/composer state lives in the store.
      step: 1,
      // Advanced structure tools: per-user opt-in (Statamic preference). The
      // toggle only renders when the user holds the access grant; enabling
      // requires confirming the warning modal.
      advancedTools: { granted: false, enabled: false, saving: false },
      advancedToolsModalOpen: false,

      // Chat history slide-over (drawer only). The list is re-read from
      // localStorage each time the panel opens — no live sync needed.
      historyOpen: false,
      historyChats: [],
      // Prompt templates: shown inline as the initial chat content (first 3,
      // then "More templates" reveals the rest). `templatesOpen` + `activeTemplate`
      // drive the clarifying-questions form slide-over for templates that need it.
      templatesOpen: false,
      activeTemplate: null,
      showAllTemplates: false,
      // Grid of two columns: four cards fill two even rows.
      initialTemplateCount: 4,
      collections: [],
      selectedCollection: '',
      selectedBlueprint: '',
      dragOver: false,

      // Local helper for the planning ticker (purely cosmetic — drives the planning bar).
      planTickerHandle: null,
      planTicker: 0,

      // True while the user is being asked to confirm a stop (keep / discard).
      stopConfirmOpen: false,

      // Drawer chat: only auto-scroll when the user is already near the bottom (or we force).
      chatStickToBottom: true,
      _chatStreamScrollSilent: false,

      // Working line: clock for the elapsed timer, and which step lists are open.
      nowMs: Date.now(),
      workClock: null,
      liveWorkOpen: false,
      openWork: {},
      copiedKey: null,
      composerFocused: false,

      // @-mention entry picker (composer typeahead).
      mention: {
        open: false,
        query: '',
        results: [],
        activeIndex: 0,
        loading: false,
        _reqId: 0,
        _debounce: null,
        _blurTimer: null,
      },

      // Preview modal for "@asset:" chips in the chat (click to open).
      assetPreview: {
        open: false,
        loading: false,
        kind: 'asset',
        ref: null,
        data: null,
      },
      _assetPreviewCache: {},

      // Native Statamic asset browser (Stack): folder chips + composer button.
      assetBrowser: {
        open: false,
        container: null,
        columns: [],
        folder: '',
      },
      _assetBrowserCache: {},

      // Expose the reactive store to the template.
      store: state,

      // Entry open in the CP, inserted as the composer's default @mention.
      // suppressed: the editor removed that chip from the current draft.
      contextEntry: null,
      _entryMentionCache: {},
      _contextSeq: 0,
      _applyingContext: false,
      _offCpLocation: null,
    };
  },

  computed: {
    stepLabels() {
      return [this.__('Configure'), this.__('Prompt'), this.__('Preview')];
    },

    /** All registered prompt templates, in registry order. */
    allTemplates() {
      return promptTemplates;
    },

    /** Whether the composer offers web search (OpenRouter, enabled, not on a regional host). */
    webSearchAvailable() {
      return !!Statamic.$config.get('agentWebSearchAvailable');
    },

    /** Empty-chat headline, addressed to the signed-in user by first name. */
    heroGreeting() {
      const name = String(Statamic.$config.get('user')?.name || '').trim().split(/\s+/)[0];
      return name
        ? this.__('What should we create, :name?', { name })
        : this.__('What should we create today?');
    },

    /** Templates shown in the empty chat: the first few unless expanded. */
    visibleTemplates() {
      const all = this.allTemplates;
      if (this.showAllTemplates || all.length <= this.initialTemplateCount) {
        return all;
      }
      return all.slice(0, this.initialTemplateCount);
    },

    /** Deep link into Statamic's asset browser for the previewed ref. */
    assetBrowseUrl() {
      const ref = this.assetPreview.ref || '';
      const [container, path = ''] = ref.split('::');
      if (!container) return '#';
      const encoded = path.split('/').filter(Boolean).map(encodeURIComponent).join('/');
      const base = `/cp/assets/browse/${encodeURIComponent(container)}`;
      if (this.assetPreview.kind === 'folder') {
        return encoded ? `${base}/${encoded}` : base;
      }
      return `${base}/${encoded}/edit`;
    },

    availableBlueprints() {
      const col = this.collections.find((c) => c.handle === this.selectedCollection);
      return col ? col.blueprints : [];
    },

    canProceedToPrompt() {
      return this.selectedCollection && this.selectedBlueprint;
    },

    /** A reply in an ongoing chat ("ja", "ok") may be short; a first request needs a few characters. */
    isReply() {
      return this.drawer && (this.store.chatSessionId || this.store.transcript.length > 0);
    },

    canGenerate() {
      const length = (this.store.pendingPrompt || '').trim().length;
      return this.isReply ? length >= 1 : length >= 3;
    },

    /**
     * The wizard's single (chipless) attachment. Drawer attachments carry a
     * token and render as "@file:" chips instead.
     */
    attachedFile() {
      const entry = this.store.pendingAttachedFiles.find((f) => f.token === null);
      return entry ? entry.file : null;
    },

    /** Pending attachment metadata by token, for the composer's file chips. */
    pendingFileMeta() {
      const meta = {};
      for (const { token, file } of this.store.pendingAttachedFiles) {
        if (token) meta[token] = { name: file.name, size: file.size };
      }
      return meta;
    },

    /** Whether the previewed asset is a non-image document (PDF, TXT, …). */
    assetPreviewIsDocument() {
      const data = this.assetPreview.data;
      if (!data || !data.ok || data.kind !== 'asset') return false;
      return data.preview ? data.preview !== 'image' : data.is_image === false;
    },

    promptRecap() {
      // Echo the user's message in full — never truncate what they typed.
      return (this.store.pendingPrompt || '').trim();
    },

    /** Composer hint: first message vs. a follow-up in an ongoing chat. */
    composerPlaceholder() {
      return this.drawer && this.store.chatSessionId
        ? this.__('Reply… e.g. “add a FAQ section to this entry”.')
        : this.__('Describe one or more entries you want to create… Paste a URL to copy a page (text and images).');
    },

    /**
     * Drawer step: when the store has plan/entries/error/generating, render step 3.
     * Otherwise render step 2 (composer).
     * Full-page mode uses `step` directly (1/2/3).
     */
    renderedStep() {
      if (!this.drawer) return this.step;
      const s = this.store;
      if (s.transcript.length > 0 || s.plan || s.entries.length > 0 || s.generating || s.planning || s.generationError || s.plannerAnswer) {
        return 3;
      }
      return 2;
    },

    /**
     * The conversation as renderable turns. Assistant turns carry the entry cards
     * they produced (matched by id) so cards appear inline beneath their reply.
     */
    chatTurns() {
      return (this.store.transcript || []).map((t, i) => ({
        key: `${i}-${t.role}`,
        role: t.role,
        text: t.text,
        kind: t.kind,
        approveLabel: typeof t.approve_label === 'string' ? t.approve_label.trim() : '',
        work: t.work && Number.isFinite(t.work.ms) ? { ms: t.work.ms, steps: Array.isArray(t.work.steps) ? t.work.steps : [] } : null,
        sources: t.role === 'assistant' ? this.sourceChips(t.citations) : [],
        cards: t.role === 'assistant' && Array.isArray(t.entry_ids)
          ? this.store.entries.filter((e) => t.entry_ids.includes(e.id))
          : [],
      }));
    },

    /** Key of the newest transcript turn — a plan's approve action is only offered while it is the latest word. */
    latestTurnKey() {
      const turns = this.chatTurns;
      return turns.length ? turns[turns.length - 1].key : null;
    },

    /**
     * Cards for the in-flight turn — not yet claimed by any transcript turn
     * (the assistant summary turn is appended only once the turn completes).
     */
    liveCards() {
      const claimed = new Set(
        (this.store.transcript || []).flatMap((t) => (Array.isArray(t.entry_ids) ? t.entry_ids : [])),
      );
      return this.store.entries.filter((e) => !claimed.has(e.id));
    },

    /** A turn is running (planning, drafting or waiting for a worker). */
    turnInFlight() {
      return this.store.generating || this.store.planning;
    },

    /** The running turn's real steps (planner activity since the message was sent). */
    liveSteps() {
      return currentTurnSteps();
    },

    /** What the agent is doing right now, in one short line. */
    currentStepText() {
      if (this.waitingForQueue) return this.__('Waiting for the request to start…');
      if (this.store.planning) {
        const steps = this.liveSteps;
        return steps.length ? steps[steps.length - 1] : this.__('Thinking…');
      }
      return this.draftingStepText;
    },

    draftingStepText() {
      const entries = this.store.entries;
      const drafting = entries.find((e) => e.status === STATUS.DRAFTING || e.status === STATUS.QUEUED);
      if (!drafting) return this.__('Finishing up…');
      const label = drafting.label || drafting.collectionTitle || '';
      if (entries.length < 2) return this.__('Writing “:label”', { label });
      const done = entries.filter((e) => [STATUS.READY, STATUS.SAVED, STATUS.FAILED].includes(e.status)).length;
      return this.__('Writing “:label” · draft :n of :total', { label, n: Math.min(done + 1, entries.length), total: entries.length });
    },

    /** "13s", "1m 05s" since the editor sent the message; empty when unknown. */
    elapsedLabel() {
      if (!this.store.turnStartedAt) return '';
      return this.formatDuration(this.nowMs - this.store.turnStartedAt);
    },

    showJumpToLatest() {
      return this.renderedStep === 3 && !this.chatStickToBottom;
    },

    /** The editor's newest message (what "Try again" sends again). */
    lastUserText() {
      const turns = this.store.transcript || [];
      for (let i = turns.length - 1; i >= 0; i -= 1) {
        if (turns[i].role === 'user' && (turns[i].text || '').trim()) return turns[i].text;
      }
      return '';
    },

    planProgressPercent() {
      const t = this.planTicker;
      return Math.min(85, 15 + t * 6);
    },

    /** The planning job is queued but no worker has started it yet. */
    waitingForQueue() {
      return this.store.planning && this.store.planningWaitSeconds !== null;
    },

    /** Waiting long enough that a missing queue worker is the likely cause. */
    queueLooksStuck() {
      return this.waitingForQueue && this.store.planningWaitSeconds >= 15;
    },

    canSaveAll() {
      return this.store.entries.filter((e) => e.status === STATUS.READY).length > 1;
    },

    /** Number of entries that have finished drafting and are usable if the user stops now. */
    readyDraftedCount() {
      return this.store.entries.filter((e) => e.status === STATUS.READY).length;
    },

    readyOrSavedSummary() {
      if (this.store.entries.length <= 1) return '';
      const saved = this.store.entries.filter((e) => e.status === STATUS.SAVED).length;
      const ready = this.store.entries.filter((e) => e.status === STATUS.READY).length;
      const failed = this.store.entries.filter((e) => e.status === STATUS.FAILED).length;
      const parts = [];
      if (saved) parts.push(this.__(':n saved', { n: saved }));
      if (ready) parts.push(this.__(':n ready', { n: ready }));
      if (failed) parts.push(this.__(':n failed', { n: failed }));
      return parts.join(' · ');
    },

    /** Avoid duplicating an error already folded into the chat transcript. */
    showLiveGenerationError() {
      if (!this.store.generationError) return false;
      const transcript = this.store.transcript || [];
      const last = transcript[transcript.length - 1];
      return !(last?.role === 'assistant' && last?.kind === 'error');
    },
  },

  watch: {
    renderedStep(newVal, oldVal) {
      this.$nextTick(() => {
        if (!this.drawer) return;
        const stepChanged = oldVal !== undefined && newVal !== oldVal;
        this.scrollChatToBottom(stepChanged);
      });
    },
    'store.entries': {
      handler() {
        this.$nextTick(() => this.scrollChatToBottom());
      },
      deep: true,
    },
    'store.transcript': {
      handler() {
        this.$nextTick(() => this.scrollChatToBottom());
      },
      deep: true,
    },
    'store.planning'(now) {
      if (now) {
        this.startPlanTicker();
      } else {
        this.stopPlanTicker();
      }
    },
    turnInFlight: {
      immediate: true,
      handler(now) {
        if (now) {
          this.liveWorkOpen = false;
          this.startWorkClock();
        } else {
          this.stopWorkClock();
        }
      },
    },
    'store.activityLog': {
      handler() {
        this.$nextTick(() => this.scrollChatToBottom());
      },
      deep: true,
    },
    // A failure notice appears below the last message: bring it fully into
    // view instead of leaving it half under the composer.
    showLiveGenerationError(now) {
      if (now) this.$nextTick(() => this.scrollChatToBottom(true));
    },
    active: {
      immediate: true,
      handler(now) {
        if (!this.drawer) return;
        setActive(now);
        if (now) {
          this.chatStickToBottom = true;
          this.seedOpenEntry();
          this.$nextTick(() => {
            this.scrollChatToBottom(true);
            // Reopening: the editor already exists, so focus it here. On the
            // first open it loads lazily and focuses itself (autofocus prop).
            if (!this.store.generating) this.$refs.composer?.focus();
          });
        }
      },
    },
    'store.pendingPrompt'(prompt, previous) {
      if (this._applyingContext || !this.drawer) return;
      const ctx = this.contextEntry;
      if (!ctx?.title) return;
      const token = `@${ctx.title}`;
      const text = String(prompt ?? '');
      if (text.includes(token)) return;

      // The chip was removed but the draft continues: leave it out.
      if (text.trim() !== '') {
        this.contextEntry = { ...ctx, suppressed: true };
        return;
      }

      // The draft was only the chip and they deleted it.
      if (String(previous ?? '').trim() === token) {
        this.contextEntry = { ...ctx, suppressed: true };
        return;
      }

      // Composer cleared after a send. The next message still starts from the open entry.
      this.seedOpenEntry({ force: true });
    },
  },

  mounted() {
    setI18n((s, opts) => this.__(s, opts || {}));
    setToaster({
      success: (m) => Statamic.$toast?.success?.(m),
      error: (m) => Statamic.$toast?.error?.(m),
    });
    this.initializePage();
    if (this.store.planning) {
      this.startPlanTicker();
    }
    window.addEventListener('keydown', this.onAssetPreviewKeydown);
    if (this.drawer) {
      this._offCpLocation = watchCpLocation(() => this.seedOpenEntry());
    }
  },

  beforeUnmount() {
    this.stopPlanTicker();
    this.stopWorkClock();
    this._offCpLocation?.();
    this._offCpLocation = null;
    clearTimeout(this.mention._debounce);
    clearTimeout(this.mention._blurTimer);
    window.removeEventListener('keydown', this.onAssetPreviewKeydown);
    // NOTE: We deliberately do NOT abort the in-flight stream here. The store
    // owns it and it must survive component teardown so generation continues
    // when the user navigates away.
    if (this.drawer) setActive(false);
  },

  methods: {
    chatHtml(text, mentionTitles, attachments) {
      const titles = Array.isArray(mentionTitles) && mentionTitles.length
        ? mentionTitles
        : this.store.mentionedTitles;
      return formatChatMessageHtml(text, titles, Array.isArray(attachments) ? attachments : []);
    },

    async initializePage() {
      await this.loadCollections();
      const params = new URLSearchParams(window.location.search);
      const preselect = params.get('collection');
      if (preselect) {
        this.selectedCollection = preselect;
        this.onCollectionChange();
      }
      if (this.drawer) {
        // Drawer always lands on the composer or the cards (driven by renderedStep).
        this.step = 2;
        this.loadAdvancedToolsPreference();
      }
    },

    toggleWebSearch() {
      setWebSearch(!this.store.webSearch);
    },

    /**
     * A reply's web search sources as chips: title (or the host when the
     * source has none) and host. Only http(s) links.
     */
    sourceChips(citations) {
      if (!Array.isArray(citations)) return [];
      return citations
        .filter((c) => c && typeof c.url === 'string' && /^https?:\/\//i.test(c.url))
        .map((c) => {
          let host = '';
          try {
            host = new URL(c.url).hostname.replace(/^www\./, '');
          } catch (e) {
            host = c.url;
          }
          return { url: c.url, host, title: (c.title || '').trim() || host };
        });
    },

    /**
     * Copy the whole visible conversation (plus the session id, so devs can
     * match server logs) to the clipboard — the "send this weird chat to the
     * developers" affordance.
     */
    async copyConversation() {
      const turns = this.store.transcript || [];
      if (!turns.length) {
        Statamic.$toast?.error?.(this.__('Nothing to copy yet.'));
        return;
      }

      const sid = this.store.chatSessionId;
      const lines = [`AI agent conversation${sid ? ` (session ${sid})` : ''} — ${new Date().toISOString()}`, ''];

      for (const turn of turns) {
        const who = turn.role === 'user' ? this.__('You') : this.__('AI agent');
        const kind = turn.kind && turn.kind !== 'summary' ? ` [${turn.kind}]` : '';
        lines.push(`${who}${kind}:`, turn.text || '');
        const sources = turn.role === 'assistant' ? this.sourceChips(turn.citations) : [];
        if (sources.length) {
          lines.push(`${this.__('Sources')}:`, ...sources.map((src) => `- ${src.title}: ${src.url}`));
        }
        lines.push('');
      }
      // The raw error stays out of the chat bubble, but developers need it.
      if (this.store.generationError) {
        lines.push('Error (raw):', String(this.store.generationError), '');
      }

      const text = lines.join('\n');

      try {
        await navigator.clipboard.writeText(text);
      } catch (e) {
        // Clipboard API unavailable (permissions/older context) — fallback.
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }

      Statamic.$toast?.success?.(this.__('Conversation copied to clipboard.'));
    },

    async loadAdvancedToolsPreference() {
      try {
        const { data } = await axios.get('/cp/ai-generate/advanced-tools');
        this.advancedTools.granted = !!data.granted;
        this.advancedTools.enabled = !!data.enabled;
      } catch (error) {
        // Toggle simply stays hidden when the state can't be loaded.
      }
    },

    onAdvancedToolsToggle(event) {
      const wantsEnabled = !!event.target.checked;
      // Keep the checkbox reflecting persisted state until confirmed/saved.
      event.target.checked = this.advancedTools.enabled;

      if (wantsEnabled && !this.advancedTools.enabled) {
        this.advancedToolsModalOpen = true;
        return;
      }

      if (!wantsEnabled && this.advancedTools.enabled) {
        this.saveAdvancedToolsPreference(false);
      }
    },

    cancelAdvancedTools() {
      if (this.advancedTools.saving) return;
      this.advancedToolsModalOpen = false;
    },

    confirmAdvancedTools() {
      this.saveAdvancedToolsPreference(true);
    },

    async saveAdvancedToolsPreference(enabled) {
      this.advancedTools.saving = true;
      try {
        const { data } = await axios.post('/cp/ai-generate/advanced-tools', { enabled });
        this.advancedTools.enabled = !!data.enabled;
        this.advancedToolsModalOpen = false;
        if (enabled && data.enabled) {
          Statamic.$toast?.success?.(this.__('Advanced structure tools enabled. Verify every structural change before committing.'));
        }
      } catch (error) {
        Statamic.$toast?.error?.(this.__('Could not save the advanced tools setting.'));
      } finally {
        this.advancedTools.saving = false;
      }
    },

    /** Distance from bottom of `el` in px; smaller = nearer the end. */
    chatStreamDistanceFromBottom(el) {
      if (!el) return 0;
      return el.scrollHeight - el.scrollTop - el.clientHeight;
    },

    onChatStreamScroll() {
      if (!this.drawer || this._chatStreamScrollSilent) return;
      const el = this.$refs.chatStream;
      if (!el) return;
      const threshold = 80;
      this.chatStickToBottom = this.chatStreamDistanceFromBottom(el) <= threshold;
    },

    /**
     * Delegated click on "@asset:" / "@folder:" chips (v-html content). Assets
     * open the compact preview modal; folders open the native asset browser at
     * that folder. Clicks bubble reliably, so no per-chip binding needed.
     */
    onChatStreamClick(event) {
      const chip = event.target.closest?.('[data-mention-kind]');
      if (!chip) return;

      const kind = chip.getAttribute('data-mention-kind') || 'asset';

      if (kind === 'entry') {
        const title = chip.getAttribute('data-mention-title');
        if (title) this.openEntryByTitle(title);
        return;
      }

      const ref = chip.getAttribute('data-mention-ref');
      if (!ref) return;

      this.openMentionRef(kind, ref);
    },

    /**
     * Open an "@Title" entry mention in the CP editor (new tab, so the chat
     * survives). Chips only carry the title, so it is resolved through the
     * same entry-search the "@" picker uses.
     */
    async openEntryByTitle(title) {
      try {
        const { data } = await axios.get('/cp/ai-generate/entry-search', {
          params: { q: title, limit: 8 },
        });
        const results = (Array.isArray(data.results) ? data.results : []).filter((r) => r.kind === 'entry');
        const match = results.find((r) => (r.title || '').toLowerCase() === title.toLowerCase()) || results[0];

        if (!match || !match.id || !match.collection) {
          Statamic.$toast?.error?.(this.__('Entry ":title" not found.', { title }));
          return;
        }

        const url = `/cp/collections/${encodeURIComponent(match.collection)}/entries/${encodeURIComponent(match.id)}`;
        const win = window.open(url, '_blank');
        if (win) {
          try { win.opener = null; } catch { /* ignore */ }
        }
      } catch (e) {
        Statamic.$toast?.error?.(this.__('Entry ":title" not found.', { title }));
      }
    },

    /** Open an asset/folder reference — assets in the preview modal, folders in the browser. */
    openMentionRef(kind, ref) {
      if (kind === 'folder') {
        const [container, folder = ''] = ref.split('::');
        this.openAssetBrowser(container, folder);
        return;
      }

      this.openAssetPreview(kind, ref);
    },

    /** A chip in the composer was clicked — same affordances as in the transcript. */
    onComposerChipClick({ kind, text }) {
      if (kind === 'url') {
        const win = window.open(text, '_blank');
        if (win) {
          try { win.opener = null; } catch { /* ignore */ }
        }
        return;
      }

      if (kind === 'asset' || kind === 'folder') {
        this.openMentionRef(kind, text.replace(/^@(asset|folder):/, ''));
        return;
      }

      if (kind === 'entry') {
        this.openEntryByTitle(text.replace(/^@/, ''));
      }
    },

    /**
     * Open Statamic's native asset browser (in a Stack) at a container/folder.
     * Called with no arguments (composer button) it opens the first container's
     * root. The container payload comes from /asset-browser and is cached.
     */
    async openAssetBrowser(containerHandle = '', folder = '') {
      const cacheKey = containerHandle || '~first~';
      let payload = this._assetBrowserCache[cacheKey];

      if (!payload) {
        try {
          const { data } = await axios.get('/cp/ai-generate/asset-browser', {
            params: containerHandle ? { container: containerHandle } : {},
          });
          if (!data.ok) throw new Error('unavailable');
          payload = data;
          this._assetBrowserCache[cacheKey] = data;
        } catch (e) {
          Statamic.$toast?.error?.(this.__('Asset browser unavailable.'));
          return;
        }
      }

      this.assetBrowser.container = payload.container;
      this.assetBrowser.columns = payload.columns || [];
      this.assetBrowser.folder = folder;
      this.assetBrowser.open = true;
    },

    /**
     * Append "@asset:…" / "@folder:…" tokens to the composer — the "reference
     * in chat" action of the preview modal and the asset browser Stack.
     */
    insertRefTokens(tokens) {
      const list = (Array.isArray(tokens) ? tokens : [tokens]).filter(Boolean);
      if (!list.length) return;

      // Register FIRST so the composer's re-parse renders the tokens as chips.
      list.forEach((t) => registerMention(t));
      const current = this.store.pendingPrompt || '';
      const glue = current === '' || current.endsWith(' ') ? '' : ' ';
      this.store.pendingPrompt = current + glue + list.map((t) => `@${t}`).join(' ') + ' ';

      this.$nextTick(() => this.$refs.composer?.focus());
    },

    insertPreviewedAssetRef() {
      if (!this.assetPreview.ref) return;
      this.insertRefTokens([`${this.assetPreview.kind}:${this.assetPreview.ref}`]);
      this.closeAssetPreview();
    },

    /** Document assets: the real preview is Statamic's asset editor (new tab). */
    openPreviewedAssetInCp() {
      const win = window.open(this.assetBrowseUrl, '_blank');
      if (win) {
        try { win.opener = null; } catch { /* ignore */ }
      }
    },

    onAssetPreviewKeydown(event) {
      if (event.key === 'Escape' && this.assetPreview.open) this.closeAssetPreview();
    },

    closeAssetPreview() {
      this.assetPreview.open = false;
      this.assetPreview.ref = null;
    },

    async openAssetPreview(kind, ref) {
      this.assetPreview.kind = kind;
      this.assetPreview.ref = ref;
      this.assetPreview.open = true;

      const cacheKey = `${kind}:${ref}`;
      const cached = this._assetPreviewCache[cacheKey];
      if (cached) {
        this.assetPreview.loading = false;
        this.assetPreview.data = cached;
        return;
      }

      this.assetPreview.loading = true;
      this.assetPreview.data = null;

      try {
        const { data } = await axios.get('/cp/ai-generate/asset-preview', {
          params: { ref, kind },
          validateStatus: (status) => status < 500,
        });
        this._assetPreviewCache[cacheKey] = data;
        // Ignore a response for a ref the user has already navigated away from.
        if (this.assetPreview.ref !== ref) return;
        this.assetPreview.data = data;
      } catch (e) {
        if (this.assetPreview.ref !== ref) return;
        this.assetPreview.data = { ok: false };
      } finally {
        if (this.assetPreview.ref === ref) this.assetPreview.loading = false;
      }
    },

    /**
     * @param {boolean} force  When true, always jump to bottom (new run, drawer opened, step change).
     */
    scrollChatToBottom(force = false) {
      if (!this.drawer) return;
      const el = this.$refs.chatStream;
      if (!el) return;
      if (!force && !this.chatStickToBottom) return;
      this._chatStreamScrollSilent = true;
      el.scrollTop = el.scrollHeight;
      this.chatStickToBottom = true;
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          this._chatStreamScrollSilent = false;
        });
      });
    },

    startWorkClock() {
      this.stopWorkClock();
      this.nowMs = Date.now();
      this.workClock = setInterval(() => { this.nowMs = Date.now(); }, 1000);
    },

    stopWorkClock() {
      if (this.workClock) {
        clearInterval(this.workClock);
        this.workClock = null;
      }
    },

    /** 9s · 1m 05s · 1h 02m */
    formatDuration(ms) {
      const total = Math.max(0, Math.floor((ms || 0) / 1000));
      if (total < 60) return `${total}s`;
      const m = Math.floor(total / 60);
      if (m < 60) return `${m}m ${String(total % 60).padStart(2, '0')}s`;
      return `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, '0')}m`;
    },

    toggleWork(key) {
      this.openWork = { ...this.openWork, [key]: !this.openWork[key] };
    },

    /** Agent replies: Markdown (lists, headings, code) plus the usual chips. */
    agentHtml(text) {
      return formatAgentReplyHtml(text, this.store.mentionedTitles, []);
    },

    /** A raw failure as a short plain hint plus the action that helps. */
    errorHint(raw) {
      const hint = friendlyChatError(raw, { offline: typeof navigator !== 'undefined' && navigator.onLine === false });
      return { message: hint.text || this.__(hint.key), action: hint.action };
    },

    onErrorAction(action) {
      if (action === CHAT_ERROR_ACTION.NEW_CHAT) {
        this.resetForNewRequest();
        return;
      }
      this.retryLastMessage();
    },

    /** Send the editor's newest message again (after an error, or for a fresh answer). */
    retryLastMessage() {
      const text = this.lastUserText;
      if (!text || this.turnInFlight) return;
      // An unanswered trailing message would otherwise show twice.
      const turns = this.store.transcript;
      if (turns.length && turns[turns.length - 1].role === 'user' && turns[turns.length - 1].text === text) {
        turns.pop();
      }
      this.store.generationError = null;
      this.store.pendingPrompt = text;
      this.handleComposerSend();
    },

    /** Open an entry in the CP behind the agent and minimise the agent to show it. */
    viewEntry(url) {
      visitCp(url);
      this.$emit('minimize');
    },

    async copyReply(turn) {
      await this.writeClipboard(turn.text || '');
      this.copiedKey = turn.key;
      clearTimeout(this._copiedTimer);
      this._copiedTimer = setTimeout(() => { this.copiedKey = null; }, 1500);
    },

    async writeClipboard(text) {
      try {
        await navigator.clipboard.writeText(text);
      } catch (e) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }
    },

    jumpToLatest() {
      this.scrollChatToBottom(true);
    },

    async loadCollections() {
      try {
        const { data } = await axios.get('/cp/ai-generate/collections');
        this.collections = data.collections;
        if (this.selectedCollection) this.onCollectionChange();
      } catch (e) {
        console.error('Load collections error:', e.response?.data || e.message);
        Statamic.$toast.error(this.__('Could not load collections.'));
      }
    },

    onCollectionChange() {
      const bps = this.availableBlueprints;
      this.selectedBlueprint = bps.length === 1 ? bps[0].handle : (bps[0]?.handle || '');
    },

    goToPrompt() {
      if (!this.canProceedToPrompt) return;
      this.step = 2;
    },

    startPlanTicker() {
      this.stopPlanTicker();
      this.planTicker = 0;
      this.planTickerHandle = setInterval(() => { this.planTicker += 1; }, 600);
    },

    stopPlanTicker() {
      if (this.planTickerHandle) {
        clearInterval(this.planTickerHandle);
        this.planTickerHandle = null;
      }
    },

    resetForNewRequest() {
      storeReset();
      if (!this.drawer) this.step = 2;
      this.showAllTemplates = false;
      this.activeTemplate = null;
      this.templatesOpen = false;
      this.chatStickToBottom = true;
      this.seedOpenEntry({ force: true });
    },

    /**
     * Put the entry open in the Control Panel into the composer as an @mention,
     * so "this page" already names it. No-op away from an entry edit screen.
     */
    async seedOpenEntry({ force = false } = {}) {
      if (!this.drawer || !this.active) return;
      // Only a new conversation starts from the open entry. Mid-conversation
      // the editor talks to the agent; re-inserting the entry after every
      // message (or after the agent opened a draft) gets in the way.
      if ((this.store.transcript || []).length > 0) return;
      const id = entryIdFromCpPath(window.location.pathname);
      if (!id) return;

      const known = this.contextEntry;
      const prompt = this.store.pendingPrompt || '';
      if (!force && known?.id === id) {
        if (known.suppressed) return;
        if (known.title && prompt.includes(`@${known.title}`)) return;
      }

      const seq = ++this._contextSeq;
      let entry = this._entryMentionCache[id];
      if (!entry) {
        try {
          const { data } = await axios.get('/cp/ai-generate/open-entry', { params: { id } });
          entry = data?.entry || null;
        } catch {
          entry = null;
        }
        if (entry?.title) this._entryMentionCache[id] = entry;
      }
      if (seq !== this._contextSeq) return;
      if (entryIdFromCpPath(window.location.pathname) !== id || !entry?.title) return;
      if (!force && this.contextEntry?.id === id && this.contextEntry.suppressed) return;

      this.applyOpenEntryMention(entry);
    },

    applyOpenEntryMention(entry) {
      const title = String(entry.title || '').trim();
      if (!title) return;

      registerMention(title);
      const token = `@${title}`;
      let prompt = this.store.pendingPrompt || '';
      const prev = this.contextEntry;
      if (prev && prev.id !== String(entry.id) && prev.title && prompt.startsWith(`@${prev.title}`)) {
        prompt = prompt.slice(`@${prev.title}`.length).replace(/^ /, '');
      }
      if (!prompt.includes(token)) {
        prompt = prompt.trim() === '' ? `${token} ` : `${token} ${prompt.replace(/^\s+/, '')}`;
      }

      this._applyingContext = true;
      this.contextEntry = { id: String(entry.id), title, suppressed: false };
      if (prompt !== this.store.pendingPrompt) {
        this.store.pendingPrompt = prompt;
      }
      this.$nextTick(() => { this._applyingContext = false; });
    },

    // ── Chat history (drawer) ──

    toggleHistory() {
      if (this.historyOpen) {
        this.historyOpen = false;
        return;
      }
      this.historyChats = listChatHistory();
      this.historyOpen = true;
    },

    /** Close the history sidebar and the template sheet (scrim click). */
    closePanels() {
      this.historyOpen = false;
      this.templatesOpen = false;
      this.activeTemplate = null;
    },

    startNewChat() {
      if (this.store.generating || this.store.planning) return;
      this.resetForNewRequest();
      this.historyOpen = false;
      this.$nextTick(() => this.$refs.composer?.focus());
    },

    // ── Prompt templates (drawer) ──

    templateIconSvg(name) {
      return TEMPLATE_ICONS[name] || TEMPLATE_ICONS.sparkles;
    },

    onTemplateSelect(template) {
      if (Array.isArray(template.questions) && template.questions.length > 0) {
        this.activeTemplate = template;
        this.templatesOpen = true;
        return;
      }
      this.applyPromptTemplate(template, {});
    },

    onTemplateApply(answers) {
      const template = this.activeTemplate;
      if (!template) return;
      this.applyPromptTemplate(template, answers);
    },

    /**
     * Build the final prompt from a template, register entry mentions so the
     * chips render in the chat, then start a fresh chat via the existing send
     * pipeline. Templates are pure client-side prompt builders — the agent's
     * tools (read_entry, update_entry_job, analyze_image, …) do the real work.
     */
    applyPromptTemplate(template, answers) {
      const prompt = String(template.buildPrompt(answers, this) || '').trim();
      if (prompt.length < 10) return;

      (template.questions || []).forEach((q) => {
        if (q.type === 'entry' && answers[q.id]?.title) {
          registerMention(answers[q.id].title);
        }
      });

      this.templatesOpen = false;
      this.activeTemplate = null;

      if (this.store.generating || this.store.planning) return;
      this.resetForNewRequest();
      this.store.pendingPrompt = prompt;
      this.$nextTick(() => this.handleGenerate());
    },

    onHistorySelect(id) {
      if (!openChatFromHistory(id)) return;
      this.historyOpen = false;
      this.chatStickToBottom = true;
      this.$nextTick(() => this.scrollChatToBottom(true));
    },

    onHistoryDelete(id) {
      deleteChatFromHistory(id);
      this.historyChats = listChatHistory();
    },

    /**
     * First right of refusal on composer keydowns: while the mention picker is
     * open, arrows/enter/tab/escape drive it instead of the editor. Returning
     * true consumes the key. (Enter-to-send lives in AgentComposerInput.)
     */
    composerKeydownInterceptor(event) {
      if (!this.mention.open) return false;

      if (event.key === 'ArrowDown') {
        this.moveMention(1);
        return true;
      }
      if (event.key === 'ArrowUp') {
        this.moveMention(-1);
        return true;
      }
      if (event.key === 'Enter' || event.key === 'Tab') {
        const item = this.mention.results[this.mention.activeIndex];
        if (item) {
          this.selectMention(item);
          return true;
        }
      }
      if (event.key === 'Escape') {
        this.closeMention();
        return true;
      }

      return false;
    },

    onComposerBlur() {
      // Delay so a mousedown selection on a list item still registers.
      this.mention._blurTimer = setTimeout(() => this.closeMention(), 120);
    },

    /** The composer's caret entered/left an "@query" token. */
    onMentionQuery(payload) {
      if (!payload || this.store.generating) {
        if (this.mention.open) this.closeMention();
        return;
      }
      this.mention.query = payload.query;
      this.mention.open = true;
      this.searchMentions(payload.query);
    },

    searchMentions(query) {
      this.mention.loading = true;
      clearTimeout(this.mention._debounce);
      this.mention._debounce = setTimeout(async () => {
        const reqId = ++this.mention._reqId;
        try {
          const { data } = await axios.get('/cp/ai-generate/entry-search', {
            params: { q: query, limit: 8 },
          });
          if (reqId !== this.mention._reqId) return;
          this.mention.results = Array.isArray(data.results) ? data.results : [];
          this.mention.activeIndex = 0;
        } catch (e) {
          if (reqId !== this.mention._reqId) return;
          this.mention.results = [];
        } finally {
          if (reqId === this.mention._reqId) this.mention.loading = false;
        }
      }, 160);
    },

    moveMention(direction) {
      const n = this.mention.results.length;
      if (!n) return;
      this.mention.activeIndex = (this.mention.activeIndex + direction + n) % n;
      this.$nextTick(() => {
        const list = this.$refs.mentionList;
        const active = list?.children?.[this.mention.activeIndex];
        active?.scrollIntoView?.({ block: 'nearest' });
      });
    },

    selectMention(item) {
      // Entries mention by title; assets/folders by precise "kind:container::path"
      // reference — the planner resolves those with list_assets.
      const mentionText = item.kind === 'asset' || item.kind === 'folder'
        ? `${item.kind}:${item.ref}`
        : item.title;

      // Register FIRST so the chip node renders (and later re-parses) as a chip.
      registerMention(mentionText);
      this.$refs.composer?.insertMention(mentionText);
      this.closeMention();
    },

    closeMention() {
      clearTimeout(this.mention._blurTimer);
      clearTimeout(this.mention._debounce);
      this.mention._reqId += 1; // invalidate any in-flight request
      this.mention.open = false;
      this.mention.loading = false;
      this.mention.results = [];
      this.mention.query = '';
      this.mention.activeIndex = 0;
    },

    /** Whether a plan turn ends with a question for the editor. */
    planAsksQuestion(turn) {
      return String(turn.text || '').includes(this.__('Question:'));
    },

    /**
     * The go-ahead button says what happens: the agent's own label for this
     * plan (e.g. "3 Einträge erstellen", "Ja, diese FAQ zuordnen"), else a
     * short fallback that fits whether the plan asks a question.
     */
    planApproveLabel(turn) {
      if (turn.approveLabel) return turn.approveLabel;
      return this.planAsksQuestion(turn) ? this.__('Yes, go ahead') : this.__('Go ahead');
    },

    approvePlan(turn = null) {
      // A restored chat may have an expired session — continueGeneration
      // falls back to the resume endpoint, so only a live turn blocks this.
      if (this.store.generating || this.store.planning || this.store.transcript.length === 0) return;
      // The agent's label is phrased as the editor's yes-answer, so it doubles
      // as the reply; without one, send the explicit approval.
      continueGeneration(turn?.approveLabel || this.__('Approved — proceed with the proposed plan.'));
      this.chatStickToBottom = true;
    },

    handleComposerSend() {
      if (!this.canGenerate || this.store.generating) return;

      this.closeMention();

      // An ongoing chat continues — even when restored from history with an
      // expired server session (continueGeneration reseeds it transparently).
      if (this.isReply) {
        continueGeneration(this.store.pendingPrompt);
        this.chatStickToBottom = true;
        return;
      }

      this.handleGenerate();
    },

    handleGenerate() {
      if (!this.canGenerate || this.store.generating) return;

      const prompt = (this.store.pendingPrompt || '').trim();

      const useAutoTarget = this.drawer && !this.selectedCollection;

      startGeneration({
        prompt,
        useAutoTarget,
        collection: this.selectedCollection,
        blueprint: this.selectedBlueprint,
      });
      this.chatStickToBottom = true;
    },

    cardStatusOf(id) {
      const card = this.store.entries.find((e) => e.id === id);
      return card ? card.status : STATUS.QUEUED;
    },

    handleCardSave(id, mode) { saveCard(id, mode); },
    handleSaveAll(mode) { saveAll(mode); },
    handleCardRetry(id) { retryCard(id); },
    handleCardDiscard(id) { discardCard(id); },

    requestStop() {
      // Drawer chat: stop just this turn, keeping history + prior cards.
      if (this.drawer) {
        stopChatTurn();
        this.stopConfirmOpen = false;
        return;
      }
      // Full-page wizard: no drafted entries yet → stop straight away.
      if (this.readyDraftedCount === 0) {
        stopGeneration({ keepReady: false });
        return;
      }
      this.stopConfirmOpen = true;
    },

    confirmStop(keepReady) {
      this.stopConfirmOpen = false;
      stopGeneration({ keepReady });
    },

    cancelStopConfirm() {
      this.stopConfirmOpen = false;
    },

    handleDrop(e) {
      this.dragOver = false;
      [...e.dataTransfer.files].forEach((file) => this.setFile(file));
    },

    handleFileSelect(e) {
      [...e.target.files].forEach((file) => this.setFile(file));
      e.target.value = '';
    },

    /**
     * Validate and register one attachment. In the drawer it becomes an
     * inline "@file:" chip in the composer; the wizard keeps its single
     * chipless attachment (replaced on every pick).
     */
    setFile(file) {
      const ext = file.name.split('.').pop().toLowerCase();
      if (!['pdf', 'txt'].includes(ext)) {
        Statamic.$toast.error(this.__('Only PDF and TXT files are supported.'));
        return;
      }
      if (file.size > 10 * 1024 * 1024) {
        Statamic.$toast.error(this.__('File is too large. Maximum 10 MB.'));
        return;
      }

      if (!this.drawer) {
        removePendingFile(null);
        addPendingFile(file, { withToken: false });
        return;
      }

      const token = addPendingFile(file);
      const current = this.store.pendingPrompt || '';
      const glue = current === '' || current.endsWith(' ') ? '' : ' ';
      this.store.pendingPrompt = `${current}${glue}@file:${token} `;
      this.$nextTick(() => this.$refs.composer?.focus());
    },

    removeFile() {
      removePendingFile(null);
      if (this.$refs.fileInput) this.$refs.fileInput.value = '';
    },

    formatFileSize,
  },
};

const TEMPLATE_ICONS = {
  'link': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>',
  'magnifying-glass': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>',
  'text-align-left': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M4 6h16M4 12h10M4 18h13"/></svg>',
  'image': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="1.6"/><path d="M21 15l-5-5L5 21"/></svg>',
  'sparkles': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/></svg>',
};
</script>

