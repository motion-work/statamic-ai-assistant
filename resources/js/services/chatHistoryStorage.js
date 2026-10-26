/**
 * Local (browser-only) persistence for AI agent conversations.
 *
 * Chats are stored in localStorage, namespaced per CP user, so history never
 * touches the server: the durable copy of a conversation is the transcript in
 * the user's own browser. The server keeps only its usual transient cache
 * session; when that expires, a chat is resumed by reseeding a new session
 * from this stored transcript (see entryGeneratorStore).
 *
 * Record shape:
 *   {
 *     id: string,               // client-side chat id (stable across sessions)
 *     title: string,            // derived from the first user message
 *     createdAt: number,        // epoch ms
 *     updatedAt: number,        // epoch ms — list is ordered by this, desc
 *     sessionId: string|null,   // last known server session id (may be expired)
 *     transcript: array,        // [{role, text, entry_ids, kind, mention_titles?}]
 *     mentionedTitles: array,   // "@" tokens used in this chat (chip rendering)
 *   }
 *
 * This module is a pure storage layer — no Vue, no store imports. All
 * conversation lifecycle logic lives in entryGeneratorStore.
 */

const KEY_PREFIX = 'ai-agent-chats:';
const MAX_CHATS = 30;
const MAX_TRANSCRIPT_TURNS = 80;

/** Storage key namespaced to the logged-in CP user. */
function storageKey() {
  let userId = 'anon';
  try {
    const user = window.Statamic?.$config?.get?.('user');
    userId = user?.id || user?.email || 'anon';
  } catch {
    // Statamic globals unavailable (tests, early boot) — shared fallback bucket.
  }
  return `${KEY_PREFIX}${userId}`;
}

/** @returns {Array} all records, newest first. Never throws. */
function readAll() {
  try {
    const raw = window.localStorage.getItem(storageKey());
    const parsed = raw ? JSON.parse(raw) : [];
    if (!Array.isArray(parsed)) return [];
    return parsed
      .filter((r) => r && typeof r.id === 'string' && Array.isArray(r.transcript))
      .sort((a, b) => (b.updatedAt || 0) - (a.updatedAt || 0));
  } catch {
    return [];
  }
}

/**
 * Write the full list. On quota errors, evict oldest records and retry once —
 * losing the oldest chat beats silently losing the newest.
 */
function writeAll(records) {
  const list = records.slice(0, MAX_CHATS);
  try {
    window.localStorage.setItem(storageKey(), JSON.stringify(list));
  } catch {
    try {
      window.localStorage.setItem(storageKey(), JSON.stringify(list.slice(0, Math.max(1, Math.floor(list.length / 2)))));
    } catch {
      // Storage unavailable (private mode / disabled) — history is best-effort.
    }
  }
}

export function newChatId() {
  try {
    return window.crypto.randomUUID();
  } catch {
    return `chat-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
  }
}

/** Human title for a chat, derived from its first user message. */
export function deriveChatTitle(transcript) {
  const first = (transcript || []).find((t) => t?.role === 'user' && (t.text || '').trim() !== '');
  if (!first) return '';
  return first.text
    // "@asset:container::path/file.jpg" → "@file.jpg" so titles stay readable.
    .replace(/@(?:asset|folder):\S*?([^/\s:]+)(?=\s|$)/g, '@$1')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 80);
}

/** @returns {Array} newest-first list of saved chats. */
export function listChats() {
  return readAll();
}

export function getChat(id) {
  return readAll().find((r) => r.id === id) || null;
}

/**
 * Insert or update a chat record. Only the fields the caller provides are
 * replaced; updatedAt is always bumped. Transcripts are capped to the newest
 * MAX_TRANSCRIPT_TURNS turns to keep records bounded.
 */
export function upsertChat({ id, title, sessionId, transcript, mentionedTitles }) {
  if (!id || !Array.isArray(transcript) || transcript.length === 0) return;

  const records = readAll();
  const existing = records.find((r) => r.id === id);
  const now = Date.now();

  const record = {
    id,
    title: title || deriveChatTitle(transcript) || existing?.title || '',
    createdAt: existing?.createdAt || now,
    updatedAt: now,
    sessionId: sessionId ?? existing?.sessionId ?? null,
    transcript: transcript.slice(-MAX_TRANSCRIPT_TURNS),
    mentionedTitles: Array.isArray(mentionedTitles) ? mentionedTitles : (existing?.mentionedTitles || []),
  };

  writeAll([record, ...records.filter((r) => r.id !== id)]);
}

export function deleteChat(id) {
  writeAll(readAll().filter((r) => r.id !== id));
}
