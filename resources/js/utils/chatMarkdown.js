/**
 * Lightweight block Markdown for AI agent replies: paragraphs with line
 * breaks, headings (#, ##, ###), bulleted and numbered lists and fenced code.
 * Inline formatting (escaping, bold, italic, code, links, chips) is delegated
 * to `inline`, which MUST escape HTML itself; code blocks are escaped here.
 * Pure (no imports), so it can be checked with plain node.
 */

const HEADING = /^(#{1,3})\s+(.+)$/;
const BULLET = /^\s*[-*•]\s+(.+)$/;
const NUMBERED = /^\s*(\d{1,3})[.)]\s+(.+)$/;
const FENCE = /^\s*```/;

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/**
 * @param {string|null|undefined} text
 * @param {(line: string) => string} inline  Escapes + formats one run of inline text.
 * @returns {string}
 */
export function renderChatMarkdown(text, inline) {
  const lines = String(text ?? '').replace(/\r\n?/g, '\n').split('\n');
  const out = [];
  let para = [];
  let list = null; // { tag: 'ul'|'ol', start: number, items: string[] }
  let code = null; // string[] while inside a fence

  const flushPara = () => {
    if (para.length) out.push(`<p>${para.map(inline).join('<br>')}</p>`);
    para = [];
  };
  const flushList = () => {
    if (!list) return;
    const start = list.tag === 'ol' && list.start !== 1 ? ` start="${list.start}"` : '';
    out.push(`<${list.tag}${start}>${list.items.map((i) => `<li>${inline(i)}</li>`).join('')}</${list.tag}>`);
    list = null;
  };
  const pushItem = (tag, item, start = 1) => {
    flushPara();
    if (!list || list.tag !== tag) {
      flushList();
      list = { tag, start, items: [] };
    }
    list.items.push(item);
  };

  for (const line of lines) {
    if (code) {
      if (FENCE.test(line)) {
        out.push(`<pre><code>${escapeHtml(code.join('\n'))}</code></pre>`);
        code = null;
      } else {
        code.push(line);
      }
      continue;
    }
    if (FENCE.test(line)) {
      flushPara();
      flushList();
      code = [];
      continue;
    }
    if (line.trim() === '') {
      flushPara();
      flushList();
      continue;
    }

    const heading = line.match(HEADING);
    const bullet = line.match(BULLET);
    const numbered = line.match(NUMBERED);
    if (heading) {
      flushPara();
      flushList();
      out.push(`<p class="eg-md__h eg-md__h--${heading[1].length}">${inline(heading[2])}</p>`);
    } else if (bullet) {
      pushItem('ul', bullet[1]);
    } else if (numbered) {
      pushItem('ol', numbered[2], Number(numbered[1]));
    } else {
      flushList();
      para.push(line);
    }
  }

  if (code) out.push(`<pre><code>${escapeHtml(code.join('\n'))}</code></pre>`);
  flushPara();
  flushList();

  return out.join('');
}
