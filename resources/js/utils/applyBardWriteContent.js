/**
 * Apply HTML to the Bard TipTap editor after async work (e.g. DeepL).
 * ProseMirror can throw RangeError "Applying a mismatched transaction" when
 * setContent runs while the document was updated by another sync (Vue/Bard).
 * We defer past the next paint and retry a few times on that error.
 */
export function isMismatchedProseMirrorTransactionError(err) {
  return (
    err instanceof RangeError &&
    String(err?.message ?? '').includes('mismatched transaction')
  );
}

export function applyBardWriteContent(editor, runSetContent) {
  if (!editor || editor.isDestroyed) {
    return;
  }

  const apply = () => {
    if (!editor || editor.isDestroyed) {
      return;
    }
    runSetContent();
  };

  const attempt = (n) => {
    try {
      apply();
    } catch (err) {
      if (!isMismatchedProseMirrorTransactionError(err) || n >= 4) {
        throw err;
      }
      requestAnimationFrame(() => attempt(n + 1));
    }
  };

  // Two frames: run after Vue flush + Bard internal updates from the async gap.
  requestAnimationFrame(() => {
    requestAnimationFrame(() => attempt(0));
  });
}
