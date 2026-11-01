<?php

namespace MotionWork\StatamicAiAssistant\Controllers\Concerns;

use Illuminate\Http\Request;
use Statamic\Facades\User;

/**
 * Text of the documents attached to an AI agent request (one or several),
 * for the controllers that start a generation. Needs $this->documents.
 */
trait ReadsAttachments
{
    /**
     * Extract text content from an optional attachment uploaded with a chat
     * turn (initial or follow-up). Centralized so continue/resume mirror the
     * streaming start endpoint's handling.
     */
    protected function attachmentContentFromRequest(Request $request): ?string
    {
        $files = [];
        if ($single = $request->file('attachment')) {
            $files[] = $single;
        }
        $many = $request->file('attachments');
        foreach (is_array($many) ? $many : [] as $file) {
            if ($file) {
                $files[] = $file;
            }
        }

        // Each document is labeled with its client filename — that name equals
        // the "@file:<token>" the user references in the prompt, so the planner
        // can match mentions to contents. Unreadable files (e.g. scanned PDFs
        // with no embedded text) are still listed with the reason, never
        // dropped silently — otherwise the model invents content from the name.
        $parts = [];
        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            $result = $this->documents->extractUploadedFile($file);

            $parts[] = $result['content'] !== null
                ? "── Attachment: {$name} ──\n".trim($result['content'])
                : "── Attachment: {$name} (UNREADABLE — {$result['reason']}) ──\n"
                    .'This file was attached but its text could not be extracted. Tell the user this and do not guess its contents.';
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }
}
