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
            'instructions' => 'You translate. The CMS does not call DeepL or Infomaniak for these tools. Read the entry, follow the glossary and style rules, translate every segment yourself, show preview_localization to the user, and call save_localization with confirmed true only after they agree. A localization stays linked to the source entry as its origin. To change the text of an existing entry in place, use preview_entry_edit and save_entry_edit the same way. To build a new entry from a goal, or restructure an existing one, call get_content_model first and follow its brief (block descriptions, when-to-use rules, site instructions, glossary, style rules), get the fields of the chosen blocks with get_block_schemas, then preview_compose_entry and save_compose_entry. To put an image from the chat on a page, call prepare_image_upload (into the container and folder of the image field, or where the user says), let the user upload it through the link, call check_image_upload for its path, then set the asset segment with preview_entry_edit and save_entry_edit. list_assets shows what is in a folder of the asset library, for existing images too. duplicate_entry copies an entry and set_published publishes or unpublishes one; call them without confirmed first and show the result. The user\'s Control Panel role decides what they may read, edit, duplicate and publish.',
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $level = self::TOOL_LEVELS[$name] ?? 'read';
        $result = ! ClaudeAccess::allows($this->bridge->actor(), $level) ? ['error' => 'forbidden', 'message' => ClaudeAccess::denial($level).' Nothing was changed.'] : match ($name) {
            'list_sites' => $this->bridge->listSites(),
            'find_entries' => $this->bridge->findEntries(
                (string) ($arguments['query'] ?? ''),
                isset($arguments['collection']) ? (string) $arguments['collection'] : null,
            ),
            'read_entry' => $this->bridge->readEntry((string) ($arguments['entry_id'] ?? '')),
            'preview_localization' => $this->bridge->previewLocalization(
                (string) ($arguments['entry_id'] ?? ''),
                (string) ($arguments['target_site'] ?? ''),
            ),
            'save_localization' => $this->bridge->saveLocalization(
                (string) ($arguments['confirmation_token'] ?? ''),
                is_array($arguments['translations'] ?? null) ? $arguments['translations'] : [],
                (bool) ($arguments['confirmed'] ?? false),
            ),
            'preview_entry_edit' => $this->bridge->previewEntryEdit(
                (string) ($arguments['entry_id'] ?? ''),
                is_array($arguments['edits'] ?? null) ? $arguments['edits'] : [],
                isset($arguments['slug']) ? (string) $arguments['slug'] : null,
            ),
            'save_entry_edit' => $this->bridge->saveEntryEdit(
                (string) ($arguments['confirmation_token'] ?? ''),
                (bool) ($arguments['confirmed'] ?? false),
            ),
            'duplicate_entry' => $this->bridge->duplicateEntry(
                (string) ($arguments['entry_id'] ?? ''),
                (bool) ($arguments['confirmed'] ?? false),
            ),
            'set_published' => $this->bridge->setPublished(
                (string) ($arguments['entry_id'] ?? ''),
                (bool) ($arguments['published'] ?? false),
                (bool) ($arguments['confirmed'] ?? false),
            ),
            'get_content_model' => $this->bridge->contentModel(
                isset($arguments['collection']) ? (string) $arguments['collection'] : null,
                isset($arguments['blueprint']) ? (string) $arguments['blueprint'] : null,
                isset($arguments['site']) ? (string) $arguments['site'] : null,
                isset($arguments['entry_id']) ? (string) $arguments['entry_id'] : null,
            ),
            'get_block_schemas' => $this->bridge->blockSchemas(
                isset($arguments['collection']) ? (string) $arguments['collection'] : null,
                isset($arguments['blueprint']) ? (string) $arguments['blueprint'] : null,
                isset($arguments['site']) ? (string) $arguments['site'] : null,
                isset($arguments['entry_id']) ? (string) $arguments['entry_id'] : null,
                is_array($arguments['blocks'] ?? null) ? $arguments['blocks'] : [],
            ),
            'preview_compose_entry' => $this->bridge->previewComposeEntry(
                is_array($arguments['data'] ?? null) ? $arguments['data'] : [],
                isset($arguments['collection']) ? (string) $arguments['collection'] : null,
                isset($arguments['blueprint']) ? (string) $arguments['blueprint'] : null,
                isset($arguments['site']) ? (string) $arguments['site'] : null,
                isset($arguments['entry_id']) ? (string) $arguments['entry_id'] : null,
                isset($arguments['mode']) ? (string) $arguments['mode'] : null,
                isset($arguments['parent_entry_id']) ? (string) $arguments['parent_entry_id'] : null,
            ),
            'save_compose_entry' => $this->bridge->saveComposeEntry(
                (string) ($arguments['confirmation_token'] ?? ''),
                (bool) ($arguments['confirmed'] ?? false),
            ),
            'list_assets' => $this->bridge->listAssets(
                isset($arguments['container']) ? (string) $arguments['container'] : null,
                isset($arguments['folder']) ? (string) $arguments['folder'] : null,
                isset($arguments['query']) ? (string) $arguments['query'] : null,
            ),
            'prepare_image_upload' => $this->bridge->prepareImageUpload(
                isset($arguments['container']) ? (string) $arguments['container'] : null,
                isset($arguments['folder']) ? (string) $arguments['folder'] : null,
                isset($arguments['filename']) ? (string) $arguments['filename'] : null,
                isset($arguments['alt']) ? (string) $arguments['alt'] : null,
            ),
            'check_image_upload' => $this->bridge->checkImageUpload((string) ($arguments['upload_id'] ?? '')),
            'read_glossary' => $this->bridge->readGlossary(),
            'save_glossary' => $this->bridge->saveGlossary(
                is_array($arguments['entries'] ?? null) ? $arguments['entries'] : [],
                (bool) ($arguments['confirmed'] ?? false),
            ),
            'read_style_rules' => $this->bridge->readStyleRules(),
            'save_style_rules' => $this->bridge->saveStyleRules(
                is_array($arguments['rules'] ?? null) ? $arguments['rules'] : [],
                (bool) ($arguments['confirmed'] ?? false),
            ),
            default => ['error' => 'unknown_tool', 'message' => 'Unknown tool '.$name.'.'],
        };

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
            $this->tool('read_entry', 'Read one entry\'s translatable segments, plus the glossary and style rules. Translate the segments yourself. Do not send them to DeepL or Infomaniak.', [
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
            $this->tool('preview_entry_edit', 'Change the text of an existing entry in place, in any language. Call it with only entry_id to list the entry\'s editable segments (text, plus toggles, choice fields, links and assets such as images, with their field label and block) and its slug. Call it again with edits and/or a new slug to get the before/after changes and a confirmation_token. A new slug changes the page\'s web address. Does not write anything. Refused when the user\'s Control Panel role cannot edit that entry.', [
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
            $this->tool('prepare_image_upload', 'Get a link to upload one image, such as a picture the user attached in the chat, into the asset library. Attachments cannot be passed to tools: post the attached file to the link yourself with code execution (curl), and only if that is not possible give the user the link to drop the image there. Then call check_image_upload. Uploading changes no page: set the image on an entry afterwards with preview_entry_edit. Refused when the user\'s Control Panel role cannot upload to that container.', [
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
