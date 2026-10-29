<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

/**
 * Stdio MCP server (newline-delimited JSON-RPC) for Claude.
 *
 * Claude translates and edits. Tool calls only read the CMS and save the
 * strings Claude sends back.
 */
class TranslationMcpServer
{
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
                'tools/list' => ['tools' => $this->tools()],
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
            'instructions' => 'You translate. The CMS does not call DeepL or Infomaniak for these tools. Read the entry, follow the glossary and style rules, translate every segment yourself, show preview_localization to the user, and call save_localization with confirmed true only after they agree. A localization stays linked to the source entry as its origin. To change the text of an existing entry in place, use preview_entry_edit and save_entry_edit the same way; the user\'s Control Panel role decides which entries they may edit.',
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
        $result = match ($name) {
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
            ),
            'save_entry_edit' => $this->bridge->saveEntryEdit(
                (string) ($arguments['confirmation_token'] ?? ''),
                (bool) ($arguments['confirmed'] ?? false),
            ),
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
                'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
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
            $this->tool('preview_entry_edit', 'Change the text of an existing entry in place, in any language. Call it with only entry_id to list the entry\'s editable segments. Call it again with edits to get the before/after changes and a confirmation_token. Does not write anything. Refused when the user\'s Control Panel role cannot edit that entry.', [
                'type' => 'object',
                'properties' => [
                    'entry_id' => ['type' => 'string'],
                    'edits' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string'],
                        'description' => 'Map of segment id to the new text. Only these segments change. HTML segments must stay HTML.',
                    ],
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
