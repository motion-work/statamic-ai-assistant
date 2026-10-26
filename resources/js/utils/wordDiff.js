/**
 * Word-level diff for the "Vergleich" view after a rewrite: an LCS over
 * tokens (words and the whitespace between them). Long texts fall back to
 * "everything removed / everything added" instead of an O(n·m) table.
 *
 * @returns {Array<{type: 'same'|'add'|'del', text: string}>}
 */
export function diffWords(before, after) {
  const a = tokenize(before);
  const b = tokenize(after);

  if (a.length * b.length > 4_000_000) {
    return [
      { type: 'del', text: before },
      { type: 'add', text: after },
    ];
  }

  const rows = a.length + 1;
  const cols = b.length + 1;
  const table = new Uint16Array(rows * cols);

  for (let i = a.length - 1; i >= 0; i--) {
    for (let j = b.length - 1; j >= 0; j--) {
      table[i * cols + j] = a[i] === b[j]
        ? table[(i + 1) * cols + j + 1] + 1
        : Math.max(table[(i + 1) * cols + j], table[i * cols + j + 1]);
    }
  }

  const parts = [];
  const push = (type, text) => {
    const last = parts[parts.length - 1];
    if (last && last.type === type) last.text += text;
    else parts.push({ type, text });
  };

  let i = 0;
  let j = 0;
  while (i < a.length && j < b.length) {
    if (a[i] === b[j]) {
      push('same', a[i]); i++; j++;
    } else if (table[(i + 1) * cols + j] >= table[i * cols + j + 1]) {
      push('del', a[i]); i++;
    } else {
      push('add', b[j]); j++;
    }
  }
  while (i < a.length) push('del', a[i++]);
  while (j < b.length) push('add', b[j++]);

  return parts;
}

/** Plain text of an HTML string (for comparing Bard content). */
export function htmlToText(html) {
  const div = document.createElement('div');
  div.innerHTML = String(html ?? '')
    .replace(/<\/(p|h[1-6]|li|blockquote)>/gi, '</$1>\n')
    .replace(/<br\s*\/?>/gi, '\n');
  return (div.textContent || '').replace(/\n{3,}/g, '\n\n').trim();
}

function tokenize(text) {
  return String(text ?? '').match(/\s+|[^\s]+/g) || [];
}
