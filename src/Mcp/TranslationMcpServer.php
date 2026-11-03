<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use MotionWork\StatamicAiAssistant\Support\ClaudeAccess;

/**
 * Stdio MCP server (newline-delimited JSON-RPC) for Claude.
 *
 * Claude translates and edits. Tool calls only read the CMS and save the
 * strings Claude sends back.
 */
class TranslationMcpServer
{
    /**
     * The Claude access level each tool needs, besides the Control Panel
     * permission the bridge checks. Previews of a write need the write's level.
     */
    private const TOOL_LEVELS = [
        'preview_localization' => 'edit',
        'save_localization' => 'edit',
        'preview_entry_edit' => 'edit',
        'save_entry_edit' => 'edit',
        'duplicate_entry' => 'edit',
        'preview_compose_entry' => 'edit',
        'save_compose_entry' => 'edit',
        'prepare_image_upload' => 'edit',
        'check_image_upload' => 'edit',
        'preview_asset_alts' => 'edit',
        'save_asset_alts' => 'edit',
        'save_glossary' => 'edit',
        'save_style_rules' => 'edit',
        'set_published' => 'publish',
    ];

    public function __construct(private ClaudeTranslationBridge $bridge) {}

    public function bridge(): ClaudeTranslationBridge
    {
        return $this->bridge;
    }

