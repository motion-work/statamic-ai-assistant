/**
 * Success toast with a "Rückgängig" action (Statamic's toasts are vue-toasted,
 * which supports action buttons). The close "×" is kept as a second action.
 *
 * @param {string} message
 * @param {() => void} onUndo
 */
export function showUndoToast(message, onUndo) {
  Statamic.$toast.success(message, {
    duration: 10000,
    action: [
      {
        text: __('Undo'),
        onClick: (event, toast) => {
          onUndo();
          toast.goAway(0);
        },
      },
      {
        text: '×',
        onClick: (event, toast) => toast.goAway(0),
      },
    ],
  });
}
