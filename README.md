# Context Compiler

A CLI tool that indexes a Magento repository via static analysis and outputs an AI-ready context bundle — structured, searchable, and immediately usable by tools like Windsurf, Cursor, or Claude.

## Problem

AI agents fail on enterprise Magento codebases not because the models are weak, but because the context is opaque. Deep XML config, implicit behavior via DI/plugins/observers, and layers of overrides make it impossible for an AI to reason about the system without help.

## Solution

Context Compiler runs static analysis on your repo and produces a clean, queryable context package:

- **Module dependency graph** — who depends on whom
- **DI preference map** — what classes are swapped and where
- **Plugin/interceptor chains** — what methods are intercepted, in what order
- **Observer/event map** — what events fire and who listens
- **Git churn hotspots** — files that change most often, ranked by risk

## Installation

```bash
composer require mage-context/compiler --dev
```

Or for development:

```bash
git clone <repo-url>
cd context-compiler
composer install
```

## Usage

### Compile a context bundle

```bash
bin/context-compiler compile \
  --repo /path/to/magento \
  --scope app/code,app/design \
  --out .ai-context \
  --format json
```

### Output structure

```
.ai-context/
  manifest.json
  magento/
    module_graph.json
    di_preference_overrides.json
    plugins_interceptors.json
    events_observers.json
  knowledge/
    known_hotspots.json
```

### Use with AI tools

Drop the `.ai-context/` directory (or specific files from it) into your AI tool's project context. The structured data gives the agent precise understanding of your system's architecture, extension points, and customizations.

## Requirements

- PHP >= 8.1
- Git (for churn analysis)
- A Magento 2 repository to analyze

## Architecture

```
CLI (symfony/console)
  → ExtractorRegistry
    → ExtractorInterface implementations
      → Magento extractors (XML parsing, config readers)
      → Universal analyzers (git churn, file complexity)
  → OutputWriter (JSON / JSONL / Markdown)
```

Extractors are modular — each one handles a single concern and outputs structured data. The registry composes them, and the OutputWriter serializes results.

## Development Status

**Phase 0** — Project scaffolding ✓
