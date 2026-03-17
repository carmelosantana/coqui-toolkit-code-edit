<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeEdit;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Toolkits\CodeEdit\Storage\EditHistory;
use CoquiBot\Toolkits\CodeEdit\Support\FileOperations;
use CoquiBot\Toolkits\CodeEdit\Support\PathResolver;
use CoquiBot\Toolkits\CodeEdit\Tool\AppendToFileTool;
use CoquiBot\Toolkits\CodeEdit\Tool\BatchReplaceTool;
use CoquiBot\Toolkits\CodeEdit\Tool\ExtractLinesTool;
use CoquiBot\Toolkits\CodeEdit\Tool\IndentTool;
use CoquiBot\Toolkits\CodeEdit\Tool\InsertAfterTool;
use CoquiBot\Toolkits\CodeEdit\Tool\InsertBeforeTool;
use CoquiBot\Toolkits\CodeEdit\Tool\RemoveLinesTool;
use CoquiBot\Toolkits\CodeEdit\Tool\ReplaceBlockTool;
use CoquiBot\Toolkits\CodeEdit\Tool\ReplaceInFileTool;
use CoquiBot\Toolkits\CodeEdit\Tool\UndoEditTool;
use CoquiBot\Toolkits\CodeEdit\Tool\WriteLinesTool;

/**
 * Surgical file editing toolkit for Coqui.
 *
 * Provides 12 tools for precise, token-efficient code edits: targeted
 * search/replace, line-level insert/remove/overwrite, block replacement,
 * indentation adjustment, batch operations, and full undo history.
 *
 * Complements FilesystemToolkit (whole-file CRUD) with surgical edit
 * operations that minimize token usage by avoiding full-file rewrites.
 *
 * All file paths are sandboxed to the workspace directory.
 * All mutating operations record edit history for undo support.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 * No credentials required.
 */
final class CodeEditToolkit implements ToolkitInterface
{
    private readonly FileOperations $files;
    private readonly EditHistory $history;

    public function __construct(
        string $workspacePath,
        ?EditHistory $history = null,
    ) {
        $resolver = new PathResolver($workspacePath);
        $this->files = new FileOperations($resolver);
        $this->history = $history ?? new EditHistory($workspacePath . '/code-edit');
    }

    /**
     * Factory method for ToolkitDiscovery — reads workspace path from environment.
     */
    public static function fromEnv(): self
    {
        $workspacePath = getenv('COQUI_WORKSPACE_PATH');
        if ($workspacePath === false || $workspacePath === '') {
            $workspacePath = getcwd() . '/.workspace';
        }

        return new self(workspacePath: $workspacePath);
    }

    public function tools(): array
    {
        return [
            (new ReplaceInFileTool($this->files, $this->history))->build(),
            (new InsertBeforeTool($this->files, $this->history))->build(),
            (new InsertAfterTool($this->files, $this->history))->build(),
            (new ReplaceBlockTool($this->files, $this->history))->build(),
            (new RemoveLinesTool($this->files, $this->history))->build(),
            (new ExtractLinesTool($this->files))->build(),
            (new BatchReplaceTool($this->files, $this->history))->build(),
            (new AppendToFileTool($this->files, $this->history))->build(),
            (new WriteLinesTool($this->files, $this->history))->build(),
            (new IndentTool($this->files, $this->history))->build(),
            (new UndoEditTool($this->files, $this->history))->build(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <CODE-EDIT-GUIDELINES>
            ## Surgical File Editing

            You have 11 editing tools + 1 undo tool for precise, token-efficient file modifications.
            All paths are relative to the workspace root and sandboxed — no directory traversal allowed.
            All mutating operations are recorded in edit history and can be undone.

            ### Tool Selection Guide

            | Task | Tool | When to Use |
            |------|------|-------------|
            | Find & replace text | `replace_in_file` | Rename variables, fix typos, update strings |
            | Insert code before a line | `insert_before` | Add imports, inject code above a function |
            | Insert code after a line | `insert_after` | Add code below a marker, append to blocks |
            | Replace a delimited section | `replace_block` | Rewrite function bodies, config sections |
            | Delete lines | `remove_lines` | Remove dead code, clean up imports |
            | Read lines (no change) | `extract_lines` | Preview code before editing, inspect context |
            | Overwrite line range | `write_lines` | Rewrite a specific section with new code |
            | Find & replace across files | `batch_replace` | Rename across a codebase, update imports |
            | Append to file | `append_to_file` | Add entries to logs, config, or new files |
            | Adjust indentation | `indent_lines` | Fix indentation after refactoring |
            | Revert changes | `undo_edit` | Undo bad edits, browse edit history |

            ### Best Practices

            - **Use `extract_lines` first** to inspect the target area before editing.
            - **Prefer targeted edits** over full-file rewrites — saves tokens and reduces errors.
            - **Use `replace_in_file` with `is_regex: true`** for pattern-based changes (supports PCRE).
            - **Use `replace_block`** for replacing entire sections between markers (function bodies, HTML blocks, etc.).
            - **Use `insert_before`/`insert_after`** instead of rewriting surrounding code.
            - **Use `batch_replace`** for codebase-wide renames — more efficient than multiple `replace_in_file` calls.
            - **Use `undo_edit` with `list_only: true`** to browse recent edit history before undoing.
            - **Regex patterns** use PCRE syntax with `~` as the delimiter. Backreferences work in replacements ($1, $2, etc.).

            ### Undo System

            Every mutating edit records the original file state. You can:
            - Undo by edit ID: `undo_edit(edit_id: 42)`
            - Undo last N edits on a file: `undo_edit(file: "path/to/file.php", count: 3)`
            - Browse history: `undo_edit(list_only: true)` or `undo_edit(file: "path/to/file.php", list_only: true)`
            - Clean up old backups: `undo_edit(prune_days: 7)`

            ### Line Numbers

            All line-based tools use 1-based inclusive ranges:
            - `from: 10, to: 20` affects lines 10 through 20 (11 lines total)
            - Line numbers exceeding the file length are clamped to the last line

            ### Regex Tips

            - Delimiter is `~` (no need to escape `/` in patterns)
            - Common flags: `m` (multiline), `s` (dotall), `i` (case-insensitive)
            - Backreferences in replacement: `$1`, `$2`, or `${1}`, `${2}`
            - Example: `replace_in_file(path: "file.php", search: "function (\\w+)\\(", replace: "function prefix_$1(", is_regex: true)`
            </CODE-EDIT-GUIDELINES>
            GUIDELINES;
    }
}
