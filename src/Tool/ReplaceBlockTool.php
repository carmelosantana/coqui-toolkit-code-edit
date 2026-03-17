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
 * Replace an entire block of content between start and end markers.
 *
 * Finds the region between start_marker and end_marker (both inclusive)
 * and replaces the entire block with new_content. Useful for replacing
 * function bodies, config sections, or any delimited regions.
 */
final readonly class ReplaceBlockTool
{
    public function __construct(
        private FileOperations $files,
        private EditHistory $history,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'replace_block',
            description: 'Replace a block of text between start and end markers (inclusive). Both markers and everything between them are replaced with new_content. Useful for replacing function bodies, config sections, or delimited regions.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.', required: true),
                new StringParameter('start_marker', 'Text or regex marking the start of the block (the line containing this is included in replacement).', required: true),
                new StringParameter('end_marker', 'Text or regex marking the end of the block (the line containing this is included in replacement).', required: true),
                new StringParameter('new_content', 'Content to replace the block with.', required: true),
                new BoolParameter('is_regex', 'Treat markers as PCRE regex patterns (default: false).', required: false),
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
        $startMarker = (string) ($input['start_marker'] ?? '');
        $endMarker = (string) ($input['end_marker'] ?? '');
        $newContent = (string) ($input['new_content'] ?? '');
        $isRegex = (bool) ($input['is_regex'] ?? false);

        if ($path === '' || $startMarker === '' || $endMarker === '') {
            return ToolResult::error('Parameters "path", "start_marker", and "end_marker" are all required.');
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
        $replacementLines = explode($eol, $newContent);
        $result = [];
        $blocksReplaced = 0;
        $inBlock = false;

        foreach ($lines as $line) {
            if (!$inBlock) {
                $matchesStart = $isRegex
                    ? (@preg_match('~' . $startMarker . '~', $line) === 1)
                    : str_contains($line, $startMarker);

                if ($matchesStart) {
                    $inBlock = true;

                    // Check if end marker is on the same line
                    $matchesEnd = $isRegex
                        ? (@preg_match('~' . $endMarker . '~', $line) === 1)
                        : str_contains($line, $endMarker);

                    if ($matchesEnd && $startMarker !== $endMarker) {
                        // Single-line block — replace just this line
                        foreach ($replacementLines as $rl) {
                            $result[] = $rl;
                        }
                        $blocksReplaced++;
                        $inBlock = false;
                    }

                    continue;
                }

                $result[] = $line;
            } else {
                // Inside a block — check for end marker
                $matchesEnd = $isRegex
                    ? (@preg_match('~' . $endMarker . '~', $line) === 1)
                    : str_contains($line, $endMarker);

                if ($matchesEnd) {
                    foreach ($replacementLines as $rl) {
                        $result[] = $rl;
                    }
                    $blocksReplaced++;
                    $inBlock = false;
                }
                // Lines inside the block (before end marker) are skipped
            }
        }

        if ($blocksReplaced > 0) {
            $original = $this->files->read($path);
            $this->history->record($path, 'replace_block', $original, [
                'start_marker' => $startMarker,
                'end_marker' => $endMarker,
                'blocks_replaced' => $blocksReplaced,
            ]);

            try {
                $this->files->writeLines($path, $result, $eol);
            } catch (CodeEditException $e) {
                return ToolResult::error($e->getMessage());
            }
        }

        return ToolResult::success(json_encode([
            'path' => $path,
            'blocks_replaced' => $blocksReplaced,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
