/**
 * Turn raw failures (server messages, HTTP errors, network errors) into one
 * short, plain-language hint for the AI agent chat, plus the action that
 * helps. Pure: it returns translation keys; the component translates them.
 *
 * The raw text never reaches the chat bubble when it looks technical; it stays
 * in the transcript / "Copy conversation" for developers.
 */

export const CHAT_ERROR_ACTION = {
  RETRY: 'retry',
  NEW_CHAT: 'new-chat',
  NONE: null,
};

const GENERIC = 'That did not work, unfortunately. Please try again.';
const OFFLINE = 'No connection to the server. Check your internet connection and try again.';
const EXPIRED = 'This chat has expired. Start a new chat to continue.';

/** Ordered: the first rule whose pattern matches wins. */
const RULES = [
  {
    test: /network ?error|failed to fetch|load failed|err_internet|err_network|connection (?:refused|reset|failed)|could not resolve host/i,
    key: OFFLINE,
    action: CHAT_ERROR_ACTION.RETRY,
  },
  {
    test: /timed? ?out|timeout|curl error 28|operation too slow|took too long/i,
    key: 'The AI took too long. Try again, maybe with a shorter request.',
    action: CHAT_ERROR_ACTION.RETRY,
  },
  {
    test: /session expired|was not found|chat has expired|abgelaufen|expiré|scadut/i,
    key: EXPIRED,
    action: CHAT_ERROR_ACTION.NEW_CHAT,
  },
  {
    test: /output budget|max_tokens|whole output|ganzes ausgabe|reasoning/i,
    key: 'The answer got too long. Try a smaller or more specific request.',
    action: CHAT_ERROR_ACTION.RETRY,
  },
  {
    test: /credits|guthaben|spending limit|ausgabelimit|budget (?:for|für) (?:this|diese)|is used up|aufgebraucht|limite de dépenses|crédit|credito|402\b/i,
    key: 'The AI budget for this site is used up. Please tell your administrator.',
    action: CHAT_ERROR_ACTION.NONE,
  },
  {
    test: /free-models-per-day|daily limit for free|tageslimit für kostenlose|limite quotidienne des modèles gratuits|limite giornaliero dei modelli gratuiti/i,
    key: 'The daily limit for free models is reached. It continues tomorrow, or switch the free models off.',
    action: CHAT_ERROR_ACTION.NONE,
  },
  {
    test: /free models are busy|kostenlosen modelle sind gerade ausgelastet|modèles gratuits sont (?:actuellement )?occupés|modelli gratuiti sono (?:al momento )?occupati/i,
    key: 'The free models are busy right now. Try again in a moment.',
    action: CHAT_ERROR_ACTION.RETRY,
  },
  {
    test: /too many|rate.?limit|zu viele|trop de requêtes|troppe richieste|429\b/i,
    key: 'There are too many requests right now. Try again in a moment.',
    action: CHAT_ERROR_ACTION.RETRY,
  },
  {
    test: /moderation|content policy|declined this request|abgelehnt|refusé|rifiutat/i,
    key: 'The AI declined this request. Try phrasing it differently.',
    action: CHAT_ERROR_ACTION.NONE,
  },
  {
    test: /api key|api_key|token is invalid|not configured|nicht konfiguriert|queue|warteschlange|file d’attente|coda/i,
    key: 'The AI agent is not fully set up on this site yet. Please tell your administrator.',
    action: CHAT_ERROR_ACTION.NONE,
  },
  {
    test: /\b5\d\d\b|server error|internal server|bad gateway|service unavailable|unexpected response|no provider|provider/i,
    key: 'The server had a problem. Please try again.',
    action: CHAT_ERROR_ACTION.RETRY,
  },
];

/** Markers of a message an editor should not have to read. */
const TECHNICAL = /[{}[\]<>]|exception|stack|sqlstate|curl|http\/|https?:\/\/|\bline \d+|\.php|json|undefined|null\b|\(\d{3}\)|\b[45]\d\d\b|[A-Z][a-z]+\\[A-Z]/i;

/**
 * Whether a raw message is already plain enough to show as is (short, one
 * thought, no codes or markup), e.g. "No matching entry found for that title."
 *
 * @param {string} text
 */
export function isPlainMessage(text) {
  const t = String(text || '').trim();
  return t.length > 0 && t.length <= 220 && !TECHNICAL.test(t);
}

/**
 * @param {unknown} raw  Error text, an Error, or an axios error.
 * @param {{ status?: number|null, offline?: boolean }} [context]
 * @returns {{ key: string|null, text: string|null, action: string|null }}
 *   `key` is a translation key (translate it), `text` a plain message to show
 *   as is; exactly one of them is set.
 */
export function friendlyChatError(raw, context = {}) {
  const status = context.status ?? raw?.response?.status ?? null;
  const message = rawMessage(raw);

  if (context.offline) {
    return { key: OFFLINE, text: null, action: CHAT_ERROR_ACTION.RETRY };
  }
  if (status === 404) {
    return { key: EXPIRED, text: null, action: CHAT_ERROR_ACTION.NEW_CHAT };
  }

  const haystack = `${message} ${status ?? ''}`;
  const rule = RULES.find((r) => r.test.test(haystack));
  if (rule) {
    return { key: rule.key, text: null, action: rule.action };
  }
  if (isPlainMessage(message)) {
    return { key: null, text: message, action: CHAT_ERROR_ACTION.RETRY };
  }

  return { key: GENERIC, text: null, action: CHAT_ERROR_ACTION.RETRY };
}

function rawMessage(raw) {
  if (raw == null) return '';
  if (typeof raw === 'string') return raw;
  const data = raw?.response?.data;
  if (data && typeof data === 'object') {
    return String(data.error || data.message || raw.message || '');
  }

  return String(raw.message || raw);
}
