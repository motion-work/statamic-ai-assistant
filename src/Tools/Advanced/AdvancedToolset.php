<?php

namespace MotionWork\StatamicAiAssistant\Tools\Advanced;

use MotionWork\StatamicAiAssistant\Tools\ChatTool;

/**
 * The structural tool pack, gated by the `advanced_tools` access feature
 * (AgentAccess) and the `advanced_tools` config master switch. Registered as
 * one unit so the planner wiring stays a single conditional.
 */
class AdvancedToolset
{
    /**
     * Whether the pack is available for a batch session: the requesting user's
     * grant was resolved at request time and stored on the session (the planner
     * runs in a queue with no authenticated user), and a site can hard-disable
     * the pack via config regardless of grants.
     *
     * @param  array<string, mixed>  $session
     */
    public static function enabledForSession(array $session): bool
    {
        return (bool) ($session['advanced_tools'] ?? false)
            && (bool) config('statamic-ai-assistant.advanced_tools', true);
    }

    /**
     * @return array<int, ChatTool>
     */
    public static function tools(): array
    {
        return [
            new ListBlueprintsTool,
            new ListFieldsetsTool,
            new ReadBlueprintTool,
            new CreateBlueprintTool,
            new UpdateBlueprintTool,
            new CreateFieldsetTool,
            new AddComponentSetTool,
            new CreateCollectionTool,
            new ConfigureCollectionTool,
            new CreateTaxonomyTool,
            new CreateTermsTool,
        ];
    }

    /**
     * Names of the tools that WRITE structure. The planner counts their
     * successful calls so a structural-only run completes as a success even
     * though no entry jobs were dispatched.
     *
     * @return array<int, string>
     */
    public static function writeToolNames(): array
    {
        return ['create_blueprint', 'update_blueprint', 'create_fieldset', 'add_component_set', 'create_collection', 'configure_collection', 'create_taxonomy'];
    }

    /**
     * The AVAILABLE TOOLS lines + workflow rule appended to the planner system
     * prompt when (and only when) the pack is registered.
     */
    public static function plannerPromptBlock(): string
    {
        return "- `list_blueprints` / `read_blueprint`: inspect which blueprints exist and their fields. read_blueprint also returns the raw `structure` (tabs + fieldset imports) — the site's conventions.\n"
            ."- `list_fieldsets`: list the site's reusable fieldsets (hero, seo, ...) and their fields.\n"
            ."- `create_collection`: create a new collection (then create a blueprint for it).\n"
            ."- `configure_collection`: change an existing collection's settings (title, route, dated, taxonomies, ...).\n"
            ."- `create_taxonomy`: create a new taxonomy (the container only). Then add its terms with create_terms, and attach it to a collection via configure_collection.\n"
            ."- `create_terms`: add terms (categories/tags) to an EXISTING taxonomy — call it after create_taxonomy, or on a taxonomy that already exists. Existing terms are skipped, so it is safe to re-run. Titles land in the default locale.\n"
            ."- `create_blueprint` / `update_blueprint`: create a blueprint or add/modify fields on one. Updates MERGE by default; read_blueprint first. "
            ."Both also handle FORM blueprints (contact forms etc.) via the `form` parameter — when the user says \"form\", it is almost always a FORM blueprint (listed under `forms` in list_blueprints), NOT a page/collection blueprint. NEVER add form fields to a page blueprint as a substitute.\n"
            ."- `create_fieldset`: create a new reusable fieldset (same field format as create_blueprint).\n"
            ."- `add_component_set`: register a component fieldset as a set of the container fieldset's components field, so editors can use it in entries.\n"
            ."\nSTRUCTURAL CHANGES RULE: the tools above change the site's STRUCTURE and apply immediately — there is no draft or review step. "
            ."Use them ONLY when the newest user message explicitly asks for a structural change (\"create a collection\", \"add a field to X\", \"new taxonomy\"). "
            ."NEVER create or modify collections, blueprints, taxonomies or fields on your own initiative to make a content task fit — if needed structure is missing, "
            ."say what is missing in your summary instead. After creating a collection + blueprint, you may create entries in it in the same run if the user asked for that.\n"
            ."\nBLUEPRINT CONVENTIONS: before creating a blueprint, call list_fieldsets AND read_blueprint on one existing blueprint of a similar collection to learn this site's structure. "
            ."When the user names an existing shared block (a hero, SEO fields, meta, ...) or a fieldset covers it, REFERENCE it with an {\"import\": \"<fieldset_handle>\"} row — do NOT recreate its fields inline. "
            ."Define inline fields only for genuinely new, content-specific needs. A structural change that only ADDS what the user asked for is correct; a blueprint that duplicates an existing fieldset is wrong.\n"
            ."\nBLUEPRINT LAYOUT: create_blueprint's `tabs` parameter is the default — mirror the tab/section layout of the blueprint you read with read_blueprint so editors get the same editing experience everywhere. "
            ."When the reference blueprint is tabbed, NEVER dump everything into one flat `fields` list (that lands every field, including SEO, in a single tab). "
            ."The usual shape: a \"main\" tab (title + hero section, then a content section with the components import), an \"seo\" tab importing the seo fieldset, and a \"sidebar\" tab (slug, date, per-page settings) — but always follow what the reference blueprint actually does.\n"
            ."\nFIELDSET ROLES: fieldsets serve two purposes on these sites. (1) Shared groups (heros, SEO, ...) imported directly into blueprints. "
            ."(2) Page-builder COMPONENTS: fieldsets (frequently named component_*) registered as sets of a container fieldset — the one marked component_container in list_fieldsets, whose sets each import one component fieldset. "
            ."When the user asks for a new component (their name for it may include \"component\", or the site's fieldsets follow the component_* naming), do BOTH steps: create_fieldset, then add_component_set into the container — a component fieldset that is not registered is invisible to editors. "
            ."Match the site's naming and set display style. If several containers exist and the target is unclear, ask via propose_plan.\n";
    }
}
