/**
 * Single source of truth for the prompt token grammar — the special tokens a
 * chat message can contain. Used by BOTH renderers so they can never drift:
 *   - formatChatUrls.js turns tokens into chips in the rendered transcript
 *   - AgentComposerInput.vue turns them into live chip nodes while typing
 *
 * Grammar:
 *   @asset:container::path/to/file.jpg   asset reference
 *   @folder:container::path/to/folder    folder reference
 *   @file:report.pdf                     uploaded attachment (token name)
 *   @Some Entry Title                    entry mention (known titles only)
 *   https://example.com/page             plain URL
 */

/** Aligns loosely with PromptUrlFetcher::extractPublicHttpUrls (http(s) only, word boundary). */
export const HTTP_URL_RE = /\bhttps?:\/\/[^\s<>\]\}\)"'`]+/giu;

/** "@asset:container::path" / "@folder:container::path". */
export const ASSET_FOLDER_MENTION_RE = /@(asset|folder):([a-z0-9_-]+)::([^<\s]+)/giu;

/** "@file:report.pdf" — an uploaded attachment, referenced by its token name. */
export const FILE_MENTION_RE = /@file:([a-z0-9][a-z0-9._-]*)/giu;

/** Trailing sentence punctuation never belongs to a URL. */
export function trimUrlTrailingPunct(raw) {
  return raw.replace(/[.,;:!?)\]}'"]+$/u, '');
}

function escapeRegExp(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Compact human label for a chip. */
export function chipLabel(kind, token) {
  if (kind === 'url') {
    try {
      const u = new URL(token);
      const path = u.pathname !== '/' ? u.pathname : '';
      const short = u.host + path;
      return short.length > 40 ? `${short.slice(0, 39)}…` : short;
    } catch {
      return token.length > 40 ? `${token.slice(0, 39)}…` : token;
    }
  }
  if (kind === 'asset' || kind === 'folder') {
    // "@asset:container::a/b/file.jpg" → "file.jpg"
    const path = token.split('::').pop() || token;
    const base = path.split('/').filter(Boolean).pop() || path;
    return base.length > 32 ? `${base.slice(0, 31)}…` : base;
  }
  if (kind === 'file') {
    // "@file:report.pdf" → "report.pdf"
    const name = token.replace(/^@?file:/, '');
    return name.length > 40 ? `${name.slice(0, 39)}…` : name;
  }
  // Entry mention: the title itself ("@Title" → "Title").
  return token.startsWith('@') ? token.slice(1) : token;
}

/** Human-readable file size for chip metadata ("245.3 KB"). */
export function formatFileSize(bytes) {
  const n = Number(bytes) || 0;
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Split plain prompt text into text/chip segments, in document order.
 * `knownTitles` are the entry titles registered via the "@" picker — only
 * those become entry chips (anything else after "@" is ordinary text).
 *
 * @param {string} text
 * @param {string[]} [knownTitles]
 * @returns {Array<{type:'text', text:string}|{type:'chip', kind:'asset'|'folder'|'entry'|'file'|'url', text:string, label:string}>}
 */
export function tokenizePrompt(text, knownTitles = []) {
  const s = String(text ?? '');
  if (s === '') return [];

  // Collect all matches with positions, then emit non-overlapping segments
  // (asset/folder refs win over URLs, longer entry titles over shorter ones).
  const matches = [];

  let m;
  const assetRe = new RegExp(ASSET_FOLDER_MENTION_RE.source, 'giu');
  while ((m = assetRe.exec(s)) !== null) {
    matches.push({ start: m.index, end: m.index + m[0].length, kind: m[1].toLowerCase(), text: m[0] });
  }

  const fileRe = new RegExp(FILE_MENTION_RE.source, 'giu');
  while ((m = fileRe.exec(s)) !== null) {
    matches.push({ start: m.index, end: m.index + m[0].length, kind: 'file', text: m[0] });
  }

  const urlRe = new RegExp(HTTP_URL_RE.source, 'giu');
  while ((m = urlRe.exec(s)) !== null) {
    const trimmed = trimUrlTrailingPunct(m[0]);
    if (trimmed === '') continue;
    matches.push({ start: m.index, end: m.index + trimmed.length, kind: 'url', text: trimmed });
  }

  const titles = [...new Set(
    (knownTitles || [])
      .filter((t) => typeof t === 'string' && t.trim() !== '')
      .filter((t) => !t.startsWith('asset:') && !t.startsWith('folder:')),
  )].sort((a, b) => b.length - a.length);

  for (const title of titles) {
    const re = new RegExp(`@${escapeRegExp(title)}`, 'g');
    while ((m = re.exec(s)) !== null) {
      matches.push({ start: m.index, end: m.index + m[0].length, kind: 'entry', text: m[0] });
    }
  }

  // Earliest first; on ties, longest wins. Drop anything overlapping a kept match.
  matches.sort((a, b) => a.start - b.start || b.end - a.end);
  const kept = [];
  let lastEnd = 0;
  for (const match of matches) {
    if (match.start < lastEnd) continue;
    kept.push(match);
    lastEnd = match.end;
  }

  const segments = [];
  let cursor = 0;
  for (const match of kept) {
    if (match.start > cursor) {
      segments.push({ type: 'text', text: s.slice(cursor, match.start) });
    }
    segments.push({ type: 'chip', kind: match.kind, text: match.text, label: chipLabel(match.kind, match.text) });
    cursor = match.end;
  }
  if (cursor < s.length) {
    segments.push({ type: 'text', text: s.slice(cursor) });
  }

  return segments;
}
