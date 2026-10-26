/**
 * Prompt templates registry.
 *
 * A template is a self-contained object that turns a few editor answers into a
 * rich natural-language prompt for the agent. The agent already owns the heavy
 * lifting (reading entries, translating, updating, vision) — templates only
 * encode intent + constraints, so the module stays small and maintainable.
 *
 * Add a template: create a file under a category folder and import it here.
 *
 * @typedef {Object} PromptTemplateQuestion
 * @property {string} id              Answer key.
 * @property {string} label           Localized question label.
 * @property {'text'|'choice'|'entry'} type
 * @property {boolean} [required]     Defaults to false.
 * @property {string} [placeholder]   For text questions.
 * @property {string[]} [options]     For choice questions.
 * @property {number} [default]       For choice questions (option index).
 * @property {string} [help]          Optional localized hint.
 *
 * @typedef {Object} PromptTemplate
 * @property {string} id
 * @property {string} category        Grouping key ('seo', ...).
 * @property {string} icon            Inline-SVG key (see TEMPLATE_ICONS in EntryGeneratorPage).
 * @property {string} title           Localized.
 * @property {string} summary         One-line outcome, localized.
 * @property {string} whenToUse       Localized.
 * @property {PromptTemplateQuestion[]} [questions]  Empty/missing = runs immediately.
 * @property {(answers: Record<string, *>, ctx: *) => string} buildPrompt
 */

import copyPageFromUrl from './content/copyPageFromUrl.js';
import optimizePage from './seo/optimizePage.js';
import metaDescriptions from './seo/metaDescriptions.js';
import altTextForPage from './seo/altTextForPage.js';

export const promptTemplates = [copyPageFromUrl, optimizePage, metaDescriptions, altTextForPage];

/** Category metadata in display order. */
export const templateCategories = [
  { id: 'content', label: 'Content' },
  { id: 'seo', label: 'SEO' },
];

/**
 * Templates grouped by category, preserving the order above.
 * @returns {Array<{category: string, label: string, templates: PromptTemplate[]}>}
 */
export function templatesGroupedByCategory() {
  return templateCategories
    .map((cat) => ({
      category: cat.id,
      label: cat.label,
      templates: promptTemplates.filter((t) => t.category === cat.id),
    }))
    .filter((g) => g.templates.length > 0);
}
