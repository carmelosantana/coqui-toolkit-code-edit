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
 * Undo previous edit operations by restoring file backups.
 *
 * Supports undo by edit ID, undo last N edits on a specific file,
 * listing recent edits, and pruning old backup files.
 */
final readonly class UndoEditTool
{
    public function __construct(
        private FileOperations $files,
        private EditHistory $history,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'undo_edit',
            description: 'Undo previous code-edit operations by restoring original file content. Can undo by edit ID, undo the last N edits on a file, list recent edits, or prune old backups.',
            parameters: [
                new NumberParameter('edit_id', 'Undo a specific edit by its ID (from the edit history). Mutually exclusive with file+count.', required: false, integer: true, minimum: 1),
                new StringParameter('file', 'File path to undo edits for (used with count). Relative to workspace.', required: false),
                new NumberParameter('count', 'Number of recent edits to undo for the specified file (default: 1).', required: false, integer: true, minimum: 1, maximum: 50),
                new BoolParameter('list_only', 'If true, list recent edits without undoing anything (default: false).', required: false),
                new NumberParameter('prune_days', 'Remove backup files older than this many days. Mutually exclusive with undo operations.', required: false, integer: true, minimum: 1),
            ],
            callback: fn(array $input): ToolResult => $this->execute($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): ToolResult
    {
        $editId = isset($input['edit_id']) ? (int) $input['edit_id'] : null;
        $file = isset($input['file']) ? trim((string) $input['file']) : null;
        $count = (int) ($input['count'] ?? 1);
        $listOnly = (bool) ($input['list_only'] ?? false);
        $pruneDays = isset($input['prune_days']) ? (int) $input['prune_days'] : null;

        // Prune operation
        if ($pruneDays !== null) {
            $pruned = $this->history->prune($pruneDays);

            return ToolResult::success(json_encode([
                'action' => 'prune',
                'edits_removed' => $pruned,
                'older_than_days' => $pruneDays,
            ], JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        // List operation
        if ($listOnly) {
            $edits = $this->history->list(
                $file !== '' ? $file : null,
                min($count * 5, 50), // Show more context when listing
            );

            return ToolResult::success(json_encode([
                'action' => 'list',
                'edits' => $edits,
            ], JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        // Undo by specific edit ID
        if ($editId !== null) {
            return $this->undoById($editId);
        }

        // Undo last N edits for a file
        if ($file !== null && $file !== '') {
            return $this->undoByFile($file, $count);
        }

        return ToolResult::error(
            'Specify either "edit_id" to undo a specific edit, "file" + "count" to undo recent edits on a file, "list_only: true" to browse history, or "prune_days" to clean up old backups.',
        );
    }

    private function undoById(int $editId): ToolResult
    {
        try {
            $backup = $this->history->getBackup($editId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage());
        }

        try {
            $this->files->write($backup['file_path'], $backup['content']);
        } catch (CodeEditException $e) {
            return ToolResult::error('Undo failed: ' . $e->getMessage());
        }

        $this->history->removeEdit($editId);

        return ToolResult::success(json_encode([
            'action' => 'undo',
            'restored' => [[
                'id' => $backup['id'],
                'file' => $backup['file_path'],
                'operation' => $backup['operation'],
                'timestamp' => $backup['timestamp'],
            ]],
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function undoByFile(string $file, int $count): ToolResult
    {
        $edits = $this->history->getLastEdits($file, $count);

        if ($edits === []) {
            return ToolResult::error(sprintf('No edit history found for file: %s', $file));
        }

        $restored = [];

        foreach ($edits as $edit) {
            try {
                $this->files->write($edit['file_path'], $edit['content']);
                $this->history->removeEdit($edit['id']);
                $restored[] = [
                    'id' => $edit['id'],
                    'file' => $edit['file_path'],
                    'operation' => $edit['operation'],
                    'timestamp' => $edit['timestamp'],
                ];
            } catch (CodeEditException $e) {
                // Continue with remaining undos even if one fails
                $restored[] = [
                    'id' => $edit['id'],
                    'file' => $edit['file_path'],
                    'operation' => $edit['operation'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ToolResult::success(json_encode([
            'action' => 'undo',
            'restored' => $restored,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
