<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeEdit\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\CodeEdit\Exception\CodeEditException;
use CoquiBot\Toolkits\CodeEdit\Support\FileOperations;

/**
 * Extract a range of lines from a file (read-only).
 *
 * Returns lines in the range [from, to] (1-based inclusive) without
 * modifying the file. No edit history is recorded since this is
 * a non-mutating operation.
 */
final readonly class ExtractLinesTool
{
    public function __construct(
        private FileOperations $files,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'extract_lines',
            description: 'Extract a range of lines from a file (read-only). Lines are 1-based inclusive. Returns the content and total line count without modifying the file.',
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
        $totalLines = count($lines);

        $fromIdx = $from - 1;
        $toIdx = min($to - 1, $totalLines - 1);

        if ($fromIdx >= $totalLines) {
            return ToolResult::error(sprintf('Start line %d exceeds file length (%d lines).', $from, $totalLines));
        }

        $slice = array_slice($lines, $fromIdx, $toIdx - $fromIdx + 1);

        return ToolResult::success(json_encode([
            'path' => $path,
            'from' => $from,
            'to' => min($to, $totalLines),
            'total_lines' => $totalLines,
            'content' => implode("\n", $slice),
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
