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
 * Run search/replace across multiple files matched by a glob pattern.
 *
 * Applies the same search/replace operation to every file matching the
 * glob. Records edit history per file for individual undo support.
 */
final readonly class BatchReplaceTool
{
    public function __construct(
        private FileOperations $files,
        private EditHistory $history,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'batch_replace',
            description: 'Run a search/replace across multiple files matching a glob pattern (e.g. "src/**/*.php"). Returns the count of files changed and total replacements.',
            parameters: [
                new StringParameter('glob', 'Glob pattern to match files (e.g. "src/**/*.php", "*.json").', required: true),
                new StringParameter('search', 'Text or regex pattern to search for.', required: true),
                new StringParameter('replace', 'Replacement text.', required: true),
                new BoolParameter('is_regex', 'Treat search as a PCRE regex pattern (default: false).', required: false),
                new StringParameter('flags', 'PCRE modifier flags when is_regex is true (e.g. "msi").', required: false),
            ],
            callback: fn(array $input): ToolResult => $this->execute($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): ToolResult
    {
        $glob = trim((string) ($input['glob'] ?? ''));
        $search = (string) ($input['search'] ?? '');
        $replace = (string) ($input['replace'] ?? '');
        $isRegex = (bool) ($input['is_regex'] ?? false);
        $flags = (string) ($input['flags'] ?? '');

        if ($glob === '' || $search === '') {
            return ToolResult::error('Both "glob" and "search" parameters are required.');
        }

        $matchedFiles = $this->files->resolver()->resolveGlob($glob);

        if ($matchedFiles === []) {
            return ToolResult::success(json_encode([
                'files_changed' => 0,
                'replacements' => 0,
                'files' => [],
            ], JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        $filesChanged = 0;
        $totalReplacements = 0;
        $changedFileList = [];

        foreach ($matchedFiles as $absolutePath) {
            $original = @file_get_contents($absolutePath);
            if ($original === false) {
                continue;
            }

            $count = 0;

            if ($isRegex) {
                $pattern = '~' . $search . '~' . $flags;
                $modified = @preg_replace($pattern, $replace, $original, -1, $count);
                if ($modified === null) {
                    return ToolResult::error('Invalid regex pattern: ' . $search);
                }
            } else {
                $modified = str_replace($search, $replace, $original, $count);
            }

            if ($count > 0 && $modified !== $original) {
                $relativePath = $this->files->resolver()->makeRelative($absolutePath);
                $this->history->record($relativePath, 'batch_replace', $original, [
                    'search' => $search,
                    'is_regex' => $isRegex,
                    'replacements' => $count,
                ]);

                try {
                    $this->files->write($relativePath, $modified);
                } catch (CodeEditException) {
                    continue; // Skip files that can't be written
                }

                $filesChanged++;
                $totalReplacements += $count;
                $changedFileList[] = $relativePath;
            }
        }

        return ToolResult::success(json_encode([
            'files_changed' => $filesChanged,
            'replacements' => $totalReplacements,
            'files' => $changedFileList,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
