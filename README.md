# MageContext

A CLI tool that indexes a Magento 2 repository via static analysis and outputs an AI-ready context bundle — structured, queryable, deterministic, and immediately usable by AI coding tools like Windsurf, Cursor, or Claude.

## Problem

AI agents fail on enterprise Magento codebases not because the models are weak, but because the context is opaque. Deep XML config, implicit behavior via DI/plugins/observers, and layers of overrides make it impossible for an AI to reason about the system without help.

## Solution

MageContext runs 24 extractors across your repo and produces a self-describing context bundle:

- **Module graph** — modules, themes, composer packages, and their dependency edges
- **Typed dependency graph** — structural, code, and runtime coupling with split metrics
- **DI resolution map** — per-area preference chains with confidence scores
- **Plugin/interceptor chains** — per-method interception with sort order and evidence
- **Event/observer graph** — cross-module event flow with risk scoring
- **Execution paths** — reconstructed controller/cron/CLI flows through DI, plugins, observers
- **Route map, cron map, CLI commands, API surface** — all entry points indexed
- **Layout handles, UI components, DB schema** — frontend and data layer structure
- **Layer classification** — files classified into architectural layers with violation detection
- **Symbol index** — class→file→module O(1) lookup for every PHP symbol
- **File index** — file→module→layer O(1) lookup for every file in scope
- **Reverse indexes** — "given X, find all facts about X" for classes, modules, events, routes
- **Allocation view** — which modules are active in which Magento areas
- **Scenario bundles** — self-contained slices per entry point with risk assessment
- **Quality metrics** — modifiability risk, architectural debt, performance indicators, deviation detection
- **Hotspot ranking** — modules ranked by combined git churn and dependency centrality
- **JSON schemas** — every output file has a machine-readable schema

## Installation

**Requirements:** PHP 8.1+ (see `composer.json`).

Install via Composer:

```bash
composer global require infinri/mage-context
```

Or install in a dedicated directory (recommended if you have other global Composer tools that use different PHP/Symfony versions):

```bash
mkdir ~/.magecontext
cd ~/.magecontext
composer init
composer require infinri/mage-context
```

Run the compiler (from anywhere):

```bash
~/.magecontext/vendor/bin/magecontext
```

Or from inside `~/.magecontext`: `vendor/bin/magecontext`.

To update later (from `~/.magecontext`):

```bash
cd ~/.magecontext && composer update infinri/mage-context
```

**Alternative: clone and run from source**

```bash
git clone https://github.com/infinri/MageContext.git
cd MageContext
composer install
```

## Quick Start

```bash
# Basic compile (auto-detects Magento, outputs to .ai-context/)
bin/magecontext compile --repo /path/to/magento

# Custom output directory
bin/magecontext compile --repo /path/to/magento --out /tmp/my-context

# Custom scopes (default: app/code,app/design)
bin/magecontext compile --repo /path/to/magento --scope app/code,vendor

# Faster local dev (skip determinism check, shorter churn window)
bin/magecontext compile --repo /path/to/magento --skip-determinism-check --churn-window 30

# Disable churn entirely (fastest)
bin/magecontext compile --repo /path/to/magento --churn-window 0
```

## CLI Commands

### `compile`

The primary command. Runs all extractors and produces the full context bundle.

| Option | Default | Description |
|--------|---------|-------------|
| `--repo`, `-r` | `.` | Path to the repository root |
| `--target`, `-t` | `auto` | Target platform: `magento`, `generic`, or `auto` |
| `--scope`, `-s` | target default | Comma-separated directories to scan |
| `--out`, `-o` | `.ai-context` | Output directory for the context bundle |
| `--format`, `-f` | `json` | Output format: `json` or `jsonl` |
| `--churn-window` | `365` | Git churn analysis window in days (0 = disable) |
| `--skip-determinism-check` | off | Skip determinism verification (dev machines only) |
| `--ci` | off | CI mode: write `ci_summary.json`, exit non-zero on threshold violations |
| `--max-violations` | unlimited | CI threshold: max layer violations |
| `--max-cycles` | unlimited | CI threshold: max circular dependencies |
| `--max-deviations` | unlimited | CI threshold: max deviations |
| `--max-risk` | unlimited | CI threshold: max average modifiability risk (0.0–1.0) |

### `diff`

Compare two compiled context bundles and detect architectural regressions.

