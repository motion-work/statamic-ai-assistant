import {
  HTTP_URL_RE,
  ASSET_FOLDER_MENTION_RE,
  FILE_MENTION_RE,
  trimUrlTrailingPunct,
  chipLabel,
  formatFileSize,
} from './composer/promptTokens.js';
import { renderChatMarkdown } from './utils/chatMarkdown.js';

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

const BOLD_MARKDOWN_RE = /\*\*([^*\n]+?)\*\*/gu;
const ITALIC_MARKDOWN_RE = /(^|[^*\w])\*([^*\s][^*\n]*?)\*(?!\*)/gu;
const CODE_MARKDOWN_RE = /`([^`\n]+?)`/gu;
const MD_LINK_RE = /\[([^\]\n]{1,200})\]\((https?:\/\/[^\s)]+)\)/giu;

/** Inline Markdown on already-escaped text: `code`, **bold**, *italic*. */
function formatBoldMarkdown(escapedText) {
  const codes = [];
  const protectedText = escapedText.replace(CODE_MARKDOWN_RE, (m, c) => {
    codes.push(`<code class="eg-chat__code">${c}</code>`);
    return `\u0000${codes.length - 1}\u0000`;
  });

  return protectedText
    .replace(BOLD_MARKDOWN_RE, '<strong class="eg-chat__bold">$1</strong>')
    .replace(ITALIC_MARKDOWN_RE, '$1<em>$2</em>')
    .replace(/\u0000(\d+)\u0000/g, (m, i) => codes[Number(i)]);
}

function mdLinkHtml(label, url) {
  const href = trimUrlTrailingPunct(url);

  return `<a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer" class="eg-chat__link" title="${escapeHtml(href)}">${formatBoldMarkdown(escapeHtml(label))}</a>`;
}

/**
 * Escape HTML, render **bold** markdown, and turn detected URLs into
 * new-tab link CHIPS (same look as in the composer, shortened label, full
 * URL as tooltip). The regex only matches http(s), so the href can never
 * be a javascript: or data: URL.
 *
 * @param {string|null|undefined} text
 * @returns {string}
 */
export function formatChatTextWithBoldUrls(text) {
  if (text == null) return '';
  const s = String(text);
  if (!s) return '';

  // [label](https://…) links first, so their URL does not become a bare chip.
  let linked = '';
  let from = 0;
  const md = new RegExp(MD_LINK_RE.source, 'giu');
  let link;
  while ((link = md.exec(s)) !== null) {
    linked += formatUrlChips(s.slice(from, link.index)) + mdLinkHtml(link[1], link[2]);
    from = link.index + link[0].length;
  }

  return linked + formatUrlChips(s.slice(from));
}

function formatUrlChips(s) {
  if (!s) return '';
  let out = '';
  let last = 0;
  const re = new RegExp(HTTP_URL_RE.source, 'giu');
  let m;
  while ((m = re.exec(s)) !== null) {
    out += formatBoldMarkdown(escapeHtml(s.slice(last, m.index)));
    const raw = m[0];
    const trimmed = trimUrlTrailingPunct(raw);
    const trailing = raw.slice(trimmed.length);
    out += `<a href="${escapeHtml(trimmed)}" target="_blank" rel="noopener noreferrer" class="eg-chat__chip eg-chat__chip--url" title="${escapeHtml(trimmed)}">${escapeHtml(chipLabel('url', trimmed))}</a>`;
    out += formatBoldMarkdown(escapeHtml(trailing));
    last = m.index + raw.length;
  }
  out += formatBoldMarkdown(escapeHtml(s.slice(last)));
  return out;
}

