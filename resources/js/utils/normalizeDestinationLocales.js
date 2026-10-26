import { unref } from 'vue';

/**
 * Coerce publish / field values to a deduped list of locale strings.
 * Statamic (or JSON) may supply arrays, numeric-key objects, or wrapped refs.
 *
 * @param {unknown} raw
 * @returns {string[]}
 */
export function normalizeDestinationLocales(raw) {
  const v = unref(raw);
  if (v == null || v === '') {
    return [];
  }
  if (Array.isArray(v)) {
    return [...new Set(v.map((x) => (x == null ? '' : String(x))).filter(Boolean))];
  }
  if (typeof v === 'object') {
    const keys = Object.keys(v);
    if (keys.length && keys.every((k) => /^\d+$/.test(k))) {
      const ordered = [...keys].sort((a, b) => Number(a) - Number(b));
      return normalizeDestinationLocales(ordered.map((k) => v[k]));
    }
    const vals = Object.values(v);
    if (vals.length && vals.every((x) => typeof x === 'string')) {
      return [...new Set(vals.filter(Boolean))];
    }
    return [];
  }
  return [String(v)].filter(Boolean);
}
