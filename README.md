# Coqui Code Edit Toolkit

Surgical file editing toolkit for [Coqui](https://github.com/AgentCoqui/coqui). Provides precise, token-efficient code editing operations with full undo history — replace, insert, remove, indent, batch operations, all sandboxed to the workspace.

## Requirements

- PHP 8.4+
- [Coqui](https://github.com/AgentCoqui/coqui) (auto-discovered via Composer)

## Installation

```bash
composer require coquibot/coqui-toolkit-code-edit
```

When installed alongside Coqui, the toolkit is **auto-discovered** via Composer's `extra.php-agents.toolkits` — no manual registration needed.

## Tools Provided

### `replace_in_file`

Replace text in a file using plain string or PCRE regex matching.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | File path relative to workspace |
| `search` | string | Yes | Text or regex pattern to search for |
| `replace` | string | Yes | Replacement text (supports backreferences for regex) |
| `is_regex` | bool | No | Treat search as PCRE regex (default: false) |
| `flags` | string | No | PCRE modifier flags, e.g. "msi" |

### `insert_before`

Insert content before lines matching an anchor pattern.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | File path relative to workspace |
| `anchor` | string | Yes | Text or regex to match lines against |
| `content` | string | Yes | Content to insert before each match |
| `is_regex` | bool | No | Treat anchor as PCRE regex (default: false) |
| `occurrences` | int | No | Matches to affect: 0=all, 1=first (default: 1) |

### `insert_after`

Insert content after lines matching an anchor pattern.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | File path relative to workspace |
| `anchor` | string | Yes | Text or regex to match lines against |
| `content` | string | Yes | Content to insert after each match |
| `is_regex` | bool | No | Treat anchor as PCRE regex (default: false) |
| `occurrences` | int | No | Matches to affect: 0=all, 1=first (default: 1) |

### `replace_block`

Replace a block of content between start and end markers (inclusive).

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | File path relative to workspace |
| `start_marker` | string | Yes | Text or regex marking block start |
| `end_marker` | string | Yes | Text or regex marking block end |
| `new_content` | string | Yes | Content to replace the block with |
| `is_regex` | bool | No | Treat markers as PCRE regex (default: false) |

### `remove_lines`

Remove a range of lines from a file.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | File path relative to workspace |
| `from` | int | Yes | Start line (1-based, inclusive) |
| `to` | int | Yes | End line (1-based, inclusive) |

### `extract_lines`

Extract a range of lines from a file (read-only, does not modify the file).

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | File path relative to workspace |
| `from` | int | Yes | Start line (1-based, inclusive) |
| `to` | int | Yes | End line (1-based, inclusive) |

### `batch_replace`

Run search/replace across multiple files matching a glob pattern.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `glob` | string | Yes | Glob pattern (e.g. "src/**/*.php") |
| `search` | string | Yes | Text or regex pattern |
| `replace` | string | Yes | Replacement text |
| `is_regex` | bool | No | Treat search as PCRE regex (default: false) |
| `flags` | string | No | PCRE modifier flags |

### `append_to_file`

Append text to the end of a file. Creates the file if it doesn't exist.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | File path relative to workspace |
| `content` | string | Yes | Content to append |

### `write_lines`

Overwrite a specific range of lines with new content.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | File path relative to workspace |
| `from` | int | Yes | Start line (1-based, inclusive) |
| `to` | int | Yes | End line (1-based, inclusive) |
| `content` | string | Yes | New content (can be more or fewer lines) |

### `indent_lines`

Indent or outdent a range of lines.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | File path relative to workspace |
| `from` | int | Yes | Start line (1-based, inclusive) |
| `to` | int | Yes | End line (1-based, inclusive) |
| `direction` | enum | Yes | `indent` or `outdent` |
| `size` | int | No | Spaces per level (default: 4) |
| `use_tabs` | bool | No | Use tabs instead of spaces (default: false) |

### `undo_edit`

Undo previous code-edit operations by restoring original file content.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `edit_id` | int | No | Undo a specific edit by ID |
| `file` | string | No | File path to undo edits for (with count) |
| `count` | int | No | Number of recent edits to undo (default: 1) |
| `list_only` | bool | No | List recent edits without undoing (default: false) |
| `prune_days` | int | No | Remove backups older than N days |

## Architecture

```
src/
├── CodeEditToolkit.php          # Main toolkit — registers all tools
├── Exception/
│   └── CodeEditException.php    # Domain exceptions
├── Storage/
│   └── EditHistory.php          # SQLite edit log + file backups
├── Support/
│   ├── FileOperations.php       # Atomic reads/writes, EOL detection
│   └── PathResolver.php         # Workspace sandbox enforcement
└── Tool/
    ├── AppendToFileTool.php
    ├── BatchReplaceTool.php
    ├── ExtractLinesTool.php
    ├── IndentTool.php
    ├── InsertAfterTool.php
    ├── InsertBeforeTool.php
    ├── RemoveLinesTool.php
    ├── ReplaceBlockTool.php
    ├── ReplaceInFileTool.php
    ├── UndoEditTool.php
    └── WriteLinesTool.php
```

## Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| Separate tool classes | Each tool has distinct parameter shapes; keeps schemas clean for LLM consumption |
| Atomic writes (temp + rename) | Prevents partial writes from corrupting files on crash |
| SQLite edit history | Lightweight undo with per-edit granularity; auto-prune keeps storage bounded |
| Path sandboxing via PathResolver | All paths validated against workspace root; blocks directory traversal |
| EOL detection + preservation | Maintains CRLF/LF consistency when editing Windows or Unix files |
| Read-only extract_lines | No history overhead for inspection — only mutating ops record history |

## Notes

- All paths are relative to the workspace root and sandboxed — directory traversal is blocked.
- Regex uses PCRE with `~` as delimiter (no need to escape `/` in patterns).
- Writes are atomic via temp file + rename to prevent corruption.
- Edit history is stored in `.workspace/code-edit/history.db` with file backups in `.workspace/code-edit/backups/`.
- Use `undo_edit(prune_days: 7)` periodically to clean up old backups.

## Development

```bash
composer install
composer test        # Run Pest tests
composer analyse     # Run PHPStan (level 8)
```

## License

MIT
