import { setBardTiptap } from "./utils/bardAi";

/**
 * Bard extension for the AI and translate toolbar buttons. Its only job is to
 * hand Statamic's tiptap bundle to utils/bardAi.js, which reads and writes
 * the Bard document (selection, text runs around sets, undo).
 */
export const AiBardService = ({ tiptap }) => {
  setBardTiptap(tiptap);

  return tiptap.core.Extension.create({
    name: "BardManagement",
  });
};
