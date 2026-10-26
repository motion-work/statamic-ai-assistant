import { applyBardWriteContent } from './applyBardWriteContent';
import { normalizeAiOutput } from './normalizeAiOutput';

/**
 * Bard helpers for the AI and translate buttons.
 *
 * Bard sets serialize to `<bard-set>{json}</bard-set>` in HTML. Sending that
 * HTML to an LLM or DeepL lets them drop or rewrite the JSON, which loses or
 * corrupts the set. So a document with sets is split into text runs (the
 * top-level nodes between sets); only the runs are sent out, and the result is
 * stitched back with the original set nodes untouched.
 */

let tiptap = null;

/** Called from the Bard extension factory, which receives Statamic's tiptap bundle. */
export function setBardTiptap(instance) {
  tiptap = instance;
}

const isSet = (node) => node?.type?.name === 'set';

function fragmentToHtml(editor, fragment) {
  if (tiptap?.core?.getHTMLFromFragment) {
    return tiptap.core.getHTMLFromFragment(fragment, editor.schema);
  }
  const serializer = tiptap.pm.model.DOMSerializer.fromSchema(editor.schema);
  const div = document.createElement('div');
  div.appendChild(serializer.serializeFragment(fragment));
  return div.innerHTML;
}

function htmlToNodes(editor, html) {
  const json = tiptap.core.generateJSON(normalizeAiOutput(html), editor.extensionManager.extensions);
  return json?.content || [];
}

/**
 * What the AI should work on right now.
 *
 * @returns {{
 *   mode: 'selection'|'runs'|'all',
 *   html: string,
 *   hasSets: boolean,
 *   range?: {from: number, to: number},
 *   runs?: Array<{type: 'text', html: string} | {type: 'set', node: object}>,
 * }}
 */
export function bardSnapshot(editor, { ignoreSelection = false } = {}) {
  const { state } = editor;
  const { selection, doc } = state;

  let hasSets = false;
  doc.forEach((node) => { if (isSet(node)) hasSets = true; });

  if (!ignoreSelection && !selection.empty && !isSet(selection.node)) {
    let selectionHasSet = false;
    doc.nodesBetween(selection.from, selection.to, (node) => { if (isSet(node)) selectionHasSet = true; });

    if (!selectionHasSet) {
      const html = fragmentToHtml(editor, doc.slice(selection.from, selection.to).content);
      if (htmlHasText(html)) {
        return { mode: 'selection', html, hasSets, range: { from: selection.from, to: selection.to } };
      }
    }
  }

  if (!hasSets) {
    return { mode: 'all', html: normalizeAiOutput(editor.getHTML()), hasSets };
  }

  const runs = [];
  let buffer = [];
  const flush = () => {
    if (buffer.length) {
      runs.push({ type: 'text', html: fragmentToHtml(editor, tiptap.pm.model.Fragment.fromArray(buffer)) });
      buffer = [];
    }
  };
  doc.forEach((node) => {
    if (isSet(node)) {
      flush();
      runs.push({ type: 'set', node: node.toJSON() });
    } else {
      buffer.push(node);
    }
  });
  flush();

  return {
    mode: 'runs',
    html: runs.filter((r) => r.type === 'text').map((r) => r.html).join(''),
    hasSets,
    runs,
  };
}

/** Text runs of a 'runs' snapshot, in order (what gets sent to the AI / translator). */
export function snapshotTexts(snapshot) {
  return snapshot.runs.filter((r) => r.type === 'text').map((r) => r.html);
}

/**
 * Write a result back to where the snapshot came from. Returns an undo callback.
 *
 * @param {string|string[]} result  HTML, or one HTML string per text run ('runs' mode)
 * @param {{insertAtCursor?: boolean}} options  "Neu schreiben" in a field with sets inserts instead of replacing
 */
export function applyToBard(editor, snapshot, result, options = {}) {
  const before = editor.getJSON();

  applyBardWriteContent(editor, () => {
    if (snapshot.mode === 'selection') {
      editor.chain().focus().insertContentAt(snapshot.range, normalizeAiOutput(result)).run();
      return;
    }

    if (snapshot.mode === 'runs' && options.insertAtCursor) {
      editor.chain().focus().insertContent(normalizeAiOutput(result)).run();
      return;
    }

    if (snapshot.mode === 'runs') {
      const texts = Array.isArray(result) ? [...result] : [result];
      const content = [];
      snapshot.runs.forEach((run) => {
        if (run.type === 'set') {
          content.push(run.node);
        } else {
          content.push(...htmlToNodes(editor, texts.shift() ?? run.html));
        }
      });
      editor.chain().focus().setContent({ type: 'doc', content }).run();
      return;
    }

    editor.chain().focus().setContent(normalizeAiOutput(result)).run();
  });

  return () => {
    applyBardWriteContent(editor, () => {
      editor.chain().focus().setContent(before).run();
    });
  };
}

export function htmlHasText(html) {
  return String(html ?? '').replace(/<[^>]+>/g, '').trim() !== '';
}
