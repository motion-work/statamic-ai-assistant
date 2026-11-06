/**
 * Moving the Control Panel page behind the full-screen AI agent.
 *
 * Statamic 6 runs the CP on Inertia, so `router.visit()` swaps the page without
 * a reload: the agent overlay and its chat stay as they are. A full navigation
 * is the fallback; the agent reattaches to a running chat after a page load.
 */
import { router } from '@statamic/cms/inertia';
import { dirty } from '@statamic/cms/api';

/** Whether a CP form on the current page has unsaved changes. */
export function hasUnsavedChanges() {
  try {
    return (dirty?.count?.() ?? 0) > 0;
  } catch {
    return false;
  }
}

/** Open a CP URL in the page behind the agent. */
export function visitCp(url) {
  if (!url) return;
  try {
    if (router && typeof router.visit === 'function') {
      router.visit(url);
      return;
    }
  } catch {
    // fall through to a full navigation
  }
  window.location.href = url;
}

/**
 * The entry to open after a turn: only when the turn saved exactly one new
 * draft (several drafts each keep their own buttons instead).
 *
 * @param {Array<{id: string, savedEntry?: {edit_url?: string}}>} cards
 * @param {string[]} autoSavedIds
 * @returns {string|null}
 */
export function singleAutoSavedEditUrl(cards, autoSavedIds) {
  if (!Array.isArray(autoSavedIds) || autoSavedIds.length !== 1) return null;
  const card = (cards || []).find((c) => c.id === autoSavedIds[0]);
  return card?.savedEntry?.edit_url || null;
}