```bash
bin/magecontext diff /path/to/old/.ai-context /path/to/new/.ai-context
```

| Option | Default | Description |
|--------|---------|-------------|
| `--format`, `-f` | `text` | Output format: `text`, `json`, or `markdown` |
| `--out`, `-o` | stdout | Output file path |

Exits non-zero if regressions are detected (new cycles, increased violations, rising risk scores, etc.).

### `pack`

Extract minimum relevant context from a compiled bundle for a specific issue or stack trace.

```bash
bin/magecontext pack --issue "Checkout fails when coupon applied" --context-dir .ai-context
bin/magecontext pack --issue "500 error" --trace /path/to/stacktrace.log
bin/magecontext pack --keywords "SalesRule,Quote,Plugin" --context-dir .ai-context
```

| Option | Default | Description |
|--------|---------|-------------|
| `--issue`, `-i` | — | Issue description or bug summary |
| `--trace`, `-t` | — | Path to a stack trace or log file |
| `--keywords`, `-k` | — | Comma-separated additional keywords to search for |
| `--context-dir`, `-c` | `.ai-context` | Path to the compiled context directory |
| `--out`, `-o` | stdout | Output path (JSON file or directory) |
| `--format`, `-f` | `both` | Output format: `json`, `markdown`, or `both` |

### `guide`

Generate development guidance for a task within specific Magento areas.

```bash
bin/magecontext guide --task "Add free shipping rule" --area salesrule,checkout
```

| Option | Default | Description |
|--------|---------|-------------|
| `--task`, `-t` | — | Description of the development task |
| `--area`, `-a` | — | Comma-separated Magento areas or module keywords |
| `--context-dir`, `-c` | `.ai-context` | Path to the compiled context directory |
| `--out`, `-o` | stdout | Output file path |
| `--format`, `-f` | `markdown` | Output format: `json`, `markdown`, or `both` |

## Output Structure

```
.ai-context/
├── manifest.json                        # Build metadata, extractor results, validation
├── ai_digest.md                         # AI entry point — read this first
├── repo_map.json                        # Directory tree structure
│
├── schemas/                             # JSON Schema for every output file
│   ├── manifest.schema.json
│   ├── symbol_index.schema.json
│   ├── reverse_index.schema.json
│   └── ... (17 total)
│
├── indexes/                             # O(1) lookup indexes
│   ├── symbol_index.json                # class→file→module mapping
│   └── file_index.json                  # file→module→layer mapping
│
├── reverse_index/
│   └── reverse_index.json               # by_class, by_module, by_event, by_route
│
├── module_view/                         # Structural analysis
│   ├── modules.json                     # Module graph with dependency edges
│   ├── dependencies.json                # Typed dependency graph + coupling metrics
│   ├── layer_classification.json        # File layer classification + violations
│   ├── layout_handles.json              # Layout XML structure
│   ├── ui_components.json               # UI component definitions
│   ├── db_schema_patches.json           # Declarative schema + patches
│   └── api_surface.json                 # REST + GraphQL endpoints
│
├── runtime_view/                        # Behavioral analysis
│   ├── execution_paths.json             # Reconstructed entry→exit flows
│   ├── plugin_chains.json               # Plugin interception chains
│   ├── event_graph.json                 # Event/observer graph
│   ├── di_resolution_map.json           # DI preference resolution
│   ├── route_map.json                   # Route declarations
│   ├── cron_map.json                    # Cron job declarations
│   └── cli_commands.json                # CLI command declarations
│
├── allocation_view/
│   └── areas.json                       # Per-area module allocation
│
├── quality_metrics/
│   ├── modifiability.json               # Per-module modifiability risk
│   ├── hotspot_ranking.json             # Churn + centrality ranking
│   ├── architectural_debt.json          # Cycles, god modules, multi-overrides
│   ├── performance.json                 # Performance risk indicators
│   ├── custom_deviations.json           # Deviations from best practices
│   ├── custom_deviations.md             # Human-readable deviation report
│   └── git_churn_hotspots.json          # Raw churn data
│
└── scenarios/                           # Per-entry-point scenario bundles
    ├── scenario_coverage.json           # Seed matching report
    ├── frontend.controller.*.json       # One bundle per execution path
    ├── crontab.cron.*.json
    └── cli.console.*.json
```

## Key Concepts

