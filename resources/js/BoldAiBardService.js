import axios from "axios";
import { applyBardWriteContent } from "./utils/applyBardWriteContent";
import { normalizeAiOutput } from "./utils/normalizeAiOutput";

export const BoldAiBardService = ({ tiptap }) => {
  const { Extension } = tiptap.core;

  return Extension.create({
    name: "BardManagement",

    addCommands() {
      return {
        refactorHTMLWithAi: (attrs) => async () => {
          const initialHTMLText = attrs.text;

          try {
            const response = await axios.post("/cp/promptHtmlrefactor", {
              text: initialHTMLText,
              prompt: attrs.refactorPrompt,
            });

            return normalizeAiOutput(response.data.content ?? "");
          } catch (error) {
            console.error("Error refactoring combined content:", error);
            return initialHTMLText;
          }
        },

        WriteInBard: (content) => ({ editor }) => {
          const html =
            typeof content === "string" ? normalizeAiOutput(content) : content;
          applyBardWriteContent(editor, () => {
            editor.chain().focus().setContent(html).run();
          });
          return true;
        },

        getBardContent: () => ({ editor }) => {
          return normalizeAiOutput(editor.getHTML());
        },
      };
    },
  });
};
