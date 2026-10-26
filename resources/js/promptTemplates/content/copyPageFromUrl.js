/**
 * Content — Copy a page's content from a source URL into a new or existing entry.
 *
 * Uses fetch_page_content + faithful reproduction rules; save_remote_image for
 * imagery. The agent picks collection/blueprint or updates a named entry.
 */
export default {
  id: 'content-copy-page-from-url',
  category: 'content',
  icon: 'link',
  title: 'Copy a page from a URL',
  summary: 'Fetch a source URL and reproduce its title, text and images as faithfully as possible.',
  whenToUse: 'Use when you want a new entry (or an update) that mirrors an existing web page.',
  questions: [
    {
      id: 'url',
      label: 'Source URL',
      type: 'text',
      required: true,
      placeholder: 'https://www.example.com/about-us',
      help: 'The page whose content should be copied — text and images.',
    },
    {
      id: 'page',
      label: 'Update an existing page instead?',
      type: 'entry',
      required: false,
      help: 'Leave empty to create a new entry. Pick a page to overwrite it with the fetched content.',
    },
  ],
  buildPrompt(answers) {
    const url = String(answers.url || '').trim();
    const page = answers.page;
    const action = page?.title
      ? `Update the existing page @${page.title}`
      : 'Create a new entry (pick the best collection and blueprint from the catalog — prefer `pages` when it fits)';

    return [
      `${action} by copying the content from this source URL exactly: ${url}`,
      `First call fetch_page_content on that URL. If the returned page is a listing or index (teasers, cards, "read more" links), identify the specific item that matches the user's intent and call fetch_page_content again on that item's detail page URL before writing anything.`,
      `Reproduce the fetched content FAITHFULLY and COMPLETELY:`,
      `- Set the entry title to the source page's main heading verbatim (use the fetched h1 / "Page title (H1)" line — do not paraphrase, shorten, or invent a new title),`,
      `- Keep the same sections, headings, wording, facts, numbers, prices, dates and order; do NOT summarize, condense, omit sections, reorder, translate, or invent content,`,
      `- Map ALL source content across the entry's blueprint fields; when a piece has no dedicated field, put it in the main rich-text/body field rather than dropping it.`,
      `For images on the source page: call save_remote_image for each image you want on the entry (hero/lead image first) so they attach to the entry's image fields — never place image URLs inside text or rich-text fields.`,
      page?.title
        ? `Use update_entry_job with a self-contained prompt that includes the fetched content and constraints above.`
        : `Use create_entry_job with a self-contained prompt that includes the source URL, the fetched content, and the constraints above.`,
      `End with a short summary of what you created or updated and which URL you used.`,
    ].filter(Boolean).join(' ');
  },
};
