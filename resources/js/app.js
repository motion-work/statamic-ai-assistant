import AiTextarea from "./components/AiTextarea.vue";
import AiText from "./components/AiText.vue";
import BardAiButton from "./components/BardAiButton.vue";
import BardTranslationButton from "./components/BardTranslationButton.vue";
import TranslationPage from "./components/TranslationPage.vue";
import TranslationActionPreflightFieldtype from "./components/TranslationActionPreflightFieldtype.vue";
import TranslationTargetLanguagesFieldtype from "./components/TranslationTargetLanguagesFieldtype.vue";
import TranslationProgress from "./components/TranslationProgress.vue";
import EntryGeneratorPage from "./components/EntryGeneratorPage.vue";
import EntryGeneratorCpLauncher from "./components/EntryGeneratorCpLauncher.vue";
import SetHintsSettingsPage from "./components/SetHintsSettingsPage.vue";
import TranslationGlossaryPage from "./components/TranslationGlossaryPage.vue";
import { AiTextLegacyBardNode } from "./AiTextLegacyBardNode";
import { BoldAiBardService } from "./BoldAiBardService";
import { TranslationInfoDisplay } from "./utils/TranslationInfoDisplay";

/**
 * Resolve CP JSON translations from Statamic.initialConfig (same source as the core translator).
 * Avoids importing Statamic's translator singleton, which would be a separate empty instance in this bundle.
 */
function cpTranslate(key, replacements = {}, translations) {
  const t = translations || {};
  let message =
    t[`*.${key}`] ||
    t[key] ||
    t[`statamic::${key}`] ||
    t[`statamic::messages.${key}`] ||
    key;
  const opts = replacements || {};
  for (const replace in opts) {
    message = String(message).split(":" + replace).join(opts[replace]);
  }
  return message;
}

Statamic.booting((statamic) => {
  const translations = statamic.initialConfig?.translations || {};
  Statamic.$components.register("ai_textarea-fieldtype", AiTextarea);

  Statamic.$components.register("ai_text-fieldtype", AiText);

  Statamic.$components.register("translation-page", TranslationPage);

  Statamic.$components.register("translation-progress", TranslationProgress);

  Statamic.$components.register("entry-generator-page", EntryGeneratorPage);

  Statamic.$components.register("entry-generator-cp-launcher", EntryGeneratorCpLauncher);

  Statamic.$components.register("set-hints-settings-page", SetHintsSettingsPage);

  Statamic.$components.register("translation-glossary-page", TranslationGlossaryPage);

  Statamic.$components.register(
    "translation_action_preflight-fieldtype",
    TranslationActionPreflightFieldtype
  );

  Statamic.$components.register(
    "translation_target_languages-fieldtype",
    TranslationTargetLanguagesFieldtype
  );

  Statamic.$bard.addExtension(BoldAiBardService);
  Statamic.$bard.addExtension(AiTextLegacyBardNode);

  Statamic.$bard.buttons((buttons) => {
    buttons.push({
      name: "BardManagement",
      text: cpTranslate("AI assistant", {}, translations),
      component: BardAiButton,
    });

    if (Statamic.$config.get("translationsActiv")) {
      buttons.push({
        name: "BardManagement",
        text: cpTranslate("AI translate", {}, translations),
        component: BardTranslationButton,
      });
    }
  });
});

// Initialize translation-related services
Statamic.booted(() => {
  const translationInfoDisplay = new TranslationInfoDisplay();
  translationInfoDisplay.init();

  if (Statamic.$config.get("entryGeneratorEnabled")) {
    Statamic.$components.append("entry-generator-cp-launcher", { props: {} });
  }
});

// Define the callback function for custom actions messages
Statamic.$callbacks.add('errorCallback', function (errorMessage) {
  Statamic.$toast.error(errorMessage);
});

Statamic.$callbacks.add('successCallback', function (redirectUrl, successMessage) {
  Statamic.$toast.success(successMessage);
  setTimeout(() => {
    window.location.href = redirectUrl;
  }, 1500);
});
