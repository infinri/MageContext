<?php

declare(strict_types=1);

namespace MageContext\Output;

use MageContext\Config\Schema;

class AiDigestGenerator
{
    /**
     * Generate the ai_digest.md content from all extractor results.
     *
     * @param array<string, array> $allData Extractor name => extracted data
     * @param array<string, array> $extractorResults Extractor name => status/counts
     * @param string $repoPath Repository path
     * @param string $target Target name
     * @param float $duration Compilation duration in seconds
     * @return string Markdown content
     */
    public function generate(
        array $allData,
        array $extractorResults,
        string $repoPath,
        string $target,
        float $duration
    ): string {
        $md = "# AI Context Digest\n\n";
        $md .= "> **This file is the AI entry point.** Read this before any raw JSON.\n\n";
        $md .= "- **Version:** " . Schema::VERSION . "\n";
        $md .= "- **Generated:** " . date('Y-m-d H:i:s T') . "\n";
        $md .= "- **Repository:** `{$repoPath}`\n";
        $md .= "- **Target:** {$target}\n";
        $md .= "- **Duration:** " . round($duration, 2) . "s\n\n";
        $md .= "---\n\n";

        // System Summary
        $md .= "## System Summary\n\n";
        $md .= $this->renderSystemSummary($allData, $extractorResults);
        $md .= "\n---\n\n";

        // Top Architectural Hotspots
        $md .= "## Top 10 Architectural Hotspots\n\n";
        $md .= $this->renderHotspots($allData);
        $md .= "\n---\n\n";

        // Most Overridden Classes
        $md .= "## Most Overridden Classes\n\n";
        $md .= $this->renderOverriddenClasses($allData);
        $md .= "\n---\n\n";

        // Deepest Plugin Stacks
        $md .= "## Deepest Plugin Stacks\n\n";
        $md .= $this->renderDeepPluginStacks($allData);
        $md .= "\n---\n\n";

        // Highest Risk Events
        $md .= "## Highest Risk Events\n\n";
        $md .= $this->renderHighRiskEvents($allData);
        $md .= "\n---\n\n";

        // DI Resolution Conflicts
        $md .= "## DI Area Overrides\n\n";
        $md .= $this->renderAreaOverrides($allData);
        $md .= "\n---\n\n";

        // Coupling Concerns
        $md .= "## Most Unstable Modules (Coupling)\n\n";
        $md .= $this->renderCouplingConcerns($allData);
        $md .= "\n---\n\n";

        // Deviation Summary
        $md .= "## Deviation Summary\n\n";
        $md .= $this->renderDeviationSummary($allData);
        $md .= "\n---\n\n";

        // Layer Violations
        $md .= "## Layer Violations\n\n";
        $md .= $this->renderLayerViolations($allData);
        $md .= "\n---\n\n";

        // Architectural Debt
        $md .= "## Architectural Debt\n\n";
        $md .= $this->renderArchitecturalDebt($allData);
        $md .= "\n---\n\n";

        // Performance Indicators
        $md .= "## Performance Risk Indicators\n\n";
        $md .= $this->renderPerformanceIndicators($allData);
        $md .= "\n---\n\n";

        // Modifiability Risk
        $md .= "## Modifiability Risk (Top 10)\n\n";
        $md .= $this->renderModifiabilityRisk($allData);
        $md .= "\n---\n\n";

        // Enhanced Hotspot Ranking
        $md .= "## Hotspot Ranking (Churn + Centrality)\n\n";
        $md .= $this->renderHotspotRanking($allData);
        $md .= "\n---\n\n";

        // C.5: Quick Lookup Guide
        $md .= "## Quick Lookup Guide\n\n";
        $md .= $this->renderLookupGuide($allData);
        $md .= "\n---\n\n";

        // Available Data Files
        $md .= "## Available Data Files\n\n";
        $md .= $this->renderFileIndex($extractorResults);

        return $md;
    }

