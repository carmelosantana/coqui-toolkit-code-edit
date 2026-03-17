<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeEdit\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\CodeEdit\Exception\CodeEditException;
use CoquiBot\Toolkits\CodeEdit\Storage\EditHistory;
use CoquiBot\Toolkits\CodeEdit\Support\FileOperations;

/**
 * Overwrite a specific range of lines in a file.
 *
 * Replaces lines in [from, to] (1-based inclusive) with the provided
 * content. Complement of extract_lines — read a range, modify, write back.
 */
final readonly class WriteLinesTool
{
    public function __construct(
        private FileOperations $files,
        private EditHistory $history,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'write_lines',
            description: 'Overwrite a range of lines in a file with new content. Lines are 1-based inclusive. The specified range [from, to] is replaced with the provided content (which can be more or fewer lines).',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.', required: true),
                new NumberParameter('from', 'Start line number (1-based, inclusive).', required: true, integer: true, minimum: 1),
                new NumberParameter('to', 'End line number (1-based, inclusive).', required: true, integer: true, minimum: 1),
                new StringParameter('content', 'New content to replace the specified line range with.', required: true),
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
        $from = (int) ($input['from'] ?? 0);
        $to = (int) ($input['to'] ?? 0);
        $content = (string) ($input['content'] ?? '');

        if ($path === '') {
            return ToolResult::error('The "path" parameter is required.');
        }

        if ($from < 1 || $to < 1 || $to < $from) {
            return ToolResult::error(sprintf('Invalid line range: %d–%d (from must be ≥ 1 and ≤ to).', $from, $to));
        }

        try {
            $data = $this->files->readLines($path);
        } catch (CodeEditException $e) {
            return ToolResult::error($e->getMessage());
        }

        /** @var string[] $lines */
        $lines = $data['lines'];
        /** @var non-empty-string $eol */
        $eol = $data['eol'];
        $totalLines = count($lines);

        $fromIdx = $from - 1;
        $toIdx = min($to - 1, $totalLines - 1);

        if ($fromIdx >= $totalLines) {
            return ToolResult::error(sprintf('Start line %d exceeds file length (%d lines).', $from, $totalLines));
        }

        $linesReplaced = $toIdx - $fromIdx + 1;
        $newLines = explode($eol, $content);

        $original = $this->files->read($path);
        $this->history->record($path, 'write_lines', $original, [
            'from' => $from,
            'to' => $to,
            'lines_replaced' => $linesReplaced,
            'new_line_count' => count($newLines),
        ]);

        array_splice($lines, $fromIdx, $linesReplaced, $newLines);

        try {
            $this->files->writeLines($path, $lines, $eol);
        } catch (CodeEditException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::success(json_encode([
            'path' => $path,
            'lines_replaced' => $linesReplaced,
            'total_lines_after' => count($lines),
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
