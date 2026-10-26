/**
 * SEO — Refactor an existing page's content around a focus keyword.
 *
 * Reads the entry, then rewrites the whole body content + title/hero/SEO fields
 * to target the keyword while preserving facts and locales.
 *
 * NOTE: Strings are English source / translation keys. The UI localizes them
 * via this.__(...); buildPrompt works on English values so it is locale-stable.
 */
export default {
  id: 'seo-optimize-page',
  category: 'seo',
  icon: 'magnifying-glass',
  title: 'Optimize a page for SEO',
  summary: 'Refactor the whole page content and SEO fields around a focus keyword.',
  whenToUse: 'Use when a page exists but ranks poorly or lacks a clear keyword focus.',
  questions: [
    {
      id: 'page',
      label: 'Which page should I optimize?',
      type: 'entry',
      required: true,
    },
    {
      id: 'keyword',
      label: 'Focus keyword or phrase',
      type: 'text',
      required: false,
      placeholder: 'e.g. “wellness weekend Thunersee”',
      help: 'Leave empty and I will infer it from the page content.',
    },
  ],
  buildPrompt(answers) {
    const page = answers.page;
    const target = page?.title ? `the existing page @${page.title}` : 'the page I name below';
    const kw = answers.keyword
      ? ` Focus keyword: "${answers.keyword}". Weave it naturally throughout the content — title, hero lead, headings, and body — never stuff it.`
      : ' Infer the most fitting focus keyword from the page content and tell me which one you chose.';

    return [
      `Optimize ${target} for search engines around the focus keyword without changing its meaning, facts, prices, or dates.`,
      kw,
      `Read the entry first with read_entry, then use update_entry_job to refactor the WHOLE page — not just metadata:`,
      `- rewrite the main body / section content so it reads naturally around the focus keyword, improving topical depth and clarity (rephrase, expand thin sections, tighten fluff),`,
      `- write an SEO-friendly title (≤60 characters) and a concise hero lead that naturally contains the focus keyword,`,
      `- fill seo_title and seo_description (meta description ≤155 characters) while preserving the page's intent,`,
      `- improve heading hierarchy and structure where the blueprint allows it, ensuring headings reflect the keyword and sub-topics.`,
      `Preserve every fact, number, price, date and the page's overall intent — do not invent or drop content.`,
      `Keep every change in the page's own locales — translate yourself, do not just transliterate.`,
      `End with a short summary of what you changed and which keyword you targeted.`,
    ].filter(Boolean).join(' ');
  },
};