    private function renderSystemSummary(array $allData, array $extractorResults): string
    {
        $md = "";

        // Module count
        $moduleData = $allData['modules'] ?? [];
        $moduleSummary = $moduleData['summary'] ?? [];
        if (!empty($moduleSummary)) {
            $md .= "- **Modules:** " . ($moduleSummary['total_modules'] ?? 0) . "\n";
            if (!empty($moduleSummary['by_type'])) {
                foreach ($moduleSummary['by_type'] as $type => $count) {
                    $md .= "  - {$type}: {$count}\n";
                }
            }
            $md .= "- **Composer packages (Magento ecosystem):** " . ($moduleSummary['total_composer_packages'] ?? 0) . "\n";
        }

        // Dependency stats
        $depData = $allData['dependencies'] ?? [];
        $depSummary = $depData['summary'] ?? [];
        if (!empty($depSummary)) {
            $md .= "- **Cross-module dependencies:** " . ($depSummary['total_edges'] ?? 0) . "\n";
            $md .= "- **Average instability:** " . ($depSummary['avg_instability'] ?? 'N/A') . "\n";
        }

        // Plugin stats
        $pluginData = $allData['plugin_chains'] ?? [];
        $pluginSummary = $pluginData['summary'] ?? [];
        if (!empty($pluginSummary)) {
            $md .= "- **Plugins:** " . ($pluginSummary['total_plugins'] ?? 0) . "\n";
            $md .= "- **Intercepted methods:** " . ($pluginSummary['total_intercepted_methods'] ?? 0) . "\n";
            $md .= "- **Max plugin depth:** " . ($pluginSummary['max_plugin_depth'] ?? 0) . "\n";
            $md .= "- **Cross-module plugins:** " . ($pluginSummary['cross_module_plugins'] ?? 0) . "\n";
        }

        // Observer stats
        $observerData = $allData['event_graph'] ?? [];
        $observerSummary = $observerData['summary'] ?? [];
        if (!empty($observerSummary)) {
            $md .= "- **Observers:** " . ($observerSummary['total_observers'] ?? 0) . "\n";
            $md .= "- **Events tracked:** " . ($observerSummary['total_events'] ?? 0) . "\n";
            $md .= "- **High-risk events:** " . ($observerSummary['high_risk_events'] ?? 0) . "\n";
        }

        // DI stats
        $diData = $allData['di_resolution_map'] ?? [];
        $diSummary = $diData['summary'] ?? [];
        if (!empty($diSummary)) {
            $md .= "- **DI preferences:** " . ($diSummary['total_preferences'] ?? 0) . "\n";
            $md .= "- **Virtual types:** " . ($diSummary['total_virtual_types'] ?? 0) . "\n";
            $md .= "- **Proxies:** " . ($diSummary['total_proxies'] ?? 0) . "\n";
            $md .= "- **Core overrides:** " . ($diSummary['core_overrides'] ?? 0) . "\n";
        }

        // Deviation stats
        $devData = $allData['custom_deviations'] ?? [];
        $devSummary = $devData['summary'] ?? [];
        if (!empty($devSummary)) {
            $md .= "- **Total deviations:** " . ($devSummary['total_deviations'] ?? 0) . "\n";
            foreach ($devSummary['by_severity'] ?? [] as $severity => $count) {
                $md .= "  - {$severity}: {$count}\n";
            }
        }

        // Layer classification stats
        $layerData = $allData['layer_classification'] ?? [];
        $layerSummary = $layerData['summary'] ?? [];
        if (!empty($layerSummary)) {
            $md .= "- **Files classified:** " . ($layerSummary['total_files_classified'] ?? 0) . "\n";
            $md .= "- **Layer violations:** " . ($layerSummary['total_violations'] ?? 0) . "\n";
        }

        // Architectural debt stats
        $debtData = $allData['architectural_debt'] ?? [];
        $debtSummary = $debtData['summary'] ?? [];
        if (!empty($debtSummary)) {
            $md .= "- **Architectural debt items:** " . ($debtSummary['total_debt_items'] ?? 0) . "\n";
            $md .= "- **Circular dependencies:** " . ($debtSummary['circular_dependencies'] ?? 0) . "\n";
            $md .= "- **God modules:** " . ($debtSummary['god_modules'] ?? 0) . "\n";
        }

        // Performance indicators
        $perfData = $allData['performance'] ?? [];
        $perfSummary = $perfData['summary'] ?? [];
        if (!empty($perfSummary)) {
            $md .= "- **Performance risk indicators:** " . ($perfSummary['total_indicators'] ?? 0) . "\n";
        }

        // Modifiability
        $modData = $allData['modifiability'] ?? [];
        $modSummary = $modData['summary'] ?? [];
        if (!empty($modSummary)) {
            $md .= "- **High-risk modules (modifiability):** " . ($modSummary['high_risk_modules'] ?? 0) . "\n";
        }

        if ($md === "") {
            $md = "No summary data available.\n";
        }

        return $md;
    }

