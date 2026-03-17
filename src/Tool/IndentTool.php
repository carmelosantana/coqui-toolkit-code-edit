<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeEdit\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\CodeEdit\Exception\CodeEditException;
use CoquiBot\Toolkits\CodeEdit\Storage\EditHistory;
use CoquiBot\Toolkits\CodeEdit\Support\FileOperations;

/**
 * Indent or outdent a range of lines in a file.
 *
 * Adjusts the leading whitespace of lines in [from, to] by adding
 * or removing indentation. Supports spaces (configurable width) or tabs.
 */
final readonly class IndentTool
{
    public function __construct(
        private FileOperations $files,
        private EditHistory $history,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'indent_lines',
            description: 'Indent or outdent a range of lines. Adjusts leading whitespace by adding or removing one level of indentation. Lines are 1-based inclusive.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.', required: true),
                new NumberParameter('from', 'Start line number (1-based, inclusive).', required: true, integer: true, minimum: 1),
                new NumberParameter('to', 'End line number (1-based, inclusive).', required: true, integer: true, minimum: 1),
                new EnumParameter('direction', 'Whether to indent (add whitespace) or outdent (remove whitespace).', ['indent', 'outdent'], required: true),
                new NumberParameter('size', 'Number of spaces per indentation level (default: 4). Ignored when use_tabs is true.', required: false, integer: true, minimum: 1, maximum: 16),
                new BoolParameter('use_tabs', 'Use tab characters instead of spaces (default: false).', required: false),
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
        $direction = (string) ($input['direction'] ?? 'indent');
        $size = (int) ($input['size'] ?? 4);
        $useTabs = (bool) ($input['use_tabs'] ?? false);

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

        $fromIdx = $from - 1;
        $toIdx = min($to - 1, $totalLines - 1);

        if ($fromIdx >= $totalLines) {
            return ToolResult::error(sprintf('Start line %d exceeds file length (%d lines).', $from, $totalLines));
        }

        $indent = $useTabs ? "\t" : str_repeat(' ', $size);
        $linesAffected = 0;

        $original = $this->files->read($path);

        for ($i = $fromIdx; $i <= $toIdx; $i++) {
            $line = $lines[$i];

            // Skip blank lines
            if (trim($line) === '') {
                continue;
            }

            if ($direction === 'indent') {
                $lines[$i] = $indent . $line;
                $linesAffected++;
            } else {
                // Outdent — remove one level of leading whitespace
                if ($useTabs && str_starts_with($line, "\t")) {
                    $lines[$i] = substr($line, 1);
                    $linesAffected++;
                } elseif (!$useTabs) {
                    $spaces = str_repeat(' ', $size);
                    if (str_starts_with($line, $spaces)) {
                        $lines[$i] = substr($line, $size);
                        $linesAffected++;
                    } elseif (str_starts_with($line, "\t")) {
                        // Fall back to removing a tab even in space mode
                        $lines[$i] = substr($line, 1);
                        $linesAffected++;
                    }
                }
            }
        }

        if ($linesAffected > 0) {
            $this->history->record($path, 'indent_lines', $original, [
                'from' => $from,
                'to' => $to,
                'direction' => $direction,
                'lines_affected' => $linesAffected,
            ]);

            try {
                $this->files->writeLines($path, $lines, $eol);
            } catch (CodeEditException $e) {
                return ToolResult::error($e->getMessage());
            }
        }

        return ToolResult::success(json_encode([
            'path' => $path,
            'direction' => $direction,
            'lines_affected' => $linesAffected,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
