/**
 * TranslationInfoDisplay
 * 
 * Handles the UI display of source -> destination language information
 * and validation in the Statamic action modal for entry translation.
 */
export class TranslationInfoDisplay {
  constructor() {
    this.translationInfoElement = null;
    this.observer = null;
    this.setupComplete = false;
    this.isUpdating = false;
  }

  /**
   * Get the current source language from Statamic store or page context
   * @returns {string}
   */
  getSourceLanguage() {
    try {
      if (window.Statamic?.$store) {
        const currentSite = window.Statamic.$store.state?.sites?.selected;
        if (currentSite) {
          return `${currentSite.name} (${currentSite.locale})`;
        }
      }
    } catch (e) {
      // Ignore
    }
    
    const siteIndicator = document.querySelector('[data-site-handle], .site-selector');
    if (siteIndicator) {
      const siteName = siteIndicator.textContent?.trim() || siteIndicator.getAttribute('data-site-handle');
      if (siteName) return siteName;
    }
    
    return 'Source';
  }

  /**
   * Create the translation info display element
   * @param {HTMLElement} container - Container element to insert after
   * @returns {HTMLElement|null}
   */
  createTranslationInfo(container) {
    if (this.translationInfoElement) {
      return this.translationInfoElement;
    }

    const sourceLangText = this.getSourceLanguage();
    const infoDiv = document.createElement('div');
    infoDiv.id = 'translation-info';
    infoDiv.className = 'mt-2 text-sm text-gray-600';
    infoDiv.style.display = 'none';
    infoDiv.innerHTML = `
      <span id="source-lang">${sourceLangText}</span>
      <span class="mx-2">→</span>
      <span id="target-lang">-</span>
    `;
    
    if (container?.parentNode) {
      container.parentNode.insertBefore(infoDiv, container.nextSibling);
      this.translationInfoElement = infoDiv;
      return infoDiv;
    }
    
    return null;
  }

  /**
   * Get selected language text from Vue Select
   * @param {string} fieldId - The field ID
   * @returns {string|null}
   */
  getSelectedLanguage(fieldId) {
    const input = document.getElementById(fieldId);
    if (!input) return null;
    
    const selectedSpan = input.closest('.v-select')?.querySelector('.vs__selected');
    return selectedSpan?.textContent.trim() || null;
  }

  /**
   * Update the translation info display with current selections
   */
  updateTranslationInfo() {
    if (this.isUpdating) return;
    this.isUpdating = true;

    try {
      const sourceInput = document.getElementById('field_source_language');
      const destinationInput = document.getElementById('field_destination_language');
      
      if (!sourceInput || !destinationInput) {
        return;
      }

      // Create info element if it doesn't exist
      let translationInfo = document.getElementById('translation-info');
      if (!translationInfo) {
        const destinationField = document.querySelector('.publish-field__destination_language')?.closest('.publish-field');
        if (destinationField) {
          translationInfo = this.createTranslationInfo(destinationField);
        }
      }
      
      const sourceLangSpan = document.getElementById('source-lang');
      const targetLangSpan = document.getElementById('target-lang');
      
      if (!sourceLangSpan || !targetLangSpan || !translationInfo) {
        return;
      }

      const sourceLangText = this.getSelectedLanguage('field_source_language');
      const destinationLangText = this.getSelectedLanguage('field_destination_language');
      
      this.manageSameTranslationDestination(sourceLangText, destinationLangText);
      
      if (sourceLangText && destinationLangText) {
        sourceLangSpan.textContent = sourceLangText;
        targetLangSpan.textContent = destinationLangText;
        translationInfo.style.display = '';
      } else {
        translationInfo.style.display = 'none';
      }
    } finally {
      setTimeout(() => {
        this.isUpdating = false;
      }, 100);
    }
  }

  /**
   * Manage same language validation: disable submit button and show error if languages match
   * @param {string} srcLanguage - Source language text
   * @param {string} destLanguage - Destination language text
   */
  manageSameTranslationDestination(srcLanguage, destLanguage) {
    const isSameLanguage = srcLanguage === destLanguage;

    if (isSameLanguage) {
      this.updateSubmitButtonState(false);
      this.showErrorMessage();
    } else {
      this.updateSubmitButtonState(true);
      this.hideErrorMessage();
    }
  }

