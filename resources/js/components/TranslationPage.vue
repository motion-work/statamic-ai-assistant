<template>
  <div class="translation-page" :class="{ 'translation-page--busy': translationUiLocked }">
    <!-- Overwrite confirmation -->
    <div
      v-if="showOverwriteConfirm"
      class="translation-page__modal-backdrop"
      role="dialog"
      aria-modal="true"
      @click.self="showOverwriteConfirm = false"
    >
      <div class="translation-page__modal">
        <h3 class="translation-page__modal-title">{{ __('Overwrite confirmation title') }}</h3>
        <p class="translation-page__modal-text">{{ __('Overwrite confirmation body') }}</p>
        <ul class="translation-page__modal-list">
          <li>{{ __('Overwrite confirmation bullet 1') }}</li>
          <li>{{ __('Overwrite confirmation bullet 2') }}</li>
        </ul>
        <div class="translation-page__modal-actions">
          <Button
            variant="default"
            :disabled="bulkRequestInFlight"
            :text="__('Cancel')"
            @click="showOverwriteConfirm = false"
          />
          <Button
            variant="primary"
            :disabled="bulkRequestInFlight"
            :text="__('Yes, replace existing translations')"
            @click="confirmOverwriteAndStart"
          />
        </div>
      </div>
    </div>

    <!-- Hero -->
    <header class="translation-page__hero">
      <div class="translation-page__hero-icon" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10" />
          <path d="M2 12h20M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z" />
        </svg>
      </div>
      <div class="translation-page__hero-text">
        <p class="translation-page__eyebrow">{{ __('Bulk translation') }}</p>
        <h1 class="translation-page__title">{{ __('DeepL Translations') }}</h1>
        <p class="translation-page__subtitle">{{ __('Translate your content across languages using DeepL') }}</p>
      </div>
    </header>

    <!-- DeepL API usage (billing period) -->
    <div v-if="usageSectionVisible" class="translation-page__usage">
      <div v-if="usageLoading" class="translation-page__usage-card translation-page__usage-card--loading" aria-busy="true">
        <div class="translation-page__usage-loading-shimmer"></div>
        <p class="translation-page__usage-loading-text">{{ __('Loading usage…') }}</p>
      </div>
      <div v-else-if="deeplUsage && deeplUsage.error" class="translation-page__usage-card translation-page__usage-card--error">
        <div class="translation-page__usage-error-row">
          <span class="translation-page__usage-error-text">{{ __('Could not load DeepL usage.') }}</span>
          <Button variant="default" size="sm" :disabled="translationUiLocked" :text="__('Refresh')" @click="loadDeeplUsage" />
        </div>
        <p class="translation-page__usage-error-detail">{{ deeplUsage.error }}</p>
      </div>
      <div v-else-if="deeplUsage && deeplUsage.enabled && usageCharacter" class="translation-page__usage-card">
        <div class="translation-page__usage-head">
          <div class="translation-page__usage-head-main">
            <div class="translation-page__usage-title-row">
              <h3 class="translation-page__usage-title">{{ __('DeepL API usage') }}</h3>
              <p v-if="deeplUsage.billing && deeplUsage.billing.period_end" class="translation-page__usage-period">
                <span class="sr-only">{{ __('Billing period end') }}</span>
                <time
                  class="translation-page__usage-period-date"
                  :datetime="deeplUsage.billing.period_end"
                  >{{ formatBillingEnd(deeplUsage.billing.period_end) }}</time
                >
              </p>
            </div>
          </div>
          <Button
            variant="default"
            size="sm"
            class="translation-page__usage-refresh"
            :disabled="translationUiLocked"
            :text="__('Refresh')"
            @click="loadDeeplUsage"
          />
        </div>
        <div v-if="deeplUsage.any_limit_reached" class="translation-page__usage-alert" role="alert">
          {{ __('DeepL usage limit reached') }}
        </div>
        <div v-if="usageCharacter.unlimited" class="translation-page__usage-unlimited">
          <p class="translation-page__usage-chars-line">
            <strong>{{ formatUsageNumber(usageCharacter.count) }}</strong>
            {{ __('Characters period short') }}
            <span class="translation-page__usage-sep" aria-hidden="true">·</span>
            <span class="translation-page__usage-pill">{{ __('Unlimited plan') }}</span>
          </p>
        </div>
        <div v-else class="translation-page__usage-meter">
          <div class="translation-page__usage-row">
            <span class="translation-page__usage-label">{{ charactersUsageLabel }}</span>
            <span class="translation-page__usage-numbers"
              >{{ formatUsageNumber(usageCharacter.count) }} / {{ formatUsageNumber(usageCharacter.limit) }}</span
            >
          </div>
          <div
            class="translation-page__usage-track"
            role="progressbar"
            :aria-valuenow="usageCharacter.percent"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="charactersUsageLabel"
          >
            <div
              class="translation-page__usage-fill"
              :class="usageCharacterFillClass"
              :style="{ width: (usageCharacter.percent || 0) + '%' }"
            ></div>
          </div>
          <p class="translation-page__usage-pct">{{ usageCharacter.percent }}%</p>
        </div>
        <div v-if="usageCharacterAccount" class="translation-page__usage-account">
          <p class="translation-page__usage-account-line">
            <span class="translation-page__usage-label">{{ __('Whole account total') }}</span>
            <span class="translation-page__usage-numbers">
              <template v-if="usageCharacterAccount.unlimited">
                {{ formatUsageNumber(usageCharacterAccount.count) }}
              </template>
              <template v-else>
                {{ formatUsageNumber(usageCharacterAccount.count) }} / {{ formatUsageNumber(usageCharacterAccount.limit) }}
              </template>
            </span>
          </p>
        </div>
        <div v-if="usageDocument" class="translation-page__usage-doc">
          <template v-if="usageDocument.unlimited">
            <p class="translation-page__usage-doc-line">
              {{ __('Documents') }}: <strong>{{ formatUsageNumber(usageDocument.count) }}</strong>
              <span class="translation-page__usage-pill translation-page__usage-pill--inline">{{ __('Unlimited plan') }}</span>
            </p>
          </template>
          <template v-else>
            <div class="translation-page__usage-row">
              <span class="translation-page__usage-label">{{ __('Documents') }}</span>
              <span class="translation-page__usage-numbers"
                >{{ formatUsageNumber(usageDocument.count) }} / {{ formatUsageNumber(usageDocument.limit) }}</span
              >
            </div>
            <div
              class="translation-page__usage-track translation-page__usage-track--sub"
              role="progressbar"
              :aria-valuenow="usageDocument.percent"
              aria-valuemin="0"
              aria-valuemax="100"
            >
              <div
                class="translation-page__usage-fill translation-page__usage-fill--doc"
                :class="usageDocumentFillClass"
                :style="{ width: (usageDocument.percent || 0) + '%' }"
              ></div>
            </div>
          </template>
        </div>
        <div v-if="estimatedCostVisible" class="translation-page__usage-cost">
          <p class="translation-page__usage-cost-line">
            <span class="translation-page__usage-cost-label">{{ __('Est.') }}</span>
            <span class="translation-page__usage-cost-amount">{{
              formatEstimatedEur(deeplUsage.estimated_cost.estimated_total_eur)
            }}</span>
            <span class="translation-page__usage-cost-suffix">{{ __('per month') }}</span>
            <template v-if="costApproxWord">
              <span class="translation-page__usage-cost-dot" aria-hidden="true">·</span>
              <span class="translation-page__usage-cost-approx">{{ costApproxWord }}</span>
            </template>
          </p>
          <p class="translation-page__usage-cost-disclaimer" role="note">
            {{ __('DeepL cost estimate disclaimer short') }}
          </p>
        </div>
      </div>
      <div v-else-if="deeplUsage && deeplUsage.enabled && !usageCharacter" class="translation-page__usage-card translation-page__usage-card--muted">
        <p class="translation-page__usage-muted-text">{{ __('DeepL usage not available for account') }}</p>
        <Button variant="default" size="sm" :disabled="translationUiLocked" :text="__('Refresh')" @click="loadDeeplUsage" />
      </div>
    </div>

    <!-- Step indicators -->
    <nav
      class="translation-page__steps"
      :class="{ 'translation-page__steps--locked': translationUiLocked }"
      :aria-busy="translationUiLocked ? 'true' : 'false'"
      :aria-label="stepsAriaLabel"
    >
      <div class="translation-page__steps-track">
        <div
          v-for="(stepLabel, idx) in stepLabels"
          :key="idx"
          class="translation-page__step"
          :class="{
            'translation-page__step--active': step === idx + 1,
            'translation-page__step--done': step > idx + 1,
          }"
        >
          <span class="translation-page__step-number">
            <svg v-if="step > idx + 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="14" height="14">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
            <span v-else>{{ idx + 1 }}</span>
          </span>
          <span class="translation-page__step-label">{{ stepLabel }}</span>
        </div>
      </div>
    </nav>

    <!-- Step 1: Select content -->
    <div v-if="step === 1" class="translation-page__panel">
      <div class="translation-page__panel-head">
        <span class="translation-page__panel-badge">1</span>
        <h2 class="translation-page__panel-title">{{ step1PanelTitle }}</h2>
      </div>

      <!-- Collection vs navigation -->
      <div class="translation-page__field">
        <span class="translation-page__label">{{ __('Content source') }}</span>
        <div class="translation-page__segmented" role="group" :aria-label="__('Content source')">
          <button
            type="button"
            class="translation-page__segment"
            :class="{ 'translation-page__segment--active': contentSourceType === 'collection' }"
            :disabled="translationUiLocked"
            @click="setContentSourceType('collection')"
          >
            {{ __('Collection') }}
          </button>
          <button
            type="button"
            class="translation-page__segment"
            :class="{ 'translation-page__segment--active': contentSourceType === 'navigation' }"
            :disabled="translationUiLocked"
            @click="setContentSourceType('navigation')"
          >
            {{ __('Navigation') }}
          </button>
        </div>
      </div>

      <!-- Collection picker -->
      <div v-if="contentSourceType === 'collection'" class="translation-page__field">
        <label class="translation-page__label" for="translation-collection-select">{{ __('Collection') }}</label>
        <div class="translation-page__select-wrap">
          <select
            id="translation-collection-select"
            v-model="selectedCollection"
            class="translation-page__select"
            @change="loadCollectionEntries"
          >
            <option value="">{{ __('Choose a collection...') }}</option>
            <option v-for="col in collections" :key="col.handle" :value="col.handle">
              {{ col.title }}
            </option>
          </select>
        </div>
      </div>

      <!-- Navigation picker -->
      <div v-if="contentSourceType === 'navigation'" class="translation-page__field">
        <label class="translation-page__label" for="translation-navigation-select">{{ __('Navigation') }}</label>
        <div class="translation-page__select-wrap">
          <select
            id="translation-navigation-select"
            v-model="selectedNavigation"
            class="translation-page__select"
          >
            <option value="">{{ __('Choose a navigation...') }}</option>
            <option v-for="nav in navigations" :key="nav.handle" :value="nav.handle">
              {{ nav.title }}
            </option>
          </select>
        </div>
      </div>

      <!-- Navigation: structure sync preview (no entry checklist) -->
      <div v-if="contentSourceType === 'navigation' && navigationSyncPreview" class="translation-page__nav-sync">
        <p class="translation-page__help">
          {{ __('Navigation sync intro') }}
        </p>
        <p
          v-if="navigationSyncPreview.url_only_navigation && navigationSyncPreview.url_only_warning"
          class="translation-page__info-callout translation-page__info-callout--warn"
          role="status"
        >
          {{ navigationSyncPreview.url_only_warning }}
        </p>
        <div class="translation-page__nav-sync-meta">
          <p>
            <strong>{{ navPreviewTitle }}</strong>
            — {{ __('Branches in source tree') }}:
            {{ navigationSyncPreview.tree_branch_count != null ? navigationSyncPreview.tree_branch_count : '—' }}
          </p>
          <p class="translation-page__help translation-page__help--compact">
            {{ __('Source site') }}: {{ navPreviewSourceName }}
            ({{ navPreviewSourceLocale }})
          </p>
        </div>

        <div class="translation-page__nav-sync-config">
          <div class="translation-page__field">
            <label class="translation-page__label">{{ __('Source language') }}</label>
            <p class="translation-page__source-readonly">
              {{ defaultSourceSiteName }} <span class="translation-page__source-readonly-locale">({{ sourceLocale }})</span>
            </p>
            <p class="translation-page__help">{{ __('Navigation source site help') }}</p>
          </div>
          <div class="translation-page__field">
            <label class="translation-page__label">{{ __('Target languages') }}</label>
            <p class="translation-page__help">{{ __('Target languages help navigation merged') }}</p>
            <translation-target-language-list
              :sites="targetSiteOptions"
              :value="normalizedDestinationLocales"
              :details-by-locale="navigationLocaleRowDetails"
              @input="onDestinationLocalesInput"
            />
            <p v-if="normalizedDestinationLocales.length === 0" class="translation-page__error-inline translation-page__error-inline--soft">
              {{ __('Select at least one target language') }}
            </p>
          </div>
          <div class="translation-page__estimate">
            {{ estimateSummary }}
          </div>
        </div>
      </div>

      <!-- Entry listing with checkboxes -->
      <div v-if="contentSourceType === 'collection' && collectionEntries.length" class="translation-page__entries-list">
        <div class="translation-page__entries-toolbar">
          <label class="translation-page__checkbox-label">
            <input type="checkbox" v-model="selectAll" @change="toggleSelectAll" />
            <span>{{ __('Select all') }} ({{ collectionEntries.length }})</span>
          </label>
          <label class="translation-page__checkbox-label">
            <input type="checkbox" v-model="hideFullyTranslated" @change="applyFilter" />
            <span>{{ __('Hide fully translated pages') }}</span>
          </label>
        </div>
        <p v-if="hideFullyTranslated" class="translation-page__help translation-page__help--toolbar">
          {{ __('Hide fully translated help') }}
        </p>

        <div class="translation-page__entries-table">
          <div class="translation-page__entries-header">
            <span class="translation-page__col-check"></span>
            <span class="translation-page__col-title">{{ __('Title') }}</span>
            <span
              v-for="site in availableSites"
              :key="site.handle"
              class="translation-page__col-locale"
            >
              {{ site.name }}
            </span>
          </div>

          <div
            v-for="entry in filteredEntries"
            :key="entry.id"
            class="translation-page__entry-row"
          >
            <span class="translation-page__col-check">
              <input
                type="checkbox"
                :value="entry.id"
                v-model="selectedEntries"
                @change="syncSelectAllFromSelectedEntries"
              />
            </span>
            <span class="translation-page__col-title">
              <span
                class="translation-page__entry-title"
                role="button"
                tabindex="0"
                :aria-label="__('Toggle entry selection')"
                @click="toggleEntrySelection(entry)"
                @keydown.enter.prevent="toggleEntrySelection(entry)"
                @keydown.space.prevent="toggleEntrySelection(entry)"
              >{{ entry.title }}</span>
              <a
                :href="entry.edit_url"
                target="_blank"
                rel="noopener noreferrer"
                class="translation-page__entry-edit"
                :aria-label="__('Open entry in editor')"
                :title="__('Open entry in editor')"
              >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true">
                  <path d="M11 3a1 1 0 100 2h2.586L8.293 10.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z" />
                  <path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z" />
                </svg>
              </a>
            </span>
            <span
              v-for="site in availableSites"
              :key="site.handle"
              class="translation-page__col-locale"
            >
              <span
                class="translation-page__locale-badge"
                :class="'translation-page__locale-badge--' + (entry.locales[site.handle] || 'missing')"
                :title="entry.locales[site.handle] || 'missing'"
              >
                <svg v-if="entry.locales[site.handle] === 'translated'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="14" height="14">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="14" height="14">
                  <path fill-rule="evenodd" d="M3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd" />
                </svg>
              </span>
            </span>
          </div>
        </div>
      </div>

      <div
        v-else-if="contentSourceType === 'collection' && selectedCollection && !loadingEntries"
        class="translation-page__empty"
      >
        <span class="translation-page__empty-icon" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25">
            <path d="M9 12h6M12 9v6" />
            <rect x="3" y="3" width="18" height="18" rx="2" />
          </svg>
        </span>
        <p>{{ __('No entries found in this collection.') }}</p>
      </div>

      <div
        v-else-if="
          contentSourceType === 'navigation' && selectedNavigation && !loadingEntries && !navigationSyncPreview
        "
        class="translation-page__empty"
      >
        <span class="translation-page__empty-icon" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25">
            <path d="M9 12h6M12 9v6" />
            <rect x="3" y="3" width="18" height="18" rx="2" />
          </svg>
        </span>
        <p>{{ navigationPreviewError || __('Could not load navigation preview.') }}</p>
      </div>

      <div v-if="loadingEntries" class="translation-page__loading">
        <span class="translation-page__spinner"></span>
        <span>{{ loadingEntriesLabel }}</span>
      </div>

      <div class="translation-page__actions translation-page__actions--step1">
        <Button
          v-if="contentSourceType === 'collection'"
          variant="primary"
          :disabled="
            translationUiLocked ||
            selectedEntries.length === 0 ||
            !selectedCollection ||
            loadingEntries
          "
          @click="goToConfigure"
        >
          {{ __('Continue') }}
          ({{ selectedEntries.length }} {{ __('selected') }})
        </Button>
        <Button
          v-if="contentSourceType === 'navigation'"
          variant="primary"
          :disabled="!canStartTranslation || bulkRequestInFlight || translationUiLocked"
          :text="__('Start translation')"
          @click="onClickStartTranslation"
        />
      </div>
    </div>

    <!-- Step 2: Configure translation (collection only — navigation is merged into step 1) -->
    <div v-if="step === 2 && contentSourceType === 'collection'" class="translation-page__panel">
      <div class="translation-page__panel-head">
        <span class="translation-page__panel-badge">2</span>
        <h2 class="translation-page__panel-title">{{ __('Configure translation') }}</h2>
      </div>

      <div class="translation-page__config-grid">
        <div class="translation-page__field">
          <label class="translation-page__label">{{ __('Source language') }}</label>
          <p class="translation-page__source-readonly">
            {{ defaultSourceSiteName }} <span class="translation-page__source-readonly-locale">({{ sourceLocale }})</span>
          </p>
          <p v-if="contentSourceType === 'collection'" class="translation-page__help">{{ __('Translation source is the default site language.') }}</p>
          <p v-else class="translation-page__help">{{ __('Navigation source site help') }}</p>
        </div>
      </div>

      <div class="translation-page__field">
        <label class="translation-page__label">{{ __('Target languages') }}</label>
        <p v-if="contentSourceType === 'collection'" class="translation-page__help">{{ __('Target languages help') }}</p>
        <p v-else class="translation-page__help">{{ __('Target languages help navigation') }}</p>
        <translation-target-language-list
          :sites="targetSiteOptions"
          :value="normalizedDestinationLocales"
          :conflict-predicate="targetLocaleHasConflict"
          @input="onDestinationLocalesInput"
        />
        <p v-if="normalizedDestinationLocales.length === 0" class="translation-page__error-inline translation-page__error-inline--soft">
          {{ __('Select at least one target language') }}
        </p>
      </div>

      <div class="translation-page__field translation-page__field--card">
        <label class="translation-page__checkbox-label translation-page__checkbox-label--block">
          <input type="checkbox" v-model="overwrite" />
          <span>{{ __('Overwrite existing translations') }}</span>
        </label>
        <p v-if="overwrite" class="translation-page__warning">
          {{ __('Existing translations in the destination language will be replaced.') }}
        </p>
        <p v-if="!overwrite && wouldSkipExisting" class="translation-page__info-callout">
          {{ __('Skip existing callout') }}
        </p>
      </div>

      <div class="translation-page__estimate">
        {{ estimateSummary }}
      </div>

      <div v-if="hasConflictWithoutOverwrite" class="translation-page__error-inline">
        <p class="translation-page__conflict-text">{{ conflictMessage }}</p>
        <div v-if="conflictsByLocale.length" class="translation-page__conflict-detail">
          <p class="translation-page__conflict-detail-intro">{{ __('Conflict detail intro') }}</p>
          <ul class="translation-page__conflict-locales">
            <li v-for="block in conflictsByLocale" :key="block.locale" class="translation-page__conflict-locale-block">
              <span class="translation-page__conflict-locale-label">{{ block.localeLabel }}</span>
              <ul class="translation-page__conflict-pages">
                <li v-for="(title, idx) in block.entryTitles" :key="block.locale + '-' + idx">
                  {{ title }}
                </li>
              </ul>
            </li>
          </ul>
        </div>
      </div>

      <div class="translation-page__actions">
        <Button variant="default" :disabled="bulkRequestInFlight" :text="__('Back')" @click="step = 1" />
        <Button
          variant="primary"
          :disabled="!canStartTranslation || bulkRequestInFlight"
          :text="__('Start translation')"
          @click="onClickStartTranslation"
        />
      </div>
    </div>

    <!-- Progress & results: step 3 for collection, step 2 for navigation -->
    <div
      v-if="progressPanelVisible"
      class="translation-page__panel translation-page__panel--progress"
    >
      <div class="translation-page__panel-head">
        <span class="translation-page__panel-badge">{{ progressStepBadge }}</span>
        <h2 class="translation-page__panel-title">{{ __('Translation progress') }}</h2>
      </div>

      <p v-if="translationUiLocked" class="translation-page__stay-notice" role="status">
        {{ __('Do not leave page during translation') }}
      </p>

      <translation-progress
        :progress="batchProgress"
        :entries="batchEntries"
        :site-locales="availableSites"
        :indeterminate="progressIndeterminate"
      ></translation-progress>

      <p
        v-if="
          isTranslationDone &&
          contentSourceType === 'navigation' &&
          batchProgress.navigation_warnings &&
          batchProgress.navigation_warnings.length
        "
        class="translation-page__info-callout translation-page__info-callout--warn"
        role="status"
      >
        {{ batchProgress.navigation_warnings[0] }}
      </p>

      <div v-if="isTranslationDone" class="translation-page__actions">
        <Button variant="default" :text="__('Translate more')" @click="resetWizard" />
        <Button variant="primary" :text="viewSourceButtonLabel" @click="goToSourceView" />
      </div>
    </div>
  </div>
