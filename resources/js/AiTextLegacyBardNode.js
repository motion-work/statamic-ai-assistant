/**
 * Statamic 5 Bard content may contain top-level nodes with type `aiText`.
 * Statamic 6 + this addon register the AI toolbar extension as `BardManagement` only,
 * so those legacy nodes were missing from the schema and broke the editor.
 *
 * This node is a pass-through container so existing content loads and can be edited;
 * output remains compatible with the rest of the Bard document.
 */
export const AiTextLegacyBardNode = ({ tiptap }) => {
  const { Node, mergeAttributes } = tiptap.core;

  return Node.create({
    name: 'aiText',

    group: 'block',

    /**
     * Legacy wrappers typically contained block content (paragraphs, headings, sets, …).
     * `block*` allows empty documents for edge cases.
     */
    content: 'block*',

    defining: true,

    parseHTML() {
      return [
        {
          tag: 'div[data-statamic-ai-text-legacy]',
        },
      ];
    },

    renderHTML({ HTMLAttributes }) {
      return [
        'div',
        mergeAttributes(HTMLAttributes, {
          'data-statamic-ai-text-legacy': '',
        }),
        0,
      ];
    },
  });
};
