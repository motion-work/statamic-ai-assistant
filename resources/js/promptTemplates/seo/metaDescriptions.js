/**
 * SEO — Generate SEO title + meta description across all locales of a page.
 *
 * Reads the entry, writes character-limited seo_title / seo_description for
 * every locale the entry has, self-translating. Solves the multilingual SEO
 * chore (DE/FR/EN/IT) every Swiss site has.
 */
export default {
  id: 'seo-meta-descriptions',
  category: 'seo',
  icon: 'text-align-left',
  title: 'Write SEO title & meta description (all locales)',
  summary: 'Generate concise, character-limited SEO title and meta description for every locale.',
  whenToUse: 'Use when a page is missing meta tags or they were written in only one language.',
  questions: [
    {
      id: 'page',
      label: 'Which page should I write them for?',
      type: 'entry',
      required: true,
    },
    {
      id: 'length',
      label: 'Meta description length',
      type: 'choice',
      options: ['Concise (≈120 chars)', 'Standard (≈155 chars)', 'Long (≈180 chars)'],
      default: 1,
    },
  ],
  buildPrompt(answers) {
    const page = answers.page;
    const target = page?.title ? `the existing page @${page.title}` : 'the page I name below';
    const lengthHint = ({
      'Concise (≈120 chars)': '≈120 characters',
      'Standard (≈155 chars)': '≈155 characters',
      'Long (≈180 chars)': '≈180 characters',
    })[answers.length] || '≈155 characters';

    return [
      `Generate an SEO title and meta description for ${target}, for every locale the entry has.`,
      `Read the entry with read_entry first — base the text on the page's own content per locale. Then use update_entry_job to write, per locale:`,
      `- seo_title: ≤60 characters, compelling and accurate,`,
      `- seo_description: ${lengthHint}, faithful to the page content, ending with a soft call-to-action where it fits.`,
      `Translate yourself faithfully — do not just transliterate one locale into the others. Preserve names, places, prices, and facts.`,
      `Do NOT rewrite the page body. End with a short summary listing the values you wrote per locale.`,
    ].filter(Boolean).join(' ');
  },
};