function escapeRegExp(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function assetFolderChipHtml(kind, container, path) {
  const ref = `${container}::${path}`;
  const fullTitle = `${kind}:${ref}`;
  return `<span class="eg-chat__chip eg-chat__chip--${kind}" data-mention-kind="${kind}" data-mention-ref="${escapeHtml(ref)}"><span class="eg-chat__chip-at">@</span>${escapeHtml(fullTitle)}</span>`;
}

/**
 * Wrap any "@asset:" / "@folder:" tokens (agent replies, typed refs, etc.).
 * Runs on escaped HTML and skips tokens already inside a chip span.
 *
 * @param {string} html
 * @returns {string}
 */
function wrapAssetFolderMentionChips(html) {
  return html.replace(ASSET_FOLDER_MENTION_RE, (match, kind, container, path, offset, full) => {
    const before = full.slice(Math.max(0, offset - 80), offset);
    if (before.includes('data-mention-ref=') || before.includes('eg-chat__chip-at')) {
      return match;
    }
    return assetFolderChipHtml(kind, container, path);
  });
}

/**
 * Wrap "@file:name.pdf" attachment tokens in a file chip (icon + name + size).
 * `attachments` is the turn's [{token, name, size}] metadata — the display
 * name and size come from there; an unknown token falls back to itself.
 *
 * @param {string} html
 * @param {Array<{token: ?string, name: string, size: number}>} attachments
 * @returns {string}
 */
function wrapFileMentionChips(html, attachments) {
  const byToken = new Map(
    (attachments || []).filter((a) => a && a.token).map((a) => [a.token, a]),
  );

  return html.replace(new RegExp(FILE_MENTION_RE.source, 'giu'), (match, token) => {
    const meta = byToken.get(token);
    const name = meta?.name || token;
    const size = meta?.size ? `<span class="eg-chat__chip-meta">${escapeHtml(formatFileSize(meta.size))}</span>` : '';

    return `<span class="eg-chat__chip eg-chat__chip--file" title="${escapeHtml(match)}">`
      + '<span class="eg-chip-icon eg-chip-icon--file" aria-hidden="true"></span>'
      + `${escapeHtml(name)}${size}</span>`;
  });
}

/**
 * Wrap "@Title" tokens for known entry mentions in a colored chip span. Runs on
 * already-escaped HTML (post formatChatTextWithBoldUrls), matching against the
 * HTML-escaped titles so entities like "&amp;" line up. Longest titles first so
 * overlapping names don't partially match.
 *
 * @param {string} html  Safe HTML from formatChatTextWithBoldUrls.
 * @param {string[]} titles  Entry titles that were referenced via the picker.
 * @returns {string}
 */
function wrapEntryMentionChips(html, titles) {
  if (!Array.isArray(titles) || titles.length === 0) return html;

  const unique = [...new Set(
    titles
      .filter((t) => typeof t === 'string' && t.trim() !== '')
      .filter((t) => !t.startsWith('asset:') && !t.startsWith('folder:')),
  )].sort((a, b) => b.length - a.length);

  let out = html;
  for (const title of unique) {
    const escaped = escapeRegExp(escapeHtml(title));
    const re = new RegExp(`@${escaped}`, 'g');
    out = out.replace(
      re,
      `<span class="eg-chat__chip eg-chat__chip--entry" data-mention-kind="entry" data-mention-title="${escapeHtml(title)}"><span class="eg-chat__chip-at">@</span>${escapeHtml(title)}</span>`,
    );
  }
  return out;
}

/**
 * Full chat message renderer: bold/URL formatting plus entry, asset/folder
 * and attachment chips.
 *
 * @param {string|null|undefined} text
 * @param {string[]} [mentionTitles]  Titles selected via the "@" entry picker.
 * @param {Array} [attachments]  The turn's attachment metadata [{token, name, size}].
 * @returns {string}
 */
export function formatChatMessageHtml(text, mentionTitles = [], attachments = []) {
  return wrapChips(formatChatTextWithBoldUrls(text), mentionTitles, attachments);
}

/**
 * Agent reply renderer: block Markdown (paragraphs, lists, headings, code)
 * with the same inline formatting and chips as formatChatMessageHtml.
 *
 * @param {string|null|undefined} text
 * @param {string[]} [mentionTitles]
 * @param {Array} [attachments]
 * @returns {string}
 */
export function formatAgentReplyHtml(text, mentionTitles = [], attachments = []) {
  return wrapChips(renderChatMarkdown(text, formatChatTextWithBoldUrls), mentionTitles, attachments);
}

function wrapChips(html, mentionTitles, attachments) {
  return wrapEntryMentionChips(
    wrapFileMentionChips(wrapAssetFolderMentionChips(html), attachments),
    mentionTitles,
  );
}
