/**
 * Paragraphs that are visually empty (incl. ProseMirror-style <br class="...">).
 * Inner: optional whitespace, nbsp, or any <br ...> tag.
 */
const EMPTY_P_INNER = "(?:\\s|&nbsp;|<br[^>]*>)*";

const STRIP_LEADING_EMPTY_PS = new RegExp(
  `^(<p[^>]*>${EMPTY_P_INNER}<\\/p>\\s*)+`,
  "giu",
);
const STRIP_TRAILING_EMPTY_PS = new RegExp(
  `(\\s*<p[^>]*>${EMPTY_P_INNER}<\\/p>)+$`,
  "giu",
);

/**
 * Unwraps legacy <div data-statamic-ai-text-legacy> wrappers left over from
 * the Statamic 5 aiText Bard node, keeping the inner content.
 */
const LEGACY_AI_TEXT_WRAPPER = /<div\s+data-statamic-ai-text-legacy(?:="[^"]*")?[^>]*>([\s\S]*?)<\/div>/gi;

/**
 * Trims whitespace and strips leading/trailing empty <p> blocks from AI / DeepL HTML output.
 * Also unwraps legacy aiText wrapper divs.
 * @param {unknown} value
 * @returns {unknown}
 */
export function normalizeAiOutput(value) {
  if (value == null || typeof value !== "string") {
    return value;
  }

  let s = value.trim();
  if (s === "" || !s.includes("<")) {
    return s;
  }

  s = s.replace(LEGACY_AI_TEXT_WRAPPER, "$1");

  let prev;
  do {
    prev = s;
    s = s.replace(STRIP_LEADING_EMPTY_PS, "");
    s = s.replace(STRIP_TRAILING_EMPTY_PS, "");
    s = s.trim();
  } while (s !== prev);

  return s;
}

/**
 * Converts HTML from the API into plain text for single-line and textarea fields (no <p> tags).
 * Uses innerText so block elements become line breaks where appropriate.
 * @param {unknown} value
 * @returns {unknown}
 */
export function normalizeAiPlainTextOutput(value) {
  if (value == null || typeof value !== "string") {
    return value;
  }

  let s = normalizeAiOutput(value);
  if (s === "" || !s.includes("<")) {
    return s;
  }

  if (typeof document === "undefined") {
    return s.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();
  }

  const div = document.createElement("div");
  div.innerHTML = s;
  const text = div.innerText ?? div.textContent ?? "";
  return text
    .replace(/\r\n/g, "\n")
    .replace(/\n{3,}/g, "\n\n")
    .trim();
}