    private function renderHotspots(array $allData): string
    {
        $hotspotData = $allData['git_churn_hotspots'] ?? [];
        $hotspots = $hotspotData['hotspots'] ?? [];

        if (empty($hotspots)) {
            return "No hotspot data available.\n";
        }

        $md = "| Rank | File | Changes | Lines | Score |\n";
        $md .= "|------|------|---------|-------|-------|\n";

        $limit = min(10, count($hotspots));
        for ($i = 0; $i < $limit; $i++) {
            $h = $hotspots[$i];
            $rank = $i + 1;
            $md .= "| {$rank} | `{$h['file']}` | {$h['change_count']} | {$h['line_count']} | " . round($h['score'], 2) . " |\n";
        }

        return $md;
    }

    private function renderOverriddenClasses(array $allData): string
    {
        $diData = $allData['di_resolution_map'] ?? [];
        $resolutions = $diData['resolutions'] ?? [];

        if (empty($resolutions)) {
            return "No DI preference data available.\n";
        }

        // Count overrides per interface
        $counts = [];
        foreach ($resolutions as $res) {
            if ($res['is_core_override'] ?? false) {
                $interface = $res['interface'];
                $counts[$interface] = ($counts[$interface] ?? 0) + 1;
            }
        }
        arsort($counts);

        if (empty($counts)) {
            return "No core class overrides detected.\n";
        }

        $md = "| Class | Override Count |\n";
        $md .= "|-------|---------------|\n";

        $i = 0;
        foreach ($counts as $class => $count) {
            if ($i >= 10) {
                break;
            }
            $md .= "| `{$class}` | {$count} |\n";
            $i++;
        }

        return $md;
    }

    private function renderDeepPluginStacks(array $allData): string
    {
        $pluginData = $allData['plugin_chains'] ?? [];
        $deepChains = $pluginData['deep_chains'] ?? [];

        if (empty($deepChains)) {
            $maxDepth = $pluginData['summary']['max_plugin_depth'] ?? 0;
            return "No plugin stacks deeper than 5. Max depth: {$maxDepth}.\n";
        }

        $md = "| Target Method | Depth |\n";
        $md .= "|---------------|-------|\n";

        $limit = min(10, count($deepChains));
        for ($i = 0; $i < $limit; $i++) {
            $c = $deepChains[$i];
            $md .= "| `{$c['method_id']}` | {$c['depth']} |\n";
        }

        return $md;
    }

    private function renderHighRiskEvents(array $allData): string
    {
        $observerData = $allData['event_graph'] ?? [];
        $highRisk = $observerData['high_risk_events'] ?? [];

        if (empty($highRisk)) {
            return "No high-risk events detected (risk >= 0.7).\n";
        }

        $md = "| Event | Listeners | Cross-Module | Risk Score |\n";
        $md .= "|-------|-----------|--------------|------------|\n";

        $limit = min(10, count($highRisk));
        for ($i = 0; $i < $limit; $i++) {
            $e = $highRisk[$i];
            $md .= "| `{$e['event']}` | {$e['listener_count']} | {$e['cross_module_listeners']} | {$e['risk_score']} |\n";
        }

        return $md;
    }

    private function renderAreaOverrides(array $allData): string
    {
        $diData = $allData['di_resolution_map'] ?? [];
        $overrides = $diData['area_overrides'] ?? [];

        if (empty($overrides)) {
            return "No area-specific DI resolution conflicts detected.\n";
        }

        $md = "";
        $limit = min(10, count($overrides));
        for ($i = 0; $i < $limit; $i++) {
            $o = $overrides[$i];
            $md .= "### `{$o['type']}`\n\n";
            foreach ($o['resolutions'] as $scope => $resolved) {
                $md .= "- **{$scope}:** `{$resolved}`\n";
            }
            $md .= "\n";
        }

        return $md;
    }

    private function renderCouplingConcerns(array $allData): string
    {
        $depData = $allData['dependencies'] ?? [];
        $couplingData = $depData['coupling_metrics'] ?? [];

        // coupling_metrics is now split: {structural, code, runtime, composite}
        // Use composite.modules for the digest
        $metrics = $couplingData['composite']['modules'] ?? $couplingData['modules'] ?? [];

        if (empty($metrics)) {
            return "No coupling data available.\n";
        }

        // Show top 10 most unstable modules
        $md = "| Module | Afferent (Ca) | Efferent (Ce) | Instability |\n";
        $md .= "|--------|---------------|---------------|-------------|\n";

        $limit = min(10, count($metrics));
        for ($i = 0; $i < $limit; $i++) {
            $m = $metrics[$i];
            $md .= "| `{$m['module']}` | {$m['afferent_coupling']} | {$m['efferent_coupling']} | {$m['instability']} |\n";
        }

        return $md;
    }

