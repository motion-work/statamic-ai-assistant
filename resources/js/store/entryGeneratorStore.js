/**
 * Module-scope reactive store for the AI entry generator.
 *
 * Lives outside any component lifecycle so generation state, the in-flight NDJSON
 * stream, and per-card status survive:
 *   - closing/minimizing the drawer
 *   - navigating between CP pages (Statamic CP is one Vue app; this module persists)
 *
 * Components import `state` (read-only consumption) and the action functions
 * (mutations). The launcher and the page both observe the same singleton.
 */

import { reactive } from 'vue';
import axios from 'axios';
import {
  newChatId,
  listChats,
  getChat,
  upsertChat,
  deleteChat,
} from '../services/chatHistoryStorage.js';

export const STATUS = {
  QUEUED: 'queued',
  DRAFTING: 'drafting',
  READY: 'ready',
  SAVING: 'saving',
  SAVED: 'saved',
  FAILED: 'failed',
};

export const state = reactive({
  // Whether the drawer panel is currently shown to the user.
  active: false,

  // Composer state — lives in the store so it survives close/reopen.
  pendingPrompt: '',
  // Files for the NEXT message: [{token, file}] — token is the "@file:<name>"
  // chip reference (null for the wizard's chipless single attachment).
  pendingAttachedFiles: [],

  // Generation lifecycle.
  generating: false,        // true from request start until done event
  planning: false,          // true between request start and plan event
  plan: null,               // { entries:[…], warnings:[…] } from server
  entries: [],              // list of card states
  generationError: null,    // fatal pre-plan error (current turn only)
  plannerAnswer: null,      // read-only answer to a question (current turn only)
  operations: [],           // completed non-entry operations this turn: [{id,kind,label}]
  transcript: [],           // full chat history from the server: [{role,text,entry_ids,kind}]
  chatSessionId: null,      // durable session id across turns (NOT cleared at poll-terminal)
  currentChatId: null,      // id of this conversation's local-history record
  bulkSaving: false,

  // Titles referenced via the composer "@" picker this session. Used purely to
  // render "@Title" tokens as chips in the chat transcript.
  mentionedTitles: [],

  /**
   * Human-readable activity lines (planning = entryId null; drafting = entry uuid).
   * Each item: { id: number, text: string, entryId: string|null }.
   */
  activityLog: [],
  hasServerActivity: false, // true once the planner reported a REAL step this turn (suppresses canned filler)

  // Field schema cache, keyed `${collection}/${blueprint}`.
  fieldPreviewCache: {},

  // Queued batch (after NDJSON batch event): poll generate-progress until planner is done
  // and all cards settle. Cards are pushed incrementally as the planner discovers them.
  generationBatchSessionId: null,
  generationPlanningStatus: null,   // 'planning' | 'planned' | 'planning_failed'
  _generationBatchPollTimer: null,

  // Internal — not consumed by templates.
  _abortController: null,
  _backgroundedAt: null,
  /** Set true by stopGeneration so the AbortError catch suppresses the cancel banner. */
  _userStopped: false,
});

/** Monotonic ids for activity list keys (Vue transitions). */
let _activityLineId = 0;

const MAX_ACTIVITY_LINES = 48;

/** JSON / API-ish keys we never surface as “field” labels from the raw stream. */
const SKIP_STREAM_FIELD_KEYS = new Set([
  'type', 'id', 'data', 'content', 'value', 'values', 'sets', 'fields', 'items', 'entries',
  'true', 'false', 'null', 'mode', 'text', 'role', 'tool', 'name', 'function', 'arguments',
  'properties', 'additional', 'system', 'user', 'assistant', 'metadata', 'object', 'array',
  'string', 'number', 'boolean', 'required', 'enum', 'default', 'const',
]);

/**
 * Append one line to the activity feed (dedupes consecutive identical line for same entry).
 * @param {string} text
 * @param {string|null} entryId  Target entry id, or null for planning / global hints.
 */
export function pushActivityLine(text, entryId = null) {
  const t = (text || '').trim();
  if (!t) return;
  const prev = state.activityLog[state.activityLog.length - 1];
  if (prev && prev.text === t && prev.entryId === entryId) return;
  _activityLineId += 1;
  state.activityLog.push({ id: _activityLineId, text: t, entryId });
  while (state.activityLog.length > MAX_ACTIVITY_LINES) {
    state.activityLog.shift();
  }
}

function truncateActivityLabel(s, max = 52) {
  const t = (s || '').trim();
  if (t.length <= max) return t;
  return `${t.slice(0, max - 1)}…`;
}

