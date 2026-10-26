import {
  HTTP_URL_RE,
  ASSET_FOLDER_MENTION_RE,
  FILE_MENTION_RE,
  trimUrlTrailingPunct,
  chipLabel,
  formatFileSize,
} from './composer/promptTokens.js';

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

const BOLD_MARKDOWN_RE = /\*\*([^*\n]+?)\*\*/gu;

function formatBoldMarkdown(escapedText) {
  return escapedText.replace(BOLD_MARKDOWN_RE, '<strong class="eg-chat__bold">$1</strong>');
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
  const html = formatChatTextWithBoldUrls(text);
  return wrapEntryMentionChips(
    wrapFileMentionChips(wrapAssetFolderMentionChips(html), attachments),
    mentionTitles,
  );
}