### AI Digest (`ai_digest.md`)
The **entry point for AI consumers**. Read this file first — it summarizes the system, highlights top risks, and includes a Quick Lookup Guide showing how to query the indexes.

### Reverse Index
The most powerful artifact for AI agents. Instead of scanning 20+ JSON files to answer "what do I need to know about class X?", query `reverse_index.json`:

```
by_class[class_id]  → file, module, plugins, DI resolutions, events observed
by_module[module_id] → files, classes, plugins, events, routes, crons, CLI, debt
by_event[event_id]  → observers, cross-module count, risk score
by_route[route_id]  → area, controller, module, plugins on controller
```

### Scenario Bundles
Each scenario bundle is a self-contained slice for one entry point (controller, cron job, CLI command). It includes the execution chain, affected modules, risk assessment, and QA concerns. Use these when working on a specific feature.

### Determinism
All output is deterministic — same input always produces byte-identical output. This is enforced by:
- Recursive key sorting on all JSON objects
- Explicit sort keys for arrays (documented in `progress.md`)
- A post-compile determinism check (re-load → re-normalize → compare)

## Configuration

Place a `.magecontext.json` in your repo root to customize behavior.

Key settings:

```json
{
    "scopes": ["app/code", "app/design"],
    "include_vendor": false,
    "churn": {
        "enabled": true,
        "window_days": 365,
        "cache": true
    },
    "max_evidence_per_edge": 5,
    "max_reverse_index_size_mb": 10,
    "edge_weights": {
        "plugin_intercept": 1.2,
        "event_observe": 1.1,
        "di_preference": 1.0
    }
}
```

CLI options override config file values. Config file overrides defaults.

## CI Integration

```bash
bin/magecontext compile \
  --repo . \
  --ci \
  --max-violations 0 \
  --max-cycles 5 \
  --max-deviations 50 \
  --max-risk 0.7
```

Exits non-zero if any threshold is exceeded. Writes `ci_summary.json` with pass/fail details.

## Requirements

- PHP >= 8.1
- Git (for churn analysis)
- A Magento 2 repository to analyze (or any PHP project in `generic` mode)

## Architecture

```
CLI (symfony/console)
  → CompileCommand
    → CompilerConfig (.magecontext.json + CLI overrides)
    → TargetRegistry (auto-detect Magento vs generic)
    → ExtractorRegistry
      → 20 Magento extractors (XML, DI, plugins, events, routes, allocation, ...)
      → 4 Universal extractors (repo map, git churn, symbol index, file index)
    → OutputWriter (deterministic JSON + Markdown)
    → IndexBuilder (reverse indexes from extractor data)
    → ScenarioBundleGenerator (per-entry-point slices)
    → SchemaGenerator (17 JSON schemas)
    → AiDigestGenerator (ai_digest.md)
    → BundleValidator (determinism, evidence, cross-checks, size guardrails)
  → DiffCommand (compare two bundles, detect regressions)
  → PackCommand (extract issue-specific context via ContextResolver)
  → GuideCommand (generate dev guidance via GuideResolver)
```

Extractors are modular — each handles a single concern, outputs structured data with evidence arrays, and declares its output view directory. The registry composes them, and post-processors (IndexBuilder, ScenarioBundleGenerator) cross-reference their output.

## Testing

```bash
# Run all tests
vendor/bin/phpunit

# Hardening tests only (determinism, corruption, edge weights, etc.)
vendor/bin/phpunit tests/Hardening/

# Acceptance tests only (canonical queries, config wiring)
vendor/bin/phpunit tests/Acceptance/
```

Test suite covering:
- 5 canonical AI queries (controller→plugins, interface→impl, event→listeners, module→dependents, hotspot→touchpoints)
- Determinism invariants
- Corruption detection
- Edge weight consistency
- Integrity degradation formulas
- Percentile normalization
- Scenario seed resolver
- Scenario bug regressions
- Churn cache behavior
- Config wiring (defaults, file overrides, CLI overrides)

## Performance

Tested on an enterprise Magento repo (148 modules, 3100+ files):

| Metric | Value |
|--------|-------|
| Compile time | 5.6s (warm churn cache) |
| Peak memory | 97 MB |
| Output size | 10.8 MB |
| Extractors | 24 |
| Schemas | 17 |
| Scenario bundles | 219 |

## License

MIT — see [LICENSE](LICENSE) file.
