<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeEdit\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\CodeEdit\Exception\CodeEditException;
use CoquiBot\Toolkits\CodeEdit\Storage\EditHistory;
use CoquiBot\Toolkits\CodeEdit\Support\FileOperations;

/**
 * Replace text in a file using plain string or PCRE regex matching.
 *
 * Supports single-file targeted search/replace with optional regex flags.
 * Records edit history for undo support.
 */
final readonly class ReplaceInFileTool
{
    public function __construct(
        private FileOperations $files,
        private EditHistory $history,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'replace_in_file',
            description: 'Replace text in a file. Supports plain string or PCRE regex search with optional flags. Returns the number of replacements made.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.', required: true),
                new StringParameter('search', 'Text or regex pattern to search for.', required: true),
                new StringParameter('replace', 'Replacement text. For regex, supports backreferences ($1, $2, etc.).', required: true),
                new BoolParameter('is_regex', 'Treat search as a PCRE regex pattern (default: false).', required: false),
                new StringParameter('flags', 'PCRE modifier flags when is_regex is true (e.g. "msi"). Default: "" (none).', required: false),
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
        $search = (string) ($input['search'] ?? '');
        $replace = (string) ($input['replace'] ?? '');
        $isRegex = (bool) ($input['is_regex'] ?? false);
        $flags = (string) ($input['flags'] ?? '');

        if ($path === '' || $search === '') {
            return ToolResult::error('Both "path" and "search" parameters are required.');
        }

        try {
            $original = $this->files->read($path);
        } catch (CodeEditException $e) {
            return ToolResult::error($e->getMessage());
        }

        $count = 0;

        if ($isRegex) {
            $pattern = '~' . $search . '~' . $flags;
            $result = @preg_replace($pattern, $replace, $original, -1, $count);
            if ($result === null) {
                return ToolResult::error('Invalid regex pattern: ' . $search);
            }
            $modified = $result;
        } else {
            $modified = str_replace($search, $replace, $original, $count);
        }

        if ($count > 0 && $modified !== $original) {
            $this->history->record($path, 'replace_in_file', $original, [
                'search' => $search,
                'is_regex' => $isRegex,
                'replacements' => $count,
            ]);

            try {
                $this->files->write($path, $modified);
            } catch (CodeEditException $e) {
                return ToolResult::error($e->getMessage());
            }
        }

        return ToolResult::success(json_encode([
            'path' => $path,
            'replacements' => $count,
            'changed' => $count > 0,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
