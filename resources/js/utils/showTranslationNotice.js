/**
 * Show the server's translation notice (e.g. "DeepL is not configured, the AI
 * model translated instead") as an info toast; falls back to a success toast on
 * Statamic builds without $toast.info.
 *
 * @param {string|null|undefined} notice
 */
export function showTranslationNotice(notice) {
  if (!notice) return;

  const toast = Statamic.$toast;
  if (typeof toast.info === 'function') {
    toast.info(notice, { duration: 8000 });
  } else {
    toast.success(notice, { duration: 8000 });
  }
}
