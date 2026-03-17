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
 * Remove a range of lines from a file.
 *
 * Removes lines in the range [from, to] (1-based inclusive).
 * Records edit history for undo support.
 */
final readonly class RemoveLinesTool
{
    public function __construct(
        private FileOperations $files,
        private EditHistory $history,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'remove_lines',
            description: 'Remove a range of lines from a file. Lines are 1-based inclusive. Returns the number of lines removed and total lines remaining.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.', required: true),
                new NumberParameter('from', 'Start line number (1-based, inclusive).', required: true, integer: true, minimum: 1),
                new NumberParameter('to', 'End line number (1-based, inclusive).', required: true, integer: true, minimum: 1),
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
        /** @var string $eol */
        $eol = $data['eol'];
        $totalLines = count($lines);

        // Clamp range to actual file length
        $fromIdx = $from - 1;
        $toIdx = min($to - 1, $totalLines - 1);

        if ($fromIdx >= $totalLines) {
            return ToolResult::error(sprintf('Start line %d exceeds file length (%d lines).', $from, $totalLines));
        }

        $linesRemoved = $toIdx - $fromIdx + 1;

        $original = $this->files->read($path);
        $this->history->record($path, 'remove_lines', $original, [
            'from' => $from,
            'to' => $to,
            'lines_removed' => $linesRemoved,
        ]);

        array_splice($lines, $fromIdx, $linesRemoved);

        try {
            $this->files->writeLines($path, $lines, $eol);
        } catch (CodeEditException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::success(json_encode([
            'path' => $path,
            'lines_removed' => $linesRemoved,
            'total_lines_after' => count($lines),
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