    private function renderDeviationSummary(array $allData): string
    {
        $devData = $allData['custom_deviations'] ?? [];
        $deviations = $devData['deviations'] ?? [];
        $summary = $devData['summary'] ?? [];

        if (empty($deviations)) {
            return "No deviations detected.\n";
        }

        $md = "**Total:** " . ($summary['total_deviations'] ?? 0) . "\n\n";

        $severityLabels = [
            'critical' => 'Critical',
            'high' => 'High',
            'medium' => 'Medium',
            'low' => 'Low',
        ];

        foreach ($severityLabels as $severity => $label) {
            $count = $summary['by_severity'][$severity] ?? 0;
            if ($count > 0) {
                $md .= "- **{$label}:** {$count}\n";
            }
        }

        $md .= "\n**By type:**\n\n";
        foreach ($summary['by_type'] ?? [] as $type => $count) {
            $md .= "- `{$type}`: {$count}\n";
        }

        return $md;
    }

    private function renderLayerViolations(array $allData): string
    {
        $layerData = $allData['layer_classification'] ?? [];
        $violations = $layerData['violations'] ?? [];

        if (empty($violations)) {
            return "No cross-layer violations detected.\n";
        }

        $md = "**Total violations:** " . count($violations) . "\n\n";
        $md .= "| From (File) | From Layer | To (Class) | To Layer | Module |\n";
        $md .= "|-------------|------------|------------|----------|--------|\n";

        $limit = min(15, count($violations));
        for ($i = 0; $i < $limit; $i++) {
            $v = $violations[$i];
            $md .= "| `" . basename($v['from']) . "` | {$v['from_layer']} | `" . $this->shortClass($v['to']) . "` | {$v['to_layer']} | {$v['module']} |\n";
        }

        if (count($violations) > $limit) {
            $md .= "\n*...and " . (count($violations) - $limit) . " more. See `module_view/layer_classification.json`.*\n";
        }

        return $md;
    }

    private function renderArchitecturalDebt(array $allData): string
    {
        $debtData = $allData['architectural_debt'] ?? [];
        $items = $debtData['debt_items'] ?? [];

        if (empty($items)) {
            return "No architectural debt detected.\n";
        }

        $md = "**Total debt items:** " . count($items) . "\n\n";

        $limit = min(15, count($items));
        for ($i = 0; $i < $limit; $i++) {
            $item = $items[$i];
            $severity = strtoupper($item['severity']);
            $md .= "- **[{$severity}]** {$item['description']}\n";
        }

        if (count($items) > $limit) {
            $md .= "\n*...and " . (count($items) - $limit) . " more. See `quality_metrics/architectural_debt.json`.*\n";
        }

        return $md;
    }

    private function renderPerformanceIndicators(array $allData): string
    {
        $perfData = $allData['performance'] ?? [];
        $indicators = $perfData['indicators'] ?? [];

        if (empty($indicators)) {
            return "No performance risk indicators detected.\n";
        }

        $md = "**Total indicators:** " . count($indicators) . "\n\n";

        $limit = min(10, count($indicators));
        for ($i = 0; $i < $limit; $i++) {
            $ind = $indicators[$i];
            $severity = strtoupper($ind['severity']);
            $type = $ind['type'];

            if ($type === 'deep_plugin_stack') {
                $md .= "- **[{$severity}]** Plugin depth {$ind['depth']} on `{$ind['target']}`\n";
            } elseif ($type === 'high_observer_count') {
                $md .= "- **[{$severity}]** {$ind['observer_count']} observers on `{$ind['event']}`\n";
            } elseif ($type === 'layout_merge_depth') {
                $md .= "- **[{$severity}]** {$ind['override_count']} overrides on layout handle `{$ind['handle']}`\n";
            }
        }

        return $md;
    }