  /**
   * Update submit button state (enable/disable)
   * @param {boolean} enabled - Whether to enable the button
   */
  updateSubmitButtonState(enabled) {
    const modal = document.querySelector('[data-modal="action"], .modal[data-modal], [role="dialog"]');
    if (!modal) return;

    const submitButton = modal.querySelector('button[type="submit"]') || 
                         modal.querySelector('.btn-primary[type="button"]') ||
                         modal.querySelector('button.btn-primary');
    
    if (submitButton) {
      submitButton.disabled = !enabled;
      if (enabled) {
        submitButton.classList.remove('opacity-50', 'cursor-not-allowed');
      } else {
        submitButton.classList.add('opacity-50', 'cursor-not-allowed');
      }
    }
  }

  /**
   * Show error message about same language at the bottom of the modal
   */
  showErrorMessage() {
    const confirmationModal = document.querySelector('.confirmation-modal');
    if (!confirmationModal) return;

    let errorDiv = document.getElementById('translation-same-language-error');
    
    if (!errorDiv) {
      errorDiv = document.createElement('div');
      errorDiv.id = 'translation-same-language-error';
      errorDiv.style.cssText = 'padding: 0.75rem; font-size: 0.875rem; font-weight: 500; color: #dc2626; background-color: #fef2f2; border: 1px solid #fecaca;';
      errorDiv.textContent = __('Impossible to translate in same language');
      
      const divs = confirmationModal.querySelectorAll('div');
      if (divs.length > 0) {
        const lastDiv = divs[divs.length - 1];
        lastDiv.parentNode.insertBefore(errorDiv, lastDiv);
      } else {
        confirmationModal.appendChild(errorDiv);
      }
    } else {
      errorDiv.style.display = '';
    }
  }

  /**
   * Hide error message
   */
  hideErrorMessage() {
    const errorDiv = document.getElementById('translation-same-language-error');
    if (errorDiv) {
      errorDiv.style.display = 'none';
    }
  }

  /**
   * Set up event listeners for language select elements
   * @param {string} fieldId - The field ID
   * @param {Function} onChangeCallback - Callback function when selection changes
   */
  setupSelectListeners(fieldId, onChangeCallback) {
    const input = document.getElementById(fieldId);
    if (!input) return;

    // Prevent duplicate listeners
    if (input.dataset.listenerAdded) return;
    input.dataset.listenerAdded = 'true';

    input.addEventListener('blur', onChangeCallback);
  }

  /**
   * Initialize the translation info display watcher
   */
  init() {
    if (this.setupComplete) return;

    const handleChange = () => {
      setTimeout(() => this.updateTranslationInfo(), 100);
    };

    let setupDone = false;
    this.observer = new MutationObserver(() => {
      const sourceInput = document.getElementById('field_source_language');
      const destinationInput = document.getElementById('field_destination_language');
      
      if (!sourceInput || !destinationInput) {
        this.translationInfoElement = null;
        setupDone = false;
        return;
      }

      if (setupDone) return;
      setupDone = true;

      // Create info element if needed
      if (!this.translationInfoElement) {
        const destinationField = document.querySelector('.publish-field__destination_language')?.closest('.publish-field');
        if (destinationField) {
          this.createTranslationInfo(destinationField);
        }
      }

      // Set up event listeners for both selects
      this.setupSelectListeners('field_source_language', handleChange);
      this.setupSelectListeners('field_destination_language', handleChange);

      // Initial update
      setTimeout(() => this.updateTranslationInfo(), 200);
    });

    this.observer.observe(document.body, {
      childList: true,
      subtree: true
    });

    // Initial attempt after a delay
    setTimeout(() => {
      this.translationInfoElement = null;
      this.updateTranslationInfo();
    }, 500);

    this.setupComplete = true;
  }

  /**
   * Clean up observers and event listeners
   */
  destroy() {
    if (this.observer) {
      this.observer.disconnect();
      this.observer = null;
    }
    this.translationInfoElement = null;
    this.setupComplete = false;
  }
}
