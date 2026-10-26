/**
 * SEO + Accessibility — Write descriptive alt text for a page's images.
 *
 * Reads the entry to find referenced image assets, calls analyze_image
 * (configured vision model) for those needing alt, then update_asset to write
 * descriptive alt in the page's locales.
 */
export default {
  id: 'seo-alt-text-for-page',
  category: 'seo',
  icon: 'image',
  title: 'Add missing alt text to a page’s images',
  summary: 'Describe each image with the vision model and write concise alt text in every locale.',
  whenToUse: 'Use when a page has images without alt text — good for SEO and accessibility.',
  questions: [
    {
      id: 'page',
      label: 'Which page should I check?',
      type: 'entry',
      required: true,
    },
    {
      id: 'scope',
      label: 'Which images should I process?',
      type: 'choice',
      options: ['Only images missing alt text', 'All images (overwrite existing alt)'],
      default: 0,
    },
  ],
  buildPrompt(answers) {
    const page = answers.page;
    const target = page?.title ? `the existing page @${page.title}` : 'the page I name below';
    const scope = answers.scope === 'All images (overwrite existing alt)'
      ? ' Process EVERY image referenced by the page, overwriting any existing alt text.'
      : ' Process ONLY images whose alt text is empty or missing.';

    return [
      `Improve accessibility and SEO of ${target} by writing descriptive alt text for its images.${scope}`,
      `Read the entry with read_entry to find the image assets referenced by the page.`,
      `For each image that needs alt text: call analyze_image to understand what the image shows, then use update_asset to write a concise, descriptive alt (≈100–125 characters, in every locale the page has) that describes the actual content — never decorative phrasing like “image of” or “picture of”.`,
      `Skip purely decorative images and note them. Do NOT change the image files themselves.`,
      `End with a summary of which assets you updated and which you skipped.`,
    ].filter(Boolean).join(' ');
  },
};