</template>

<script>
import axios from 'axios';
import { Button } from '@statamic/cms/ui';
import TranslationProgress from './TranslationProgress.vue';
import TranslationTargetLanguageList from './TranslationTargetLanguageList.vue';
import { normalizeDestinationLocales } from '../utils/normalizeDestinationLocales.js';

export default {
  components: {
    Button,
    TranslationProgress,
    TranslationTargetLanguageList,
  },

  data() {
    return {
      step: 1,
      collections: [],
      navigations: [],
      /** 'collection' | 'navigation' */
      contentSourceType: 'collection',
      selectedCollection: '',
      selectedNavigation: '',
      collectionEntries: [],
      availableSites: [],
      originSiteHandle: '',
      selectedEntries: [],
      selectAll: false,
      hideFullyTranslated: false,
      loadingEntries: false,

      defaultSourceLocale: '',
      defaultSourceSiteName: '',
      destinationLocales: [],
      overwrite: false,
      showOverwriteConfirm: false,

      batchId: null,
      batchProgress: {},
      batchEntries: {},
      pollingInterval: null,

      deeplUsage: null,
      usageLoading: true,

      /** True while POST /bulk is in flight (guards double-submit). */
      bulkRequestInFlight: false,

      /** Preview payload for navigation sync (structure copy to locales). */
      navigationSyncPreview: null,
      /** Last API error when navigation preview fails (shown inline). */
      navigationPreviewError: null,
    };
  },

  computed: {
    usageSectionVisible() {
      if (this.deeplUsage && this.deeplUsage.enabled === false) {
        return false;
      }
      return true;
    },

    usageCharacter() {
      return this.deeplUsage && this.deeplUsage.character ? this.deeplUsage.character : null;
    },

    usageCharacterAccount() {
      return this.deeplUsage && this.deeplUsage.character_account ? this.deeplUsage.character_account : null;
    },

    charactersUsageLabel() {
      if (typeof this.__ !== "function") {
        return "Characters";
      }
      return this.deeplUsage && this.deeplUsage.character_is_api_key_scoped
        ? this.__("Characters (this API key)")
        : this.__("Characters");
    },

    estimatedCostVisible() {
      const e = this.deeplUsage && this.deeplUsage.estimated_cost;
      return !!(e && e.enabled && typeof e.estimated_total_eur === "number");
    },

    /** Optional word after the cost (e.g. “approx.”); omitted when the locale uses an empty string. */
    costApproxWord() {
      if (typeof this.__ !== "function") {
        return "approx.";
      }
      const s = this.__("approx.");
      return s && String(s).trim() ? s : "";
    },

    usageDocument() {
      return this.deeplUsage && this.deeplUsage.document ? this.deeplUsage.document : null;
    },

    usageCharacterFillClass() {
      const p = this.usageCharacter && this.usageCharacter.percent;
      if (p == null) {
        return '';
      }
      if (p >= 98) {
        return 'translation-page__usage-fill--critical';
      }
      if (p >= 85) {
        return 'translation-page__usage-fill--warning';
      }
      return '';
    },

    usageDocumentFillClass() {
      const p = this.usageDocument && this.usageDocument.percent;
      if (p == null) {
        return '';
      }
      if (p >= 98) {
        return 'translation-page__usage-fill--critical';
      }
      if (p >= 85) {
        return 'translation-page__usage-fill--warning';
      }
      return '';
    },

    stepsAriaLabel() {
      return typeof this.__ === 'function' ? this.__('Steps') : 'Steps';
    },

    step1PanelTitle() {
      if (this.contentSourceType === 'navigation') {
        return this.__('Prepare navigation sync');
      }
      return this.__('Select content to translate');
    },

    /** Per-target-locale rows for navigation sync (shown next to checkboxes). */
    navigationLocaleRowDetails() {
      const preview = this.navigationSyncPreview;
      if (!preview || !preview.per_destination || !this.availableSites.length) {
        return {};
      }
      const src = this.sourceLocale;
      const out = {};
      for (const block of preview.per_destination) {
        const site = this.availableSites.find((s) => s.handle === block.site_handle);
        if (!site || site.locale === src) {
          continue;
        }
        if (!block.missing_entries || block.missing_entries.length === 0) {
          out[site.locale] = { text: this.__('No pages missing'), tone: 'ok' };
        } else {
          out[site.locale] = {
            text: `${this.__('Pages translated before sync', {
              count: block.missing_entries.length,
            })}: ${this.missingEntryTitlesJoin(block)}`,
            tone: 'warn',
          };
        }
      }
      return out;
    },

    progressPanelVisible() {
      if (this.contentSourceType === 'navigation') {
        return this.step === 2;
      }
      return this.step === 3;
    },

    progressStepBadge() {
      return this.contentSourceType === 'navigation' ? 2 : 3;
    },

    isProgressStep() {
      if (this.contentSourceType === 'navigation') {
        return this.step === 2;
      }
      return this.step === 3;
    },

    sourceLocale() {
      return this.defaultSourceLocale;
    },

    navPreviewTitle() {
      const n = this.navigationSyncPreview && this.navigationSyncPreview.navigation;
      return (n && n.title) || this.selectedNavigation || '';
    },

    navPreviewSourceName() {
      const s = this.navigationSyncPreview && this.navigationSyncPreview.source_site;
      return (s && s.name) || '';
    },

    navPreviewSourceLocale() {
      const s = this.navigationSyncPreview && this.navigationSyncPreview.source_site;
      return (s && s.locale) || '';
    },

    loadingEntriesLabel() {
      return this.contentSourceType === 'navigation'
        ? this.__('Loading navigation preview…')
        : this.__('Loading entries...');
    },

    viewSourceButtonLabel() {
      if (this.contentSourceType === 'navigation') {
        return this.__('View navigation');
      }
      return this.__('View entries');
    },

    targetSiteOptions() {
      return (this.availableSites || []).filter((s) => s.locale !== this.sourceLocale);
    },

    normalizedDestinationLocales() {
      return normalizeDestinationLocales(this.destinationLocales);
    },

    filteredEntries() {
      if (!this.hideFullyTranslated) {
        return this.collectionEntries;
      }
      const origin = this.originSiteHandle || (this.availableSites[0] && this.availableSites[0].handle);
      if (!origin) {
        return this.collectionEntries;
      }
      return this.collectionEntries.filter((entry) => {
        return !this.availableSites.every((site) => {
          if (site.handle === origin) {
            return true;
          }
          return entry.locales[site.handle] === 'translated';
        });
      });
    },

    totalOperations() {
      const m = this.normalizedDestinationLocales.length;
      if (m === 0) {
        return 0;
      }
      if (this.contentSourceType === 'navigation') {
        return m;
      }
      return this.selectedEntries.length * m;
    },

    estimateSummary() {
      if (this.contentSourceType === 'navigation') {
        const c = this.normalizedDestinationLocales.length;
        if (!this.selectedNavigation || !c) {
          return this.__('Choose a navigation and target languages to sync.');
        }
        return this.__('Will sync the navigation structure to :count language(s), translating missing pages first.', {
          count: c,
        });
      }
      const n = this.selectedEntries.length;
      const langs = this.destinationLanguageNames;
      if (!n || !this.normalizedDestinationLocales.length) {
        return this.__('Choose a source, entries, and target languages to see the estimate.');
      }
      return this.__('Will translate :entries entries into :langs (:ops operations) using DeepL.', {
        entries: n,
        langs: langs,
        ops: this.totalOperations,
      });
    },

    destinationLanguageNames() {
      const names = [];
      this.normalizedDestinationLocales.forEach((loc) => {
        const site = this.availableSites.find((s) => s.locale === loc);
        names.push(site ? site.name : loc);
      });
      return names.join(', ');
    },

    wouldSkipExisting() {
      if (this.contentSourceType === 'navigation') {
        return false;
      }
      return this.selectedEntries.some((id) => {
        const entry = this.collectionEntries.find((e) => e.id === id);
        if (!entry) return false;
        return this.normalizedDestinationLocales.some((loc) => {
          const site = this.availableSites.find((s) => s.locale === loc);
          if (!site) return false;
          return entry.locales[site.handle] === 'translated';
        });
      });
    },

    conflictingDestinationLocales() {
      if (this.contentSourceType !== 'collection') {
        return [];
      }
      if (this.overwrite) {
        return [];
      }
      const locales = [];
      this.normalizedDestinationLocales.forEach((loc) => {
        const site = this.availableSites.find((s) => s.locale === loc);
        if (!site) {
          return;
        }
        const hasAny = this.selectedEntries.some((id) => {
          const entry = this.collectionEntries.find((e) => e.id === id);
          return entry && entry.locales[site.handle] === 'translated';
        });
        if (hasAny) {
          locales.push(loc);
        }
      });
      return locales;
    },

    conflictsByLocale() {
      if (this.overwrite) {
        return [];
      }
      const rows = [];
      this.conflictingDestinationLocales.forEach((loc) => {
        const site = this.availableSites.find((s) => s.locale === loc);
        if (!site) {
          return;
        }
        const titles = [];
        this.selectedEntries.forEach((id) => {
          const entry = this.collectionEntries.find((e) => e.id === id);
          if (entry && entry.locales[site.handle] === 'translated') {
            titles.push(entry.title);
          }
        });
        if (!titles.length) {
          return;
        }
        const unique = [...new Set(titles)].sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }));
        rows.push({
          locale: loc,
          localeLabel: this.localeLabelForSite(site, loc),
          entryTitles: unique,
        });
      });
      return rows;
    },

    hasConflictWithoutOverwrite() {
      if (this.contentSourceType !== 'collection') {
        return false;
      }
      return this.conflictingDestinationLocales.length > 0;
    },

    conflictMessage() {
      return this.__('Conflict without overwrite message');
    },

    canStartTranslation() {
      if (!this.sourceLocale || this.normalizedDestinationLocales.length === 0) {
        return false;
      }
      if (this.normalizedDestinationLocales.some((l) => l === this.sourceLocale)) {
        return false;
      }
      if (this.contentSourceType === 'navigation') {
        return !!(this.selectedNavigation && this.navigationSyncPreview);
      }
      if (this.selectedEntries.length === 0) {
        return false;
      }
      if (this.hasConflictWithoutOverwrite) {
        return false;
      }
      return true;
    },

    isTranslationDone() {
      return (
        this.batchProgress &&
        (this.batchProgress.status === 'completed' || this.batchProgress.status === 'failed')
      );
    },

    /** Progress step: disable unrelated UI while work is running. */
    translationUiLocked() {
      if (!this.isProgressStep) {
        return false;
      }
      const s = this.batchProgress && this.batchProgress.status;
      if (!s) {
        return true;
      }
      return s !== 'completed' && s !== 'failed';
    },

    progressIndeterminate() {
      return false;
    },

    stepLabels() {
      if (this.contentSourceType === 'navigation') {
        return [this.__('Prepare navigation sync'), this.__('Progress')];
      }
      return [this.__('Select content'), this.__('Configure'), this.__('Progress')];
    },
  },

  watch: {
    'batchProgress.status'(v) {
      if (v === 'completed' || v === 'failed') {
        this.loadDeeplUsage();
      }
    },
    selectedNavigation(val) {
      if (this.contentSourceType !== 'navigation') {
        return;
      }
      if (val) {
        this.loadNavigationEntries();
      } else {
        this.navigationSyncPreview = null;
        this.navigationPreviewError = null;
      }
    },
  },

  mounted() {
    this.loadStatus();
    this.loadDeeplUsage();
  },

  beforeUnmount() {
    this.stopPolling();
  },

  methods: {
    formatUsageNumber(n) {
      if (n == null || Number.isNaN(Number(n))) {
        return '—';
      }
      try {
        return new Intl.NumberFormat(undefined).format(Number(n));
      } catch (e) {
        return String(n);
      }
    },

    formatEstimatedEur(n) {
      if (n == null || Number.isNaN(Number(n))) {
        return "—";
      }
      try {
        return new Intl.NumberFormat(undefined, {
          style: "currency",
          currency: "EUR",
        }).format(Number(n));
      } catch (e) {
        return String(n);
      }
    },

    missingEntryTitlesJoin(block) {
      if (!block || !block.missing_entries || !block.missing_entries.length) {
        return '';
      }
      return block.missing_entries.map((e) => e.title).join(', ');
    },

    formatBillingEnd(iso) {
      if (!iso || typeof iso !== 'string') {
        return '';
      }
      try {
        const d = new Date(iso);
        if (Number.isNaN(d.getTime())) {
          return iso;
        }
        return new Intl.DateTimeFormat(undefined, {
          dateStyle: 'long',
          timeStyle: 'short',
        }).format(d);
      } catch (e) {
        return iso;
      }
    },

    async loadDeeplUsage() {
      this.usageLoading = true;
      try {
        const { data } = await axios.get('/cp/ai-translations/deepl-usage');
        this.deeplUsage = data;
      } catch (e) {
        this.deeplUsage = {
          enabled: true,
          error: e.response?.data?.message || e.message || this.__('Something went wrong.'),
        };
      } finally {
        this.usageLoading = false;
      }
    },

    targetLocaleHasConflict(site) {
      if (this.contentSourceType !== 'collection') {
        return false;
      }
      if (this.overwrite || !site) {
        return false;
      }
      if (!this.normalizedDestinationLocales.includes(site.locale)) {
        return false;
      }
      return this.selectedEntries.some((id) => {
        const entry = this.collectionEntries.find((e) => e.id === id);
        return entry && entry.locales[site.handle] === 'translated';
      });
    },

    localeLabelForSite(site, loc) {
      const raw = site && site.name ? String(site.name) : '';
      const name = raw.replace(/^[\u{1F1E6}-\u{1F1FF}]{2}\s*/u, '').trim();
      return name ? `${name} (${loc})` : loc;
    },

    goToConfigure() {
      this.step = 2;
    },

    onDestinationLocalesInput(v) {
      this.destinationLocales = normalizeDestinationLocales(v);
    },

    onClickStartTranslation() {
      if (this.contentSourceType === 'collection' && this.overwrite) {
        this.showOverwriteConfirm = true;
        return;
      }
      this.runTranslation();
    },

    confirmOverwriteAndStart() {
      this.showOverwriteConfirm = false;
      this.runTranslation();
    },

    async loadStatus() {
      try {
        const response = await axios.get('/cp/ai-translations/status');
        this.collections = response.data.collections || [];
        this.navigations = response.data.navigations || [];
      } catch (error) {
        this.$toast.error(this.__('Failed to load collections.'));
      }
    },

    async loadContentEntries() {
      if (this.contentSourceType === 'navigation') {
        await this.loadNavigationEntries();
      } else {
        await this.loadCollectionEntries();
      }
    },

    setContentSourceType(type) {
      if (this.contentSourceType === type) {
        return;
      }
      this.step = 1;
      this.contentSourceType = type;
      this.selectedCollection = '';
      this.selectedNavigation = '';
      this.collectionEntries = [];
      this.availableSites = [];
      this.originSiteHandle = '';
      this.defaultSourceLocale = '';
      this.defaultSourceSiteName = '';
      this.selectedEntries = [];
      this.selectAll = false;
      this.navigationSyncPreview = null;
      this.navigationPreviewError = null;
      this.destinationLocales = [];
    },

    async loadCollectionEntries() {
      if (!this.selectedCollection) {
        this.collectionEntries = [];
        this.availableSites = [];
        this.originSiteHandle = '';
        this.defaultSourceLocale = '';
        this.defaultSourceSiteName = '';
        return;
      }

      this.loadingEntries = true;
      this.selectedEntries = [];
      this.selectAll = false;

      try {
        const response = await axios.get('/cp/ai-translations/collection-entries', {
          params: { collection: this.selectedCollection },
        });

        this.collectionEntries = response.data.entries || [];
        this.availableSites = response.data.sites || [];
        this.originSiteHandle = response.data.origin_site_handle || '';
        this.defaultSourceLocale = response.data.default_source_locale || '';
        this.defaultSourceSiteName = response.data.default_source_site_name || '';

        this.destinationLocales = [];
      } catch (error) {
        const msg =
          error.response?.data?.error ||
          error.response?.data?.message ||
          this.__('Failed to load entries.');
        this.$toast.error(msg);
        this.collectionEntries = [];
        this.availableSites = [];
      } finally {
        this.loadingEntries = false;
      }
    },

    async loadNavigationEntries() {
      if (!this.selectedNavigation) {
        this.collectionEntries = [];
        this.availableSites = [];
        this.originSiteHandle = '';
        this.defaultSourceLocale = '';
        this.defaultSourceSiteName = '';
        this.navigationSyncPreview = null;
        this.navigationPreviewError = null;
        return;
      }

      this.loadingEntries = true;
      this.selectedEntries = [];
      this.selectAll = false;
      this.navigationSyncPreview = null;
      this.navigationPreviewError = null;

      try {
        const response = await axios.get('/cp/ai-translations/navigation-entries', {
          params: { navigation: this.selectedNavigation },
        });

        const data = response.data;
        if (!data || typeof data !== 'object' || !data.navigation || !data.source_site) {
          throw new Error(this.__('Invalid response from server.'));
        }

        this.navigationSyncPreview = data;
        this.navigationPreviewError = null;
        this.collectionEntries = [];
        this.availableSites = data.sites || [];
        this.originSiteHandle = data.origin_site_handle || '';
        this.defaultSourceLocale = data.default_source_locale || '';
        this.defaultSourceSiteName = data.default_source_site_name || '';

        this.destinationLocales = [];
      } catch (error) {
        const msg =
          error.response?.data?.error ||
          error.response?.data?.message ||
          error.message ||
          this.__('Failed to load entries.');
        this.navigationPreviewError = msg;
        this.$toast.error(msg);
        this.collectionEntries = [];
        this.availableSites = [];
        this.navigationSyncPreview = null;
      } finally {
        this.loadingEntries = false;
      }
    },

    toggleSelectAll() {
      if (this.selectAll) {
        this.selectedEntries = this.filteredEntries.map((e) => e.id);
      } else {
        this.selectedEntries = [];
      }
    },

    syncSelectAllFromSelectedEntries() {
      const ids = this.filteredEntries.map((e) => e.id);
      this.selectAll =
        ids.length > 0 && ids.every((rowId) => this.selectedEntries.includes(rowId));
    },

    toggleEntrySelection(entry) {
      const id = entry.id;
      const next = new Set(this.selectedEntries);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      this.selectedEntries = Array.from(next);
      this.syncSelectAllFromSelectedEntries();
    },

    applyFilter() {
      this.selectedEntries = [];
      this.selectAll = false;
    },

    buildBatchEntriesFromResults(results) {
      const entries = {};
      (results || []).forEach((r) => {
        const sid = r.source_entry_id || r.entry_id;
        const dl = r.destination_locale || '';
        const key = `${sid}\x1e${dl}`;
        entries[key] = {
          status: r.success === false ? 'failed' : 'completed',
          error: r.error,
          origin_title: r.origin_title,
          target_title: r.target_title,
          edit_url: r.edit_url,
          destination_locale: r.destination_locale,
          linked_entries: r.linked_entries || [],
        };
      });
      return entries;
    },

    entryLabelForProgress(entryId, locale) {
      const entry = this.collectionEntries.find((e) => e.id === entryId);
      const title = entry ? entry.title : entryId;
      const site = this.availableSites.find((s) => s.locale === locale);
      const langName = site ? site.name : locale;
      return `${title} → ${langName}`;
    },

    async runNavigationSync() {
      if (this.bulkRequestInFlight) {
        return;
      }
      this.bulkRequestInFlight = true;
      this.step = 2;
      this.batchEntries = {};
      this.batchProgress = {
        current: 0,
        total: 1,
        status: 'processing',
        current_entry: this.__('Syncing navigation…'),
      };

      try {
        const { data } = await axios.post('/cp/ai-translations/navigation-sync', {
          navigation: this.selectedNavigation,
          destination_locales: this.normalizedDestinationLocales,
        });

        const results = data.results || [];
        const errs = results
          .filter((r) => !r.success)
          .map((r) => r.error)
          .filter(Boolean);
        const ok = results.filter((r) => r.success);
        const navWarnings = [];
        results.forEach((r) => {
          (r.warnings || []).forEach((w) => {
            if (w && !navWarnings.includes(w)) {
              navWarnings.push(w);
            }
          });
        });

        const batchEntries = {};
        results.forEach((r) => {
          const key = `nav:${r.locale}`;
          batchEntries[key] = {
            status: r.success ? 'completed' : 'failed',
            error: r.error || null,
            origin_title: this.__('Navigation structure'),
            target_title: r.message || r.site_handle || r.locale,
            destination_locale: r.locale,
          };
        });
        this.batchEntries = batchEntries;

        this.batchProgress = {
          current: results.length,
          total: Math.max(results.length, 1),
          status: 'completed',
          translated: ok.length,
          updated: 0,
          errors: errs,
          navigation_warnings: navWarnings,
        };
      } catch (error) {
        const msg =
          error.response?.data?.error ||
          error.response?.data?.message ||
          error.message ||
          this.__('Translation failed.');
        this.batchProgress = {
          status: 'failed',
          errors: [msg],
          fatal_error: msg,
        };
      } finally {
        this.bulkRequestInFlight = false;
      }
    },

    async runTranslation() {
      if (this.bulkRequestInFlight) {
        return;
      }
      if (this.contentSourceType === 'navigation') {
        await this.runNavigationSync();
        return;
      }
      this.bulkRequestInFlight = true;
      this.step = 3;

      const pairs = [];
      for (const entryId of this.selectedEntries) {
        for (const locale of this.normalizedDestinationLocales) {
          pairs.push({ entryId, locale });
        }
      }

      const total = pairs.length;
      this.batchProgress = { current: 0, total, status: 'processing' };
      this.batchEntries = {};

      let translated = 0;
      let updated = 0;
      let linkedCreatedTotal = 0;
      const errors = [];

      for (let i = 0; i < pairs.length; i++) {
        const { entryId, locale } = pairs[i];

        this.batchProgress = {
          current: i,
          total,
          status: 'processing',
          current_entry: this.entryLabelForProgress(entryId, locale),
        };

        try {
          const { data } = await axios.post('/cp/ai-translations/entry', {
            entry_id: entryId,
            destination_locale: locale,
            overwrite: this.overwrite,
          });

          const sid = data.source_entry_id || entryId;
          const key = `${sid}\x1e${locale}`;
          const status = data.success === false ? 'failed' : 'completed';

          this.batchEntries = {
            ...this.batchEntries,
            [key]: {
              status,
              error: data.error,
              origin_title: data.origin_title,
              target_title: data.target_title,
              edit_url: data.edit_url,
              destination_locale: locale,
              linked_entries: data.linked_entries || [],
            },
          };

          if (!data.success) {
            errors.push(data.error);
          } else {
            linkedCreatedTotal += (data.linked_entries || []).length;
            if (data.skipped || data.is_new) {
              translated++;
            } else {
              updated++;
            }
          }
        } catch (error) {
          const msg =
            error.response?.data?.message ||
            error.response?.data?.error ||
            error.message ||
            this.__('Translation failed.');
          errors.push(msg);
          const key = `${entryId}\x1e${locale}`;
          this.batchEntries = {
            ...this.batchEntries,
            [key]: { status: 'failed', error: msg, destination_locale: locale },
          };
        }

        this.batchProgress = {
          current: i + 1,
          total,
          status: 'processing',
          current_entry: this.entryLabelForProgress(entryId, locale),
        };
      }

      this.batchProgress = {
        current: total,
        total,
        status: 'completed',
        translated,
        updated,
        errors,
        linked_created_total: linkedCreatedTotal,
      };
      this.bulkRequestInFlight = false;
    },

    startPolling() {
      this.pollingInterval = setInterval(async () => {
        try {
          const response = await axios.get(`/cp/ai-translations/progress/${this.batchId}`);
          this.batchProgress = response.data;
          this.batchEntries = response.data.entries || {};

          if (response.data.status === 'completed' || response.data.status === 'failed') {
            this.stopPolling();
          }
        } catch (error) {
          // Keep polling on transient errors
        }
      }, 2000);
    },

    stopPolling() {
      if (this.pollingInterval) {
        clearInterval(this.pollingInterval);
        this.pollingInterval = null;
      }
    },

    resetWizard() {
      this.step = 1;
      this.selectedEntries = [];
      this.selectAll = false;
      this.batchId = null;
      this.batchProgress = {};
      this.batchEntries = {};
      this.showOverwriteConfirm = false;
      this.bulkRequestInFlight = false;
      this.navigationSyncPreview = null;
      this.navigationPreviewError = null;
      this.destinationLocales = [];
      this.loadContentEntries();
    },

    goToSourceView() {
      if (this.contentSourceType === 'navigation' && this.selectedNavigation) {
        window.location.href = `/cp/navigation/${this.selectedNavigation}`;
        return;
      }
      if (this.selectedCollection) {
        window.location.href = `/cp/collections/${this.selectedCollection}`;
      }
    },
  },
};
</script>