function humanizeFieldKey(k) {
  return k.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Infer completed JSON object keys from streamed text; emits at most one new line per scan.
 */
function trackStreamFieldKeys(card, delta) {
  if (!delta) return;
  card._tokenScratch = ((card._tokenScratch || '') + delta).slice(-4000);
  if (!card.streamKeysLogged) card.streamKeysLogged = {};
  const len = card.tokenLength || 0;
  const lastScan = card._lastKeyScanTokenLen || 0;
  if (len - lastScan < 280) return;
  card._lastKeyScanTokenLen = len;

  const re = /"([a-z][a-z0-9_]*)"\s*:/g;
  let m;
  const scratch = card._tokenScratch;
  let added = 0;
  while ((m = re.exec(scratch)) !== null) {
    const k = m[1];
    if (k.length < 2 || k.length > 56 || SKIP_STREAM_FIELD_KEYS.has(k) || card.streamKeysLogged[k]) {
      continue;
    }
    card.streamKeysLogged[k] = true;
    pushActivityLine(_trans('Writing field — :field', { field: humanizeFieldKey(k) }), card.id);
    added += 1;
    if (added >= 4) break;
  }
}

// Translator + toaster injected by the host (component) since the store cannot
// reach Vue's instance-level $t / $toast directly.
let _trans = (s) => s;
let _toast = null;

export function setI18n(translator) {
  if (typeof translator === 'function') _trans = translator;
}

export function setToaster(toast) {
  _toast = toast;
}

/** Remember a title picked via the "@" entry picker (for transcript chips). */
export function registerMention(title) {
  const t = typeof title === 'string' ? title.trim() : '';
  if (t !== '' && !state.mentionedTitles.includes(t)) {
    state.mentionedTitles.push(t);
  }
}

/** Titles from the session picker that appear as "@Title" in this message. */
function mentionTitlesForPrompt(text) {
  const s = String(text || '');
  if (!s || state.mentionedTitles.length === 0) return [];

  const found = [];
  for (const title of [...state.mentionedTitles].sort((a, b) => b.length - a.length)) {
    if (s.includes(`@${title}`)) found.push(title);
  }
  return found;
}

// ─── Pending attachments (multi-file, chip-referenced) ──────────────────

/**
 * Register a file for the NEXT message. Returns the unique "@file:<token>"
 * token name (slugged filename, deduped) the composer inserts as a chip, or
 * null with `withToken: false` (full-page wizard — no chips there). The File
 * objects live only until the message is sent.
 */
export function addPendingFile(file, { withToken = true } = {}) {
  if (!file) return null;

  let token = null;
  if (withToken) {
    const ext = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';
    const slug = file.name
      .replace(/\.[^.]+$/, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '') || 'file';
    const taken = state.pendingAttachedFiles.map((f) => f.token);
    token = ext ? `${slug}.${ext}` : slug;
    for (let i = 2; taken.includes(token); i += 1) {
      token = ext ? `${slug}-${i}.${ext}` : `${slug}-${i}`;
    }
  }

  state.pendingAttachedFiles.push({ token, file });
  return token;
}

export function removePendingFile(token) {
  state.pendingAttachedFiles = state.pendingAttachedFiles.filter((f) => f.token !== token);
}

/**
 * The files to send with a message: tokenless ones (wizard) always go; chip
 * files only while their "@file:" token is still in the prompt — deleting a
 * chip drops its file.
 */
function pendingFilesForPrompt(text) {
  return state.pendingAttachedFiles.filter(
    (f) => f.token === null || String(text).includes(`@file:${f.token}`),
  );
}

/** Serializable per-turn metadata so chat chips can show name + size later. */
function attachmentsMeta(files) {
  return files.map(({ token, file }) => ({
    token,
    name: file.name || '',
    size: Number(file.size) || 0,
  }));
}

/** Append the given pending files to a FormData body (shared by all sends). */
function appendAttachments(formData, files) {
  for (const { token, file } of files) {
    formData.append('attachments[]', file, token || file.name);
  }
}

/** Preserve client-only turn metadata (mention_titles, attachments) when the
 * server transcript overwrites the client copy. The server never sees these
 * fields, so without this they'd vanish on the first poll. */
function mergeTranscriptMentions(incoming, previous) {
  if (!Array.isArray(incoming)) return incoming;

  const prevByText = new Map();
  for (const turn of previous || []) {
    if (turn?.role !== 'user' || !turn.text) continue;
    prevByText.set(turn.text, {
      mention_titles: Array.isArray(turn.mention_titles) ? turn.mention_titles : [],
      // `attachment` (single object) is the legacy shape from older saved chats.
      attachments: Array.isArray(turn.attachments) ? turn.attachments : [],
      attachment: turn.attachment || null,
    });
  }

  return incoming.map((turn) => {
    if (turn?.role !== 'user') return turn;
    const preserved = prevByText.get(turn.text) || {};
    const detected = mentionTitlesForPrompt(turn.text);
    const merged = [...new Set([...(preserved.mention_titles || []), ...detected])];
    const attachments = Array.isArray(turn.attachments) && turn.attachments.length
      ? turn.attachments
      : (preserved.attachments || []);
    const attachment = turn.attachment || preserved.attachment || null;
    const next = { ...turn };
    if (merged.length) next.mention_titles = merged;
    if (attachments.length) next.attachments = attachments;
    if (attachment) next.attachment = attachment;
    return next;
  });
}

// ─── Local chat history (per-user, browser-only) ────────────────────────

/**
 * Snapshot the live conversation into localStorage. Called wherever the
 * transcript changes (optimistic user turns + every server overwrite), so the
 * saved record always mirrors what the user sees.
 */
function persistChatHistory() {
  if (!state.currentChatId || state.transcript.length === 0) return;
  upsertChat({
    id: state.currentChatId,
    sessionId: state.chatSessionId,
    transcript: state.transcript,
    mentionedTitles: state.mentionedTitles,
  });
}

/** Saved conversations for the history panel, newest first. */
export function listChatHistory() {
  return listChats();
}

/**
 * Load a saved conversation into the live state so the user can read it and
 * continue from where it left off. Follow-ups reuse the recorded server
 * session when it is still alive; continueGeneration falls back to the
 * resume endpoint when it has expired. Refused while a turn is in flight.
 */
export function openChatFromHistory(id) {
  if (state.generating || state.planning) {
    if (_toast) _toast.error(_trans('Wait for the current generation to finish first.'));
    return false;
  }
  const record = getChat(id);
  if (!record) return false;

  resetGenerationOnly();
  state.pendingAttachedFiles = [];
  state.currentChatId = record.id;
  state.chatSessionId = record.sessionId || null;
  state.mentionedTitles = [...(record.mentionedTitles || [])];
  state.transcript = record.transcript.map((t) => ({ ...t }));
  return true;
}

/** Remove a conversation from local history (and from view when it is open). */
export function deleteChatFromHistory(id) {
  // The open chat re-persists on every progress poll — deleting it mid-turn
  // would just resurrect it, so refuse instead of pretending it worked.
  if (state.currentChatId === id && (state.generating || state.planning)) {
    if (_toast) _toast.error(_trans('Wait for the current generation to finish first.'));
    return;
  }
  deleteChat(id);
  if (state.currentChatId === id) {
    reset();
  }
}

// ─── Activity / visibility ───────────────────────────────────────────────

/**
 * Headline activity used by the launcher bubble and the page.
 * busy = something in flight; ready = number of cards waiting for the user.
 */
export function getActivity() {
  const ready = state.entries.filter((e) => e.status === STATUS.READY).length;
  const busy = state.generating || state.planning || state.bulkSaving
    || state.entries.some((e) => e.status === STATUS.SAVING);
  return { busy, ready };
}

export function setActive(active) {
  const prev = state.active;
  state.active = !!active;
  if (prev && !active && (state.generating || state.planning)) {
    state._backgroundedAt = Date.now();
  }
  if (active) {
    state._backgroundedAt = null;
  }
}

// ─── Generation: plan via NDJSON, then optional Redis batch poll ─────────

function clearGenerationBatchPoll() {
  if (state._generationBatchPollTimer) {
    clearTimeout(state._generationBatchPollTimer);
    state._generationBatchPollTimer = null;
  }
}

function buildCardFromSnapshotEntry(se, index) {
  const operation = se.operation === 'update' ? 'update' : 'create';
  const entryId = typeof se.entry_id === 'string' && se.entry_id ? se.entry_id : null;
  return {
    id: se.id,
    index,
    label: se.label,
    prompt: se.prompt,
    collection: se.collection,
    blueprint: se.blueprint,
    collectionTitle: se.collection_title || se.collection,
    blueprintTitle: se.blueprint_title || se.blueprint,
    operation,
    entryId,
    status: STATUS.QUEUED,
    tokenLength: 0,
    data: null,
    displayData: null,
    fieldPreview: null,
    warnings: [],
    error: null,
    savedEntry: null,
    savingMode: null,
    _tokenScratch: '',
    streamKeysLogged: {},
    _lastKeyScanTokenLen: 0,
    _pollStatus: 'pending',
  };
}

function applyBatchProgressSnapshot(data) {
  // Planner status mapping (additive — cards appear as they are dispatched).
  const planningStatus = data.planning_status || 'planning';
  const previousPlanning = state.generationPlanningStatus;
  state.generationPlanningStatus = planningStatus;

  if (planningStatus === 'planning' && previousPlanning !== 'planning') {
    state.planning = true;
  }

  if (planningStatus === 'planning_failed') {
    state.planning = false;
    if (data.planner_error && !state.generationError) {
      state.generationError = data.planner_error;
    }
  }

  // Full conversation history — server is the source of truth. Overwrite each poll.
  if (Array.isArray(data.transcript)) {
    state.transcript = mergeTranscriptMentions(data.transcript, state.transcript);
    persistChatHistory();
    // Errors are rendered from the transcript turn — don't also show the live banner.
    const last = data.transcript[data.transcript.length - 1];
    if (last?.role === 'assistant' && last?.kind === 'error') {
      state.generationError = null;
    }
  }

  // Read-only answer (e.g. "which entry contains X?"). A success, shown as a
  // normal agent reply — never the "Something went wrong" error panel.
  if (data.planner_answer && !state.plannerAnswer) {
    state.plannerAnswer = data.planner_answer;
    state.planning = false;
  }

  // Live planner step feed (fetching URLs, reading layouts, deciding). The buffer
  // is drained server-side on each poll, so every line here is new — just append.
  for (const line of (data.planner_activity || [])) {
    state.hasServerActivity = true;
    pushActivityLine(line, null);
  }

  // Completed non-entry operations (structural changes, …) — persistent per
  // turn, server is the source of truth. Rendered as a checklist in the panel.
  if (Array.isArray(data.operations)) {
    state.operations = data.operations;
  }

  const rows = data.entries || [];

  // 1. Add any entries the planner has just discovered. Cards appear in the
  //    drawer the moment the planner calls create_entry_job, even if the
  //    worker has not started generating yet.
  for (const se of rows) {
    if (!se || !se.id) continue;
    if (state.entries.some((e) => e.id === se.id)) continue;
    const card = buildCardFromSnapshotEntry(se, state.entries.length);
    state.entries.push(card);
    if (!state.plan) {
      state.plan = { entries: [], warnings: data.warnings || [] };
    }
    state.plan.entries.push({
      id: se.id,
      collection: se.collection,
      blueprint: se.blueprint,
      label: se.label,
      prompt: se.prompt,
      collection_title: se.collection_title,
      blueprint_title: se.blueprint_title,
    });
    const label = truncateActivityLabel(se.label || se.collection_title || se.collection);
    pushActivityLine(_trans('Plan grew — :label', { label }), null);
    fetchFieldPreview(se.collection, se.blueprint).then((schema) => {
      if (!schema) return;
      const target = state.entries.find((c) => c.id === se.id);
      if (target && !target.fieldPreview) target.fieldPreview = schema;
    });
  }

  if (state.plan && Array.isArray(data.warnings)) {
    state.plan.warnings = data.warnings;
  }

  // 2. Apply per-card status transitions for cards we already track.
  for (const se of rows) {
    const card = state.entries.find((e) => e.id === se.id);
    if (!card) continue;

    const prev = card._pollStatus;
    const next = se.status || 'pending';

    if (next === 'generating' && prev !== 'generating') {
      card.status = STATUS.DRAFTING;
      card.tokenLength = typeof se.token_length === 'number' ? se.token_length : card.tokenLength;
      const label = truncateActivityLabel(card.label || card.collectionTitle || card.collection);
      pushActivityLine(
        card.operation === 'update'
          ? _trans('Updating — :label', { label })
          : _trans('Drafting — :label', { label }),
        card.id,
      );
    } else if (next === 'pending') {
      card.status = STATUS.QUEUED;
    }

    if (se.stream_delta) {
      trackStreamFieldKeys(card, se.stream_delta);
    }
    if (typeof se.token_length === 'number') {
      card.tokenLength = se.token_length;
    }

    if (next === 'ready' && prev !== 'ready') {
      card.status = STATUS.READY;
      card.data = se.data;
      card.displayData = se.displayData || se.data;
      card.warnings = se.warnings || [];
      if (!card.fieldPreview) {
        fetchFieldPreview(card.collection, card.blueprint).then((s) => {
          if (s) card.fieldPreview = s;
        });
      }
      const label = truncateActivityLabel(card.label || card.collectionTitle || card.collection);
      pushActivityLine(
        card.operation === 'update'
          ? _trans('Update ready — :label', { label })
          : _trans('Finished — :label', { label }),
        card.id,
      );
    }

    if (next === 'failed' && prev !== 'failed') {
      card.status = STATUS.FAILED;
      card.error = se.error || _trans('Generation failed.');
      const label = truncateActivityLabel(card.label || card.collectionTitle || card.collection);
      pushActivityLine(_trans('Could not finish — :label', { label }), card.id);
    }

    card._pollStatus = next;
  }

  // 3. Once the planner has finished, surface a "plan ready" line and stop the planning indicator.
  if (planningStatus === 'planned' && previousPlanning !== 'planned') {
    state.planning = false;
    const n = state.entries.length;
    pushActivityLine(
      n === 0
        ? _trans('Plan ready — no entries.')
        : (n === 1
          ? _trans('Plan ready — one entry.')
          : _trans('Plan ready — :n entries.', { n })),
      null,
    );
  }
}

async function pollGenerationBatchOnce() {
  const sid = state.generationBatchSessionId;
  if (!sid || !state.generating) return;

  try {
    const { data } = await axios.get(`/cp/ai-generate/generate-progress/${sid}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    applyBatchProgressSnapshot(data);

    const planningDone = data.planning_status === 'planned' || data.planning_status === 'planning_failed';
    const sessionTerminal = data.status === 'completed' || data.status === 'cancelled';
    const allEntriesTerminal = state.entries.length > 0 && state.entries.every(
      (e) => e.status === STATUS.READY || e.status === STATUS.FAILED,
    );

    if (sessionTerminal || (planningDone && state.entries.length === 0) || (planningDone && allEntriesTerminal)) {
      clearGenerationBatchPoll();
      state.generating = false;
      state.planning = false;
      state.generationBatchSessionId = null;
      notifyBackgroundCompletionIfNeeded();
      return;
    }
  } catch (e) {
    if (e?.response?.status === 404) {
      state.generationError = _trans('Generation session expired or was not found.');
      clearGenerationBatchPoll();
      state.generating = false;
      state.planning = false;
      state.generationBatchSessionId = null;
      return;
    }
  }

  state._generationBatchPollTimer = setTimeout(() => pollGenerationBatchOnce(), 2000);
}

function scheduleGenerationBatchPoll() {
  clearGenerationBatchPoll();
  pollGenerationBatchOnce();
}

export async function startGeneration({ prompt, useAutoTarget, collection, blueprint }) {
  if (state.generating) return;
  const text = (prompt || '').trim();
  if (text.length < 3) return;

  const mentionTitles = mentionTitlesForPrompt(text);
  const files = pendingFilesForPrompt(text);

  resetGenerationOnly();
  state.generating = true;
  state.planning = true;
  state.pendingPrompt = '';
  // Optimistic first user turn so the chat shows the message before the first poll.
  state.transcript = [{ role: 'user', text, entry_ids: [], kind: null, mention_titles: mentionTitles, attachments: attachmentsMeta(files) }];
  state.currentChatId = newChatId();
  persistChatHistory();

  const formData = new FormData();
  if (useAutoTarget) {
    formData.append('auto_resolve_target', '1');
  } else {
    formData.append('collection', collection);
    formData.append('blueprint', blueprint || '');
    formData.append('auto_resolve_target', '0');
  }
  formData.append('prompt', text);
  appendAttachments(formData, files);
  // The file payloads are now captured by FormData; clear the scratch list so
  // composer chips reference nothing stale after sending.
  state.pendingAttachedFiles = [];

  state._abortController = new AbortController();

  const headers = {
    Accept: 'application/x-ndjson',
    'X-Requested-With': 'XMLHttpRequest',
  };
  const xsrf = getCpXsrfToken();
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;

  try {
    const res = await fetch('/cp/ai-generate/generate-stream', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      headers,
      signal: state._abortController.signal,
    });

    if (!res.ok || !res.body) {
      let msg = _trans('Generation failed.');
      try {
        const t = await res.text();
        const j = JSON.parse(t);
        msg = j.error || j.message || msg;
      } catch { /* keep default */ }
      state.generationError = msg;
      return;
    }

    const reader = res.body.pipeThrough(new TextDecoderStream()).getReader();
    let buffer = '';

    const processLine = (line) => {
      const trimmed = line.trim();
      if (!trimmed) return;
      let msg;
      try { msg = JSON.parse(trimmed); } catch { return; }
      handleStreamEvent(msg);
    };

    while (true) {
      const { done, value } = await reader.read();
      if (value) {
        buffer += value;
        const parts = buffer.split('\n');
        buffer = parts.pop() || '';
        parts.forEach(processLine);
      }
      if (done) break;
    }
    if (buffer.trim()) processLine(buffer);

    if (!state.generationError && state.entries.length === 0 && !state.plan) {
      state.generationError = _trans('Generation failed.');
    }
  } catch (e) {
    if (e?.name === 'AbortError') {
      // User-initiated stop is reflected in the UI directly — don't surface a banner.
      if (!state._userStopped) {
        state.generationError = _trans('Generation cancelled.');
      }
    } else {
      state.generationError = e?.message || _trans('Generation failed.');
    }
  } finally {
    const useBatchPoll = !!(state.generationBatchSessionId && !state.generationError);
    if (!useBatchPoll) {
      state.generating = false;
      state.planning = false;
    }
    state._abortController = null;
    const wasUserStopped = state._userStopped;
    state._userStopped = false;
    if (useBatchPoll) {
      scheduleGenerationBatchPoll();
    } else if (!wasUserStopped) {
      notifyBackgroundCompletionIfNeeded();
    } else {
      state._backgroundedAt = null;
    }
  }
}

function handleStreamEvent(msg) {
  switch (msg.type) {
    case 'planning':
      state.planning = true;
      state.generationPlanningStatus = 'planning';
      pushActivityLine(_trans('Taking a look at what you asked for…'), null);
      break;

    case 'keepalive':
      // NDJSON line to reset proxy idle timers — no UI update.
      break;

    case 'batch':
      if (msg.session_id) {
        state.generationBatchSessionId = msg.session_id;
        state.chatSessionId = msg.session_id; // durable across follow-up turns
        persistChatHistory();
        // Reset card list — the agentic planner will push entries one at a time
        // through the polling snapshot as it discovers them.
        state.plan = { entries: [], warnings: [] };
        state.entries = [];
      }
      break;

    case 'done':
      // Async batch always: actual lifecycle is owned by the polling loop.
      break;

    case 'error':
      state.generationError = msg.message || _trans('Generation failed.');
      break;
  }
}

export function cancelGeneration() {
  if (state._abortController) {
    try { state._abortController.abort(); } catch { /* ignore */ }
    state._abortController = null;
  }
}

/**
 * Stop a running generation. Always aborts the in-flight stream.
 *
 * Behavior depends on `keepReady`:
 *   - true  → keep entries already finished (status === READY); drop queued / drafting / failed
 *             along with the planning indicator. The plan summary remains intact so the user
 *             knows what was originally requested vs. what they kept.
 *   - false → wipe everything (entries, plan, errors). The composer reappears.
 *
 * Marks the cancellation as user-driven so we don't fire a "background completion" toast
 * in the launcher when the abort propagates through the fetch's catch block.
 */
export function stopGeneration({ keepReady = false } = {}) {
  // Suppress the about-to-fire AbortError → "Generation cancelled." path because
  // we are reflecting that intent in the UI ourselves (and don't want a bare
  // error banner overriding the kept cards).
  state._userStopped = true;
  cancelGeneration();

  const batchSid = state.generationBatchSessionId;
  if (batchSid) {
    clearGenerationBatchPoll();
    axios.post(`/cp/ai-generate/generate-cancel/${batchSid}`, {}, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).catch(() => {});
    state.generationBatchSessionId = null;
  }

  if (keepReady) {
    state.entries = state.entries.filter((e) => e.status === STATUS.READY);
    if (state.entries.length === 0) {
      // Nothing usable was kept — clear the plan to avoid an empty cards section.
      state.plan = null;
    }
  } else {
    state.entries = [];
    state.plan = null;
  }

  state.generating = false;
  state.planning = false;
  state.bulkSaving = false;
  state.generationError = null;
  state._backgroundedAt = null;
}

/**
 * Send a follow-up message in the current chat. Reuses the live chatSessionId,
 * keeps all prior turns + entry cards, and restarts the progress poll. The
 * server appends the user turn and re-runs the planner with full context.
 *
 * When the server session has expired (or a chat was restored from local
 * history without a live session), the conversation is reseeded from the
 * locally saved transcript via generate-resume — the chat continues
 * seamlessly instead of dying with "this chat has expired".
 */
export async function continueGeneration(prompt) {
  const text = (prompt || '').trim();
  if (state.generating || text.length < 1) return;
  if (!state.chatSessionId && state.transcript.length === 0) return;

  const files = pendingFilesForPrompt(text);

  // Turns as the resume endpoint expects them — captured BEFORE the optimistic
  // append so the new prompt isn't duplicated when the server re-appends it.
  const priorTurns = state.transcript
    .filter((t) => (t.text || '').trim() !== '')
    .map((t) => ({ role: t.role, text: t.text, kind: t.kind || null }));

  // Clear only the CURRENT-turn scratch — history + prior cards stay.
  state.generationError = null;
  state.plannerAnswer = null;
  state.operations = [];
  state.generating = true;
  state.planning = true;
  state.generationPlanningStatus = 'planning';
  state.pendingPrompt = '';
  state.activityLog = [];
  state.hasServerActivity = false;
  const mentionTitles = mentionTitlesForPrompt(text);

  // Optimistically show the user's message; the next poll's server transcript
  // (which also contains it) overwrites this idempotently.
  state.transcript = [...state.transcript, { role: 'user', text, entry_ids: [], kind: null, mention_titles: mentionTitles, attachments: attachmentsMeta(files) }];
  persistChatHistory();

  const headers = { 'X-Requested-With': 'XMLHttpRequest' };
  const xsrf = getCpXsrfToken();
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;

  // Attachments are scoped to this turn — once the request bodies are built
  // the file payloads are captured, so clear the scratch list immediately.
  const continueBody = new FormData();
  continueBody.append('prompt', text);
  appendAttachments(continueBody, files);

  const resumeBody = new FormData();
  resumeBody.append('prompt', text);
  resumeBody.append('transcript', JSON.stringify(priorTurns));
  appendAttachments(resumeBody, files);

  state.pendingAttachedFiles = [];

  try {
    let sessionId = null;

    if (state.chatSessionId) {
      try {
        const { data } = await axios.post(
          `/cp/ai-generate/generate-continue/${state.chatSessionId}`,
          continueBody,
          { headers, withCredentials: true },
        );
        sessionId = data.session_id || state.chatSessionId;
      } catch (e) {
        // 404 = session expired → fall through to resume; anything else is fatal.
        if (e?.response?.status !== 404 || priorTurns.length === 0) throw e;
      }
    }

    if (!sessionId) {
      const { data } = await axios.post(
        '/cp/ai-generate/generate-resume',
        resumeBody,
        { headers, withCredentials: true },
      );
      sessionId = data.session_id;
      state.chatSessionId = sessionId;
      persistChatHistory();
    }

    state.generationBatchSessionId = sessionId;
    scheduleGenerationBatchPoll();
  } catch (e) {
    state.generating = false;
    state.planning = false;
    state.generationError = e?.response?.data?.error || _trans('Generation failed.');
  }
}

/**
 * Chat-aware stop: abort the in-flight turn but KEEP the conversation history
 * and all prior entry cards (unlike stopGeneration, used by the full-page wizard).
 */
export function stopChatTurn() {
  state._userStopped = true;
  cancelGeneration();

  const sid = state.chatSessionId || state.generationBatchSessionId;
  if (sid) {
    clearGenerationBatchPoll();
    axios.post(`/cp/ai-generate/generate-cancel/${sid}`, {}, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).catch(() => {});
    state.generationBatchSessionId = null;
  }

  state.generating = false;
  state.planning = false;
  state.generationError = null;
  state._backgroundedAt = null;
}

// ─── Field schema cache ──────────────────────────────────────────────────

export async function fetchFieldPreview(collection, blueprint) {
  const key = `${collection}/${blueprint || ''}`;
  if (state.fieldPreviewCache[key]) return state.fieldPreviewCache[key];
  try {
    const { data } = await axios.get('/cp/ai-generate/blueprint-fields', {
      params: { collection, blueprint: blueprint || '' },
    });
    state.fieldPreviewCache[key] = data.fields;
    return data.fields;
  } catch (e) {
    return null;
  }
}

// ─── Saving ──────────────────────────────────────────────────────────────

/**
 * True when any entry OTHER than `exceptId` still holds generated content that
 * would be lost by a full-page navigation — i.e. a drafted-but-unsaved card
 * (READY) or one still being produced/saved (QUEUED / DRAFTING / SAVING).
 */
export function hasOtherPendingEntries(exceptId = null) {
  return state.entries.some(
    (e) => e.id !== exceptId
      && [STATUS.READY, STATUS.QUEUED, STATUS.DRAFTING, STATUS.SAVING].includes(e.status),
  );
}

/**
 * Open a saved entry's CP editor without destroying the rest of the batch.
 *
 * The batch lives in module-scoped memory, so navigating the current tab away
 * (window.location) wipes every other generated-but-unsaved entry. When other
 * entries are still pending we therefore open the editor in a NEW TAB and keep
 * the panel intact; only when nothing else would be lost do we navigate in place.
 */
function openEntryEditor(editUrl, cardId, isUpdate) {
  if (!editUrl) return;

  if (hasOtherPendingEntries(cardId)) {
    // NB: don't pass 'noopener' in the features string — that forces the return
    // value to null and defeats the pop-up-blocked check. Sever opener manually.
    const win = window.open(editUrl, '_blank');
    if (win) {
      try { win.opener = null; } catch { /* ignore */ }
    }
    if (_toast) {
      if (win) {
        _toast.success(isUpdate
          ? _trans('Entry updated — opened in a new tab. Your other entries are kept here.')
          : _trans('Entry created — opened in a new tab. Your other entries are kept here.'));
      } else {
        // Pop-up blocked: don't navigate (that would drop the batch). Point the
        // user at the card's Edit link, which also opens in a new tab.
        _toast.error(_trans('Pop-up blocked — use the “Edit” link on the card to open this entry.'));
      }
    }
    return;
  }

  if (_toast) {
    _toast.success(isUpdate ? _trans('Entry updated — opening editor.') : _trans('Entry created — opening editor.'));
  }
  setTimeout(() => { window.location.href = editUrl; }, 600);
}

export async function saveCard(id, mode) {
  const card = state.entries.find((e) => e.id === id);
  if (!card || card.status !== STATUS.READY) return;

  const isUpdate = card.operation === 'update' && !!card.entryId;
  const dataIsEmpty = !card.data || (typeof card.data === 'object' && Object.keys(card.data).length === 0);

  // Empty data on an update means the AI did not actually produce field changes.
  // Posting it would 422 with "no field changes were produced"; surface it here instead.
  if (dataIsEmpty) {
    if (_toast) {
      _toast.error(isUpdate
        ? _trans('No field changes were produced. Try a more specific prompt.')
        : _trans('No content to save.'));
    }
    return;
  }

  card.status = STATUS.SAVING;
  card.savingMode = mode;

  const payload = isUpdate
    ? { entry_id: card.entryId, data: card.data }
    : { collection: card.collection, blueprint: card.blueprint, data: card.data };

  try {
    const { data } = await axios.post('/cp/ai-generate/create-entry', payload);
    if (data.success) {
      card.status = STATUS.SAVED;
      card.savedEntry = { entry_id: data.entry_id, edit_url: data.edit_url, title: data.title };
      if (_toast) {
        if (mode === 'edit') {
          openEntryEditor(data.edit_url, card.id, isUpdate);
        } else {
          _toast.success(isUpdate ? _trans('Entry updated.') : _trans('Entry saved as draft.'));
        }
      }
    } else {
      card.status = STATUS.READY;
      if (_toast) _toast.error(isUpdate ? _trans('Entry update failed.') : _trans('Entry creation failed.'));
    }
  } catch (e) {
    card.status = STATUS.READY;
    if (_toast) _toast.error(e.response?.data?.error || (isUpdate ? _trans('Entry update failed.') : _trans('Entry creation failed.')));
  } finally {
    card.savingMode = null;
  }
}

export async function saveAll(mode) {
  if (state.bulkSaving) return;
  const targets = state.entries.filter((e) => e.status === STATUS.READY);
  if (targets.length === 0) return;

  state.bulkSaving = true;
  let saved = 0;
  let failed = 0;

  let updated = 0;

  let skipped = 0;

  for (const card of targets) {
    const isUpdate = card.operation === 'update' && !!card.entryId;
    const dataIsEmpty = !card.data || (typeof card.data === 'object' && Object.keys(card.data).length === 0);
    if (dataIsEmpty) {
      skipped += 1;
      continue;
    }
    const payload = isUpdate
      ? { entry_id: card.entryId, data: card.data }
      : { collection: card.collection, blueprint: card.blueprint, data: card.data };
    try {
      card.status = STATUS.SAVING;
      card.savingMode = mode;
      const { data } = await axios.post('/cp/ai-generate/create-entry', payload);
      if (data.success) {
        card.status = STATUS.SAVED;
        card.savedEntry = { entry_id: data.entry_id, edit_url: data.edit_url, title: data.title };
        if (isUpdate) updated += 1; else saved += 1;
      } else {
        card.status = STATUS.READY;
        failed += 1;
      }
    } catch {
      card.status = STATUS.READY;
      failed += 1;
    } finally {
      card.savingMode = null;
    }
  }

  state.bulkSaving = false;

  if (_toast) {
    if (saved > 0) _toast.success(_trans(':n entries saved as drafts.', { n: saved }));
    if (updated > 0) _toast.success(_trans(':n entries updated.', { n: updated }));
    if (failed > 0) _toast.error(_trans(':n entries failed to save.', { n: failed }));
    if (skipped > 0) _toast.error(_trans(':n entries skipped — no field changes produced.', { n: skipped }));
  }
}

// ─── Per-card actions ────────────────────────────────────────────────────

export async function retryCard(id) {
  const card = state.entries.find((e) => e.id === id);
  if (!card) return;

  card.status = STATUS.DRAFTING;
  card.tokenLength = 0;
  card._tokenScratch = '';
  card.streamKeysLogged = {};
  card._lastKeyScanTokenLen = 0;
  card.data = null;
  card.displayData = null;
  card.warnings = [];
  card.error = null;
  card.savedEntry = null;

  const formData = new FormData();
  formData.append('collection', card.collection);
  formData.append('blueprint', card.blueprint);
  formData.append('auto_resolve_target', '0');
  formData.append('prompt', card.prompt);

  try {
    const { data } = await axios.post('/cp/ai-generate/generate', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 300000,
    });
    if (data.success) {
      card.status = STATUS.READY;
      card.data = data.data;
      card.displayData = data.displayData || data.data;
      card.warnings = data.warnings || [];
    } else {
      card.status = STATUS.FAILED;
      card.error = data.error || _trans('Generation failed.');
    }
  } catch (e) {
    card.status = STATUS.FAILED;
    card.error = e.response?.data?.error || e.message || _trans('Generation failed.');
  }
}

export function discardCard(id) {
  state.entries = state.entries.filter((e) => e.id !== id);
  if (state.entries.length === 0) {
    reset();
  }
}

// ─── Reset ───────────────────────────────────────────────────────────────

/** Clear generation results but keep the composer (prompt + file). */
function resetGenerationOnly() {
  clearGenerationBatchPoll();
  state.generationBatchSessionId = null;
  state.generationPlanningStatus = null;
  state.plan = null;
  state.entries = [];
  state.generationError = null;
  state.plannerAnswer = null;
  state.operations = [];
  state.transcript = [];
  state.chatSessionId = null;
  state.currentChatId = null;
  state.planning = false;
  state.generating = false;
  state.bulkSaving = false;
  state.activityLog = [];
  state.hasServerActivity = false;
}

/** Clear everything except `active`; used when user clicks "New chat". */
export function reset() {
  resetGenerationOnly();
  state.mentionedTitles = [];
  state.pendingAttachedFiles = [];
  state._backgroundedAt = null;
}

// ─── Background-completion toast ─────────────────────────────────────────

function notifyBackgroundCompletionIfNeeded() {
  if (!state._backgroundedAt || state.active || !_toast) return;
  state._backgroundedAt = null;

  if (state.generationError) {
    _toast.error(_trans('AI agent: generation failed. Open the assistant to see why.'));
    return;
  }

  const ready = state.entries.filter((e) => e.status === STATUS.READY).length;
  const failed = state.entries.filter((e) => e.status === STATUS.FAILED).length;
  if (state.entries.length === 0) return;

  if (ready > 0 && failed === 0) {
    _toast.success(
      ready === 1
        ? _trans('AI agent: 1 entry is ready to review.')
        : _trans('AI agent: :n entries are ready to review.', { n: ready })
    );
  } else if (ready > 0 && failed > 0) {
    _toast.success(_trans('AI agent: :ready ready, :failed failed. Open the assistant to review.', { ready, failed }));
  } else if (failed > 0) {
    _toast.error(_trans('AI agent: generation failed for all entries.'));
  }
}

// ─── Helpers ─────────────────────────────────────────────────────────────

function getCpXsrfToken() {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
  return m ? decodeURIComponent(m[1]) : '';
}
