<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeEdit\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\CodeEdit\Exception\CodeEditException;
use CoquiBot\Toolkits\CodeEdit\Storage\EditHistory;
use CoquiBot\Toolkits\CodeEdit\Support\FileOperations;

/**
 * Append content to the end of a file.
 *
 * Creates the file if it does not exist (directory must already exist).
 * Records edit history for undo support.
 */
final readonly class AppendToFileTool
{
    public function __construct(
        private FileOperations $files,
        private EditHistory $history,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'append_to_file',
            description: 'Append text to the end of a file. Creates the file if it does not exist (parent directory must exist). Returns the number of bytes appended.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.', required: true),
                new StringParameter('content', 'Content to append to the file.', required: true),
            ],
            callback: fn(array $input): ToolResult => $this->execute($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): ToolResult
    {
        $path = trim((string) ($input['path'] ?? ''));
        $content = (string) ($input['content'] ?? '');

        if ($path === '') {
            return ToolResult::error('The "path" parameter is required.');
        }

        if ($content === '') {
            return ToolResult::error('The "content" parameter is required.');
        }

        // Record the original state before appending
        try {
            if ($this->files->exists($path)) {
                $original = $this->files->read($path);
                $this->history->record($path, 'append_to_file', $original, [
                    'appended_bytes' => strlen($content),
                ]);
            } else {
                // New file — record empty original for undo (will effectively delete the file content)
                $this->history->record($path, 'append_to_file', '', [
                    'appended_bytes' => strlen($content),
                    'created' => true,
                ]);
            }
        } catch (CodeEditException $e) {
            return ToolResult::error($e->getMessage());
        }

        try {
            $bytes = $this->files->append($path, $content);
        } catch (CodeEditException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::success(json_encode([
            'path' => $path,
            'appended_bytes' => $bytes,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