    private function renderModifiabilityRisk(array $allData): string
    {
        $modData = $allData['modifiability'] ?? [];
        $modules = $modData['modules'] ?? [];

        if (empty($modules)) {
            return "No modifiability data available.\n";
        }

        $md = "| Module | Risk Score | Coupling | Plugins | Core Overrides | Churn | Deviations |\n";
        $md .= "|--------|------------|----------|---------|----------------|-------|------------|\n";

        $limit = min(10, count($modules));
        for ($i = 0; $i < $limit; $i++) {
            $m = $modules[$i];
            $s = $m['signals'] ?? [];
            $md .= "| `{$m['module']}` | {$m['modifiability_risk_score']} | {$s['coupling_refs']} | {$s['plugin_count']} | {$s['core_override_count']} | {$s['churn_total']} | {$s['deviation_count']} |\n";
        }

        return $md;
    }

    private function renderHotspotRanking(array $allData): string
    {
        $rankData = $allData['hotspot_ranking'] ?? [];
        $rankings = $rankData['rankings'] ?? [];

        if (empty($rankings)) {
            return "No hotspot ranking data available.\n";
        }

        $md = "| Module | Score | Churn | Centrality |\n";
        $md .= "|--------|-------|-------|------------|\n";

        $limit = min(10, count($rankings));
        for ($i = 0; $i < $limit; $i++) {
            $r = $rankings[$i];
            $score = $r['final_score'] ?? $r['hotspot_score'] ?? 0;
            $md .= "| `{$r['module']}` | {$score} | {$r['churn_count']} | {$r['centrality']} |\n";
        }

        return $md;
    }

    private function shortClass(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return count($parts) > 2 ? implode('\\', array_slice($parts, -2)) : $fqcn;
    }

    /**
     * C.5: Render a quick lookup guide showing AI consumers how to use indexes.
     */
    private function renderLookupGuide(array $allData): string
    {
        $md = "Use these indexes for O(1) lookups instead of scanning raw extractor output:\n\n";

        $md .= "| Query | Index File | Key |\n";
        $md .= "|-------|-----------|-----|\n";
        $md .= "| Where is class X defined? | `indexes/symbol_index.json` | `symbols[].class_id` |\n";
        $md .= "| What module owns file Y? | `indexes/file_index.json` | `files[].file_id` |\n";
        $md .= "| All facts about class X | `reverse_index/reverse_index.json` | `by_class[class_id]` |\n";
        $md .= "| All facts about module M | `reverse_index/reverse_index.json` | `by_module[module_id]` |\n";
        $md .= "| Who listens to event E? | `reverse_index/reverse_index.json` | `by_event[event_id]` |\n";
        $md .= "| What handles route R? | `reverse_index/reverse_index.json` | `by_route[route_id]` |\n";
        $md .= "| Area-specific modules | `allocation_view/areas.json` | `areas[area].modules` |\n";
        $md .= "\n";

        // Index stats
        $ri = $allData['reverse_index'] ?? [];
        $summary = $ri['summary'] ?? [];
        if (!empty($summary)) {
            $md .= "**Index coverage:**\n";
            $md .= "- {$summary['indexed_classes']} classes indexed\n";
            $md .= "- {$summary['indexed_modules']} modules indexed\n";
            $md .= "- {$summary['indexed_events']} events indexed\n";
            $md .= "- {$summary['indexed_routes']} routes indexed\n";
        }

        $si = $allData['symbol_index']['summary'] ?? [];
        if (!empty($si)) {
            $md .= "- {$si['total_symbols']} symbols in symbol index";
            if (!empty($si['by_type'])) {
                $parts = [];
                foreach ($si['by_type'] as $type => $count) {
                    $parts[] = "{$count} {$type}s";
                }
                $md .= " (" . implode(', ', $parts) . ")";
            }
            $md .= "\n";
        }

        $fi = $allData['file_index']['summary'] ?? [];
        if (!empty($fi)) {
            $md .= "- {$fi['total_files']} files in file index\n";
        }

        return $md;
    }

    private function renderFileIndex(array $extractorResults): string
    {
        $md = "| View | File | Items |\n";
        $md .= "|------|------|-------|\n";

        foreach ($extractorResults as $name => $result) {
            $view = $result['view'] ?? '.';
            $status = $result['status'] ?? 'unknown';
            $count = $result['item_count'] ?? 0;
            $statusIcon = $status === 'ok' ? '' : ' (error)';
            foreach ($result['output_files'] ?? [] as $file) {
                $md .= "| {$view} | `{$file}` | {$count}{$statusIcon} |\n";
            }
        }

        return $md;
    }
}