    public function serve(): void
    {
        while (true) {
            $message = $this->readMessage();
            if ($message === null) {
                return;
            }

            $response = $this->dispatch($message);
            if ($response !== null) {
                $this->write($response);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    public function dispatch(array $message): ?array
    {
        $method = $message['method'] ?? '';
        $id = $message['id'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if (! array_key_exists('id', $message)) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize(is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '2024-11-05'),
                'ping' => new \stdClass,
                'tools/list' => ['tools' => array_values(array_filter(
                    $this->tools(),
                    fn ($tool) => ClaudeAccess::allows($this->bridge->actor(), self::TOOL_LEVELS[$tool['name']] ?? 'read'),
                ))],
                'tools/call' => $this->callTool($params),
                default => null,
            };
        } catch (\Throwable $e) {
            return $this->error($id, -32603, $e->getMessage());
        }

        if ($result === null) {
            return $this->error($id, -32601, 'Method not found: '.$method);
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readMessage(): ?array
    {
        $line = fgets(STDIN);
        if ($line === false) {
            return null;
        }

        if (stripos($line, 'Content-Length:') === 0) {
            $length = (int) trim(substr($line, strlen('Content-Length:')));
            while (($header = fgets(STDIN)) !== false && trim($header) !== '') {
                // Discard the rest of the header block.
            }
            $body = $length > 0 ? (string) fread(STDIN, $length) : '';
            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : ['jsonrpc' => '2.0', 'id' => null, 'method' => ''];
        }

        $trimmed = trim($line);
        if ($trimmed === '') {
            return $this->readMessage();
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : ['jsonrpc' => '2.0', 'id' => null, 'method' => ''];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(array $payload): void
    {
        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        fflush(STDOUT);
    }

    /**
     * @return array<string, mixed>
     */
    private function initialize(string $protocolVersion): array
    {
        return [
            'protocolVersion' => $protocolVersion !== '' ? $protocolVersion : '2024-11-05',
            'capabilities' => ['tools' => new \stdClass],
            'serverInfo' => [
                'name' => 'statamic-ai-assistant-translations',
                'version' => '3.x',
            ],
            'instructions' => 'You translate. The CMS does not call DeepL or Infomaniak for these tools. Read the entry, follow the glossary and style rules, translate every segment yourself, show preview_localization to the user, and call save_localization with confirmed true only after they agree. A localization stays linked to the source entry as its origin. To change the text of an existing entry in place, use preview_entry_edit and save_entry_edit the same way. To build a new entry from a goal, or restructure an existing one, call get_content_model first and follow its brief (block descriptions, when-to-use rules, site instructions, glossary, style rules), get the fields of the chosen blocks with get_block_schemas, then preview_compose_entry and save_compose_entry. To put a file from the chat on a page (an image, or another file the container allows, such as a GLB model), call prepare_image_upload (into the container and folder of the asset field, or where the user says), let the user upload it through the link, call check_image_upload for its path, then set the asset segment with preview_entry_edit and save_entry_edit. For alt text on library images, call review_images (you see each batch and write the strings yourself), then preview_asset_alts and save_asset_alts after the user agrees. list_assets shows what is in a folder of the asset library, for existing images too. duplicate_entry copies an entry and set_published publishes or unpublishes one; call them without confirmed first and show the result. The user\'s Control Panel role decides what they may read, edit, duplicate and publish.',
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $level = self::TOOL_LEVELS[$name] ?? 'read';

        $result = ClaudeAccess::allows($this->bridge->actor(), $level)
            ? $this->runTool($name, new McpArguments(is_array($params['arguments'] ?? null) ? $params['arguments'] : []))
            : ['error' => 'forbidden', 'message' => ClaudeAccess::denial($level).' Nothing was changed.'];

        if (isset($result['_content_blocks']) && is_array($result['_content_blocks'])) {
            return [
                'content' => $result['_content_blocks'],
                'isError' => isset($result['error']),
            ];
        }

        return [
            'content' => [[
                'type' => 'text',
                // Compact: only the model reads it, and indentation costs tokens.
                'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]],
            'isError' => isset($result['error']),
        ];
    }

    /**
     * Each tool: the bridge part that runs it (null: the bridge itself), the
     * method, and how its arguments are read, in parameter order (see
     * McpArguments::read()).
     */
    private const HANDLERS = [
        'list_sites' => [null, 'listSites', []],
        'find_entries' => [null, 'findEntries', ['string:query', 'optional:collection']],
        'read_entry' => ['localization', 'readEntry', ['string:entry_id']],
        'preview_localization' => ['localization', 'previewLocalization', ['string:entry_id', 'string:target_site']],
        'save_localization' => ['localization', 'saveLocalization', ['string:confirmation_token', 'list:translations', 'flag:confirmed']],
        'preview_entry_edit' => ['edits', 'previewEntryEdit', ['string:entry_id', 'list:edits', 'optional:slug']],
        'save_entry_edit' => ['edits', 'saveEntryEdit', ['string:confirmation_token', 'flag:confirmed']],
        'duplicate_entry' => ['lifecycle', 'duplicateEntry', ['string:entry_id', 'flag:confirmed']],
        'set_published' => ['lifecycle', 'setPublished', ['string:entry_id', 'flag:published', 'flag:confirmed']],
        'get_content_model' => ['brief', 'contentModel', ['optional:collection', 'optional:blueprint', 'optional:site', 'optional:entry_id']],
        'get_block_schemas' => ['brief', 'blockSchemas', ['optional:collection', 'optional:blueprint', 'optional:site', 'optional:entry_id', 'list:blocks']],
        'preview_compose_entry' => ['compose', 'previewComposeEntry', ['list:data', 'optional:collection', 'optional:blueprint', 'optional:site', 'optional:entry_id', 'optional:mode', 'optional:parent_entry_id']],
        'save_compose_entry' => ['composeSave', 'saveComposeEntry', ['string:confirmation_token', 'flag:confirmed']],
        'list_assets' => ['assets', 'listAssets', ['optional:container', 'optional:folder', 'optional:query']],
        'prepare_image_upload' => ['assets', 'prepareImageUpload', ['optional:container', 'optional:folder', 'optional:filename', 'optional:alt']],
        'check_image_upload' => ['assets', 'checkImageUpload', ['string:upload_id']],
        'review_images' => ['assetAlts', 'reviewImages', ['optional:container', 'optional:folder', 'list:locales', 'optional:scope', 'optional:cursor']],
        'preview_asset_alts' => ['assetAlts', 'previewAssetAlts', ['list:updates']],
        'save_asset_alts' => ['assetAlts', 'saveAssetAlts', ['string:confirmation_token', 'flag:confirmed']],
        'read_glossary' => ['glossary', 'readGlossary', []],
        'save_glossary' => ['glossary', 'saveGlossary', ['list:entries', 'flag:confirmed']],
        'read_style_rules' => ['glossary', 'readStyleRules', []],
        'save_style_rules' => ['glossary', 'saveStyleRules', ['list:rules', 'flag:confirmed']],
    ];

    /**
     * @return array<string, mixed>
     */
    private function runTool(string $name, McpArguments $args): array
    {
        if (! isset(self::HANDLERS[$name])) {
            return ['error' => 'unknown_tool', 'message' => 'Unknown tool '.$name.'.'];
        }

        [$part, $method, $params] = self::HANDLERS[$name];
        $target = $part === null ? $this->bridge : $this->bridge->{$part};

        return $target->{$method}(...array_map(fn (string $spec) => $args->read($spec), $params));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tools(): array
    {
        return [
            $this->tool('list_sites', 'List the sites (languages) this CMS can localize into.', [
                'type' => 'object',
                'properties' => new \stdClass,
            ]),
            $this->tool('find_entries', 'Find entries by title, slug, or id. Use this before translating.', [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Title, slug, or id. Empty returns the first entries.'],
                    'collection' => ['type' => 'string', 'description' => 'Optional collection handle.'],
                ],
            ]),
            $this->tool('read_entry', 'Read one entry\'s translatable segments, plus the glossary and style rules. Each text segment includes field, the blueprint handle it belongs to (seo_title, title, …). Translate the segments yourself. Do not send them to DeepL or Infomaniak.', [
                'type' => 'object',
                'properties' => [
                    'entry_id' => ['type' => 'string'],
                ],
                'required' => ['entry_id'],
            ]),
            $this->tool('preview_localization', 'Describe the localization that would be saved and return a confirmation_token. Does not write anything. Show the message to the user before saving.', [
                'type' => 'object',
                'properties' => [
                    'entry_id' => ['type' => 'string'],
                    'target_site' => ['type' => 'string', 'description' => 'Site handle, locale, or language name, such as en or English.'],
                ],
                'required' => ['entry_id', 'target_site'],
            ]),
            $this->tool('save_localization', 'Save a localization using translations you produced. Requires the confirmation_token from preview_localization and confirmed true after the user agrees. The CMS stores your strings and links the new entry to the source as its origin. It does not translate them again.', [
                'type' => 'object',
                'properties' => [
                    'confirmation_token' => ['type' => 'string'],
                    'confirmed' => ['type' => 'boolean'],
                    'translations' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string'],
                        'description' => 'Map of segment id to your translation. HTML segments must stay HTML.',
                    ],
                ],
                'required' => ['confirmation_token', 'confirmed', 'translations'],
            ]),
            $this->tool('preview_entry_edit', 'Change the text of an existing entry in place, in any language. Call it with only entry_id to list the entry\'s editable segments and its slug. A text segment has field set to the blueprint handle (seo_title, title, …). Toggles, choices, links and assets also carry their field label and block. Call it again with edits and/or a new slug to get the before/after changes and a confirmation_token. A new slug changes the page\'s web address. Does not write anything. Refused when the user\'s Control Panel role cannot edit that entry.', [
                'type' => 'object',
                'properties' => [
                    'entry_id' => ['type' => 'string'],
                    'edits' => [
                        'type' => 'object',
                        'description' => 'Map of segment id to the new value. Only these segments change. Text segments take a string; HTML segments must stay HTML. Segments with a kind: toggle takes true or false; choice takes one of its options, or a list when multiple is true; link takes a full URL, mailto:, tel:, a /path, a #anchor or entry::<id> from find_entries; asset takes the path of an asset in the segment\'s container (such as from check_image_upload), or a list when multiple is true; check the field label, since an asset field can also hold a video or file. null clears a choice, a link or an asset.',
                    ],
                    'slug' => ['type' => 'string', 'description' => 'Optional new slug. Formatted like the Control Panel slug field (lowercase, hyphens, umlauts spelled out).'],
                ],
                'required' => ['entry_id'],
            ]),
            $this->tool('save_entry_edit', 'Save the edit from preview_entry_edit. Requires its confirmation_token and confirmed true after the user agrees. Refused if the entry changed since the preview.', [
                'type' => 'object',
                'properties' => [
                    'confirmation_token' => ['type' => 'string'],
                    'confirmed' => ['type' => 'boolean'],
                ],
                'required' => ['confirmation_token', 'confirmed'],
            ]),
            $this->tool('duplicate_entry', 'Duplicate an entry the way the Control Panel does: an unpublished copy with "(Duplicated)" in the title and a new slug, in the same place in the page tree, plus copies of its localizations. Without confirmed true, nothing is written and the result describes the copy. Refused when the user\'s Control Panel role cannot create entries in that collection and site.', [
                'type' => 'object',
                'properties' => [
                    'entry_id' => ['type' => 'string'],
                    'confirmed' => ['type' => 'boolean'],
                ],
                'required' => ['entry_id'],
            ]),
            $this->tool('set_published', 'Publish or unpublish one entry in one language. Other languages keep their own state. Without confirmed true, nothing is written and the result describes the change. Refused when the user\'s Control Panel role cannot publish that entry.', [
                'type' => 'object',
                'properties' => [
                    'entry_id' => ['type' => 'string'],
                    'published' => ['type' => 'boolean', 'description' => 'true to publish, false to unpublish.'],
                    'confirmed' => ['type' => 'boolean'],
                ],
                'required' => ['entry_id', 'published'],
            ]),
            $this->tool('get_content_model', 'Learn how entries are built before composing or refactoring one. Without arguments: the collections, blueprints and sites the user may create entries in. With collection (and optionally blueprint, site): the brief for composing an entry there: the entry\'s own fields, a catalog of every content block with its editor-written description and when-to-use rules, site instructions, glossary and style rules. Choose blocks from the catalog, then get their fields with get_block_schemas. With entry_id: the same brief for that entry\'s blueprint, including the fields of the blocks already on it, plus its current content, for refactoring it.', [
                'type' => 'object',
                'properties' => [
                    'collection' => ['type' => 'string'],
                    'blueprint' => ['type' => 'string'],
                    'site' => ['type' => 'string', 'description' => 'Site handle, such as de. Defaults to the collection\'s first site.'],
                    'entry_id' => ['type' => 'string', 'description' => 'An entry to refactor.'],
                ],
            ]),
            $this->tool('get_block_schemas', 'The fields of the content blocks you chose from get_content_model\'s catalog, for the same collection (or entry_id). Call it once with every block you plan to use; ask again if you add another.', [
                'type' => 'object',
                'properties' => [
                    'blocks' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Block handles (type_handle) from set_layout_catalog.'],
                    'collection' => ['type' => 'string'],
                    'blueprint' => ['type' => 'string'],
                    'site' => ['type' => 'string'],
                    'entry_id' => ['type' => 'string'],
                ],
                'required' => ['blocks'],
            ]),
            $this->tool('preview_compose_entry', 'Check a composed entry and describe what would be saved: a new unpublished entry (collection, blueprint, site, optional parent_entry_id), or, with entry_id, a refactor of an existing entry either in place (mode "in_place") or as a new unpublished copy (mode "copy"); ask the user which. data is one JSON object in the format of get_content_model\'s brief. Returns an outline, warnings and a confirmation_token, or the validation problems to fix. Writes nothing.', [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'object', 'description' => 'The composed entry, keyed by field handle, in the brief\'s format.'],
                    'collection' => ['type' => 'string'],
                    'blueprint' => ['type' => 'string'],
                    'site' => ['type' => 'string'],
                    'parent_entry_id' => ['type' => 'string', 'description' => 'Optional parent page in the page tree for a new entry.'],
                    'entry_id' => ['type' => 'string', 'description' => 'The entry to refactor.'],
                    'mode' => ['type' => 'string', 'enum' => ['in_place', 'copy'], 'description' => 'Required with entry_id.'],
                ],
                'required' => ['data'],
            ]),
            $this->tool('save_compose_entry', 'Save the entry from preview_compose_entry. Requires its confirmation_token and confirmed true after the user agrees. New entries and copies are saved unpublished; publish them later with set_published.', [
                'type' => 'object',
                'properties' => [
                    'confirmation_token' => ['type' => 'string'],
                    'confirmed' => ['type' => 'boolean'],
                ],
                'required' => ['confirmation_token', 'confirmed'],
            ]),
            $this->tool('list_assets', 'List the asset library: the files (path, type, size, alt text; URL is url_prefix + path) and subfolders of one folder, or with query, the files anywhere below the folder whose name contains it. Use it when the user asks what is in a folder, or to find an existing image to set on an entry by its path. Read-only. Refused for containers the user\'s Control Panel role cannot view.', [
                'type' => 'object',
                'properties' => [
                    'container' => ['type' => 'string', 'description' => 'Asset container handle. Without it, the only container you may view, or the list of containers.'],
                    'folder' => ['type' => 'string', 'description' => 'Folder inside the container, such as illustrationen. Empty is the root.'],
                    'query' => ['type' => 'string', 'description' => 'Part of a file name to search for in the folder and its subfolders.'],
                ],
            ]),
            $this->tool('prepare_image_upload', 'Get a link to upload one file, such as a picture or a GLB model the user attached in the chat, into the asset library. The container decides which types are allowed, as in the Control Panel. Attachments cannot be passed to tools: post the attached file to the link yourself with code execution (curl), and only if that is not possible give the user the link to drop the file there. Then call check_image_upload. Uploading changes no page: set the path on an entry afterwards with preview_entry_edit or preview_compose_entry. Refused when the user\'s Control Panel role cannot upload to that container.', [
                'type' => 'object',
                'properties' => [
                    'container' => ['type' => 'string', 'description' => 'Asset container handle. Use the container of the asset segment you will set.'],
                    'folder' => ['type' => 'string', 'description' => 'Folder inside the container, such as team/2026. Use the folder the user names, else the asset segment\'s folder. Empty is the container root. Created when missing.'],
                    'filename' => ['type' => 'string', 'description' => 'Optional file name without extension, such as hero-bankkaution. Defaults to the uploaded file\'s name. An existing file is never overwritten.'],
                    'alt' => ['type' => 'string', 'description' => 'Alt text describing the image, in the site\'s language. Write it from what you see in the attachment.'],
                ],
            ]),
            $this->tool('check_image_upload', 'Whether the image for an upload link has arrived, and its stored path to use as an asset segment\'s value.', [
                'type' => 'object',
                'properties' => [
                    'upload_id' => ['type' => 'string'],
                ],
                'required' => ['upload_id'],
            ]),
            $this->tool('review_images', 'Show a batch of image assets so you can write alt text yourself (the CMS does not generate it). Returns the copy brief, locale-to-handle mapping, current meta, and inline JPEG previews. Use scope "missing" (default) or "all". Paginate with cursor until remaining is 0. Then preview_asset_alts and save_asset_alts. Read-only on assets; needs view permission on the container.', [
                'type' => 'object',
                'properties' => [
                    'container' => ['type' => 'string', 'description' => 'Asset container handle. Without it, the only container you may view, or the list of containers.'],
                    'folder' => ['type' => 'string', 'description' => 'Folder inside the container. Empty searches the whole container recursively.'],
                    'locales' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Languages to write, e.g. ["de","fr"]. Mapped to blueprint handles (alt, alt_fr, …).'],
                    'scope' => ['type' => 'string', 'enum' => ['missing', 'all'], 'description' => 'missing: only images with an empty target handle. all: every image.'],
                    'cursor' => ['type' => 'string', 'description' => 'Pagination cursor from the previous review_images response.'],
                ],
                'required' => ['locales'],
            ]),
            $this->tool('preview_asset_alts', 'Describe alt text metadata changes and return a confirmation_token. Pass updates as a list of {ref, values} using blueprint handles from review_images. Writes nothing. Refused when the role cannot edit assets on that container.', [
                'type' => 'object',
                'properties' => [
                    'updates' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'ref' => ['type' => 'string', 'description' => 'container::path'],
                                'values' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                            ],
                            'required' => ['ref', 'values'],
                        ],
                    ],
                ],
                'required' => ['updates'],
            ]),
            $this->tool('save_asset_alts', 'Save alt text from preview_asset_alts. Requires its confirmation_token and confirmed true after the user agrees. Refused if asset metadata changed since the preview.', [
                'type' => 'object',
                'properties' => [
                    'confirmation_token' => ['type' => 'string'],
                    'confirmed' => ['type' => 'boolean'],
                ],
                'required' => ['confirmation_token', 'confirmed'],
            ]),
            $this->tool('read_glossary', 'Read the glossary terms the translation should use. Not synced to DeepL by this connection.', [
                'type' => 'object',
                'properties' => new \stdClass,
            ]),
            $this->tool('save_glossary', 'Replace the whole local glossary. Needs the role permission to edit the glossary. Without confirmed true, nothing is written and the current and next tables are returned. Does not push to DeepL.', [
                'type' => 'object',
                'properties' => [
                    'confirmed' => ['type' => 'boolean'],
                    'entries' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'terms' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                            ],
                            'required' => ['terms'],
                        ],
                    ],
                ],
                'required' => ['entries'],
            ]),
            $this->tool('read_style_rules', 'Read the per-language style instructions to follow while translating.', [
                'type' => 'object',
                'properties' => new \stdClass,
            ]),
            $this->tool('save_style_rules', 'Replace the local style rules. Needs the role permission to edit the glossary. Pass the complete set. Without confirmed true, nothing is written. Does not push to DeepL.', [
                'type' => 'object',
                'properties' => [
                    'confirmed' => ['type' => 'boolean'],
                    'rules' => [
                        'type' => 'object',
                        'additionalProperties' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                ],
                'required' => ['rules'],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $schema): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $schema,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
