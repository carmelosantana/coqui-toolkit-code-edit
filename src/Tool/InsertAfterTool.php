<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeEdit\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\CodeEdit\Exception\CodeEditException;
use CoquiBot\Toolkits\CodeEdit\Storage\EditHistory;
use CoquiBot\Toolkits\CodeEdit\Support\FileOperations;

/**
 * Insert content after lines matching an anchor pattern.
 *
 * Finds lines containing the anchor text (or matching a regex), then inserts
 * the provided content on the line(s) immediately after each match.
 */
final readonly class InsertAfterTool
{
    public function __construct(
        private FileOperations $files,
        private EditHistory $history,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'insert_after',
            description: 'Insert content after lines matching an anchor string or regex. Controls how many matches to affect via occurrences (default: 1, use 0 for all).',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.', required: true),
                new StringParameter('anchor', 'Text or regex pattern to match lines against.', required: true),
                new StringParameter('content', 'Content to insert after each matching line.', required: true),
                new BoolParameter('is_regex', 'Treat anchor as a PCRE regex (default: false).', required: false),
                new NumberParameter('occurrences', 'Number of matches to affect. 0 = all matches, 1 = first only (default: 1).', required: false, integer: true, minimum: 0),
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
        $anchor = (string) ($input['anchor'] ?? '');
        $content = (string) ($input['content'] ?? '');
        $isRegex = (bool) ($input['is_regex'] ?? false);
        $occurrences = (int) ($input['occurrences'] ?? 1);

        if ($path === '' || $anchor === '') {
            return ToolResult::error('Both "path" and "anchor" parameters are required.');
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
        $insertLines = explode($eol, $content);
        $insertions = 0;
        $result = [];

        foreach ($lines as $line) {
            $result[] = $line;

            $matches = $isRegex
                ? (@preg_match('~' . $anchor . '~', $line) === 1)
                : str_contains($line, $anchor);

            if ($matches && ($occurrences === 0 || $insertions < $occurrences)) {
                foreach ($insertLines as $insertLine) {
                    $result[] = $insertLine;
                }
                $insertions++;
            }
        }

        if ($insertions > 0) {
            $original = $this->files->read($path);
            $this->history->record($path, 'insert_after', $original, [
                'anchor' => $anchor,
                'insertions' => $insertions,
            ]);

            try {
                $this->files->writeLines($path, $result, $eol);
            } catch (CodeEditException $e) {
                return ToolResult::error($e->getMessage());
            }
        }

        return ToolResult::success(json_encode([
            'path' => $path,
            'insertions' => $insertions,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
