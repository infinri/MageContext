<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use Symfony\Component\Finder\Finder;

/**
 * Spec §3.4: Custom deviations with evidence and 'why this is risky'.
 */
class DeviationExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'custom_deviations';
    }

    public function getDescription(): string
    {
        return 'Detects deviations from Magento best practices: core overrides, anti-patterns, risky customizations';
    }

    public function getOutputView(): string
    {
        return 'quality_metrics';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $deviations = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            // 1. DI preferences that replace core classes
            $deviations = array_merge($deviations, $this->findCorePreferenceOverrides($scopePath, $repoPath));

            // 2. Plugins on core classes
            $deviations = array_merge($deviations, $this->findCorePlugins($scopePath, $repoPath));

            // 3. ObjectManager direct usage (anti-pattern)
            $deviations = array_merge($deviations, $this->findObjectManagerUsage($scopePath, $repoPath));

            // 4. Direct SQL queries (bypassing repository/resource model pattern)
            $deviations = array_merge($deviations, $this->findDirectSqlUsage($scopePath, $repoPath));

            // 5. Classes extending core models/blocks/controllers directly
            $deviations = array_merge($deviations, $this->findCoreClassExtensions($scopePath, $repoPath));

            // 6. Template overrides via theme (potential upgrade conflicts)
            $deviations = array_merge($deviations, $this->findTemplateOverrides($scopePath, $repoPath));
        }

        // Sort by severity
        $severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($deviations, function ($a, $b) use ($severityOrder) {
            $aOrder = $severityOrder[$a['severity']] ?? 99;
            $bOrder = $severityOrder[$b['severity']] ?? 99;
            return $aOrder <=> $bOrder;
        });

        $summary = $this->buildSummary($deviations);

        return [
            'deviations' => $deviations,
            'summary' => $summary,
        ];
    }

    private function findCorePreferenceOverrides(string $scopePath, string $repoPath): array
    {
        $deviations = [];
        $finder = new Finder();
        $finder->files()->in($scopePath)->name('di.xml')->sortByName();

        foreach ($finder as $file) {
            $xml = @simplexml_load_file($file->getRealPath());
            if ($xml === false) {
                continue;
            }

            $fId = $this->fileId($file->getRealPath(), $repoPath);

            foreach ($xml->xpath('//preference') ?: [] as $node) {
                $for = (string) ($node['for'] ?? '');
                $type = (string) ($node['type'] ?? '');

                if ($for !== '' && str_starts_with($for, 'Magento\\')) {
                    $deviations[] = [
                        'type' => 'core_preference_override',
                        'severity' => 'high',
                        'message' => "Preference replaces core class: {$for} → {$type}",
                        'why_risky' => 'Preferences fully replace classes, preventing other modules from extending them. Breaks during upgrades when core class changes.',
                        'details' => [
                            'original_class' => $for,
                            'replacement_class' => $type,
                        ],
                        'source_file' => $fId,
                        'recommendation' => 'Use plugins (interceptors) instead of preferences when possible.',
                        'evidence' => [Evidence::fromXml($fId, "preference for={$for} type={$type}")->toArray()],
                    ];
                }
            }
        }

        return $deviations;
    }

    private function findCorePlugins(string $scopePath, string $repoPath): array
    {
        $deviations = [];
        $finder = new Finder();
        $finder->files()->in($scopePath)->name('di.xml')->sortByName();

        foreach ($finder as $file) {
            $xml = @simplexml_load_file($file->getRealPath());
            if ($xml === false) {
                continue;
            }

            $fId = $this->fileId($file->getRealPath(), $repoPath);

            foreach ($xml->xpath('//type') ?: [] as $typeNode) {
                $targetClass = (string) ($typeNode['name'] ?? '');
                if (!str_starts_with($targetClass, 'Magento\\')) {
                    continue;
                }

                foreach ($typeNode->plugin ?? [] as $pluginNode) {
                    $pluginName = (string) ($pluginNode['name'] ?? '');
                    $pluginClass = (string) ($pluginNode['type'] ?? '');
                    $disabled = strtolower((string) ($pluginNode['disabled'] ?? 'false')) === 'true';

                    if ($pluginName !== '' && !$disabled) {
                        $severity = $this->assessPluginSeverity($targetClass);
                        $deviations[] = [
                            'type' => 'core_plugin',
                            'severity' => $severity,
                            'message' => "Plugin on core class: {$targetClass} via {$pluginClass}",
                            'why_risky' => 'Core plugins alter Magento internals. Behavior may break silently on upgrade if method signature changes.',
                            'details' => [
                                'target_class' => $targetClass,
                                'plugin_name' => $pluginName,
                                'plugin_class' => $pluginClass,
                            ],
                            'source_file' => $fId,
                            'recommendation' => 'Core plugins are acceptable but should be documented. Verify behavior after upgrades.',
                            'evidence' => [Evidence::fromXml($fId, "plugin {$pluginName} on {$targetClass}")->toArray()],
                        ];
                    }
                }
            }
        }

        return $deviations;
    }

    private function findObjectManagerUsage(string $scopePath, string $repoPath): array
    {
        $deviations = [];
        $finder = new Finder();
        $finder->files()->in($scopePath)->name('*.php')->sortByName();

        foreach ($finder as $file) {
            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                continue;
            }

            $fId = $this->fileId($file->getRealPath(), $repoPath);

            // Skip ObjectManager itself and factories/proxies
            if (str_contains($fId, 'ObjectManager')
                || str_contains($fId, 'Factory.php')
                || str_contains($fId, 'Proxy.php')) {
                continue;
            }

            $patterns = [
                'ObjectManager::getInstance()' => '/ObjectManager\s*::\s*getInstance\s*\(/i',
                'ObjectManagerInterface->create()' => '/\$this\s*->\s*_objectManager\s*->\s*create\s*\(/i',
                'ObjectManagerInterface->get()' => '/\$this\s*->\s*_objectManager\s*->\s*get\s*\(/i',
            ];

            foreach ($patterns as $label => $pattern) {
                if (preg_match_all($pattern, $content, $matches)) {
                    $count = count($matches[0]);
                    $deviations[] = [
                        'type' => 'object_manager_usage',
                        'severity' => 'critical',
                        'message' => "Direct ObjectManager usage ({$label}) found {$count} time(s)",
                        'why_risky' => 'ObjectManager direct usage breaks testability, hides dependencies, and violates Magento coding standards. Cannot be intercepted by DI.',
                        'details' => [
                            'pattern' => $label,
                            'occurrences' => $count,
                        ],
                        'source_file' => $fId,
                        'recommendation' => 'Use constructor dependency injection instead.',
                        'evidence' => [Evidence::fromPhpAst($fId, 0, null, "ObjectManager usage: {$label}")->toArray()],
                    ];
                }
            }
        }

        return $deviations;
    }

    private function findDirectSqlUsage(string $scopePath, string $repoPath): array
    {
        $deviations = [];
        $finder = new Finder();
        $finder->files()->in($scopePath)->name('*.php')->sortByName();

        foreach ($finder as $file) {
            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                continue;
            }

            $fId = $this->fileId($file->getRealPath(), $repoPath);

            // Skip resource models and setup scripts (where direct SQL is expected)
            if (str_contains($fId, '/ResourceModel/')
                || str_contains($fId, '/Setup/')) {
                continue;
            }

            $patterns = [
                'getConnection()->query()' => '/->getConnection\s*\(\s*\)\s*->\s*query\s*\(/i',
                'getConnection()->exec()' => '/->getConnection\s*\(\s*\)\s*->\s*exec\s*\(/i',
                'raw SQL string' => '/->getConnection\s*\(\s*\)\s*->\s*(?:fetchAll|fetchRow|fetchOne|fetchCol)\s*\(/i',
            ];

            foreach ($patterns as $label => $pattern) {
                if (preg_match($pattern, $content)) {
                    $deviations[] = [
                        'type' => 'direct_sql',
                        'severity' => 'medium',
                        'message' => "Direct SQL usage ({$label}) outside ResourceModel",
                        'why_risky' => 'Direct SQL bypasses model events, plugins, indexers, and caching. Data changes may be invisible to other modules.',
                        'details' => ['pattern' => $label],
                        'source_file' => $fId,
                        'recommendation' => 'Use Repository or ResourceModel pattern for data access.',
                        'evidence' => [Evidence::fromPhpAst($fId, 0, null, "direct SQL: {$label}")->toArray()],
                    ];
                }
            }
        }

        return $deviations;
    }

    private function findCoreClassExtensions(string $scopePath, string $repoPath): array
    {
        $deviations = [];
        $finder = new Finder();
        $finder->files()->in($scopePath)->name('*.php')->sortByName();

        $riskyBaseClasses = [
            'Magento\\Framework\\App\\Action\\Action' => 'Consider using Controller\\Result interfaces',
            'Magento\\Backend\\App\\Action' => 'Acceptable for admin controllers, but document customizations',
            'Magento\\Framework\\Model\\AbstractModel' => 'Acceptable pattern, but prefer composition where possible',
            'Magento\\Framework\\View\\Element\\Template' => 'Acceptable for blocks, but check for ViewModel usage instead',
        ];

        foreach ($finder as $file) {
            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                continue;
            }

            $fId = $this->fileId($file->getRealPath(), $repoPath);

            foreach ($riskyBaseClasses as $baseClass => $recommendation) {
                $escaped = preg_quote($baseClass, '/');
                // Match "extends Magento\...\ClassName" or "extends \Magento\...\ClassName"
                $shortName = substr($baseClass, strrpos($baseClass, '\\') + 1);
                $pattern = '/\bextends\s+(?:\\\\?' . $escaped . '|' . preg_quote($shortName, '/') . ')\b/';

                if (preg_match($pattern, $content)) {
                    // Verify it's actually the Magento class via use statement
                    if (str_contains($content, $baseClass) || str_contains($content, 'extends \\' . $baseClass)) {
                        $deviations[] = [
                            'type' => 'core_class_extension',
                            'severity' => 'low',
                            'message' => "Extends core class: {$baseClass}",
                            'why_risky' => 'Tightly couples custom code to Magento internals. Core class method changes may break subclass.',
                            'details' => ['base_class' => $baseClass],
                            'source_file' => $fId,
                            'recommendation' => $recommendation,
                            'evidence' => [Evidence::fromPhpAst($fId, 0, null, "extends {$baseClass}")->toArray()],
                        ];
                    }
                }
            }
        }

        return $deviations;
    }

    private function findTemplateOverrides(string $scopePath, string $repoPath): array
    {
        $deviations = [];

        // Look for Magento template overrides in theme directories
        // Pattern: app/design/frontend/<Vendor>/<theme>/Magento_*
        $finder = new Finder();
        $finder->directories()
            ->in($scopePath)
            ->path('/Magento_/')
            ->depth('< 6');

        $overriddenModules = [];
        foreach ($finder as $dir) {
            $relativePath = $this->fileId($dir->getRealPath(), $repoPath);

            if (preg_match('/\/(Magento_\w+)/', $relativePath, $match)) {
                $moduleName = $match[1];
                if (!isset($overriddenModules[$moduleName])) {
                    $overriddenModules[$moduleName] = [];
                }

                // Count template files in this override directory
                $templateFinder = new Finder();
                $templateFinder->files()
                    ->in($dir->getRealPath())
                    ->name('*.phtml');

                $templateCount = iterator_count($templateFinder);
                if ($templateCount > 0) {
                    $overriddenModules[$moduleName][] = [
                        'path' => $relativePath,
                        'template_count' => $templateCount,
                    ];
                }
            }
        }

        foreach ($overriddenModules as $moduleName => $overrides) {
            $totalTemplates = array_sum(array_column($overrides, 'template_count'));
            if ($totalTemplates === 0) {
                continue;
            }
            $sourcePath = $overrides[0]['path'] ?? '';
            $deviations[] = [
                'type' => 'template_override',
                'severity' => 'medium',
                'message' => "Theme overrides {$totalTemplates} template(s) from {$moduleName}",
                'why_risky' => 'Template overrides copy-paste core markup. They silently break when Magento changes the original template structure on upgrade.',
                'details' => [
                    'module' => $moduleName,
                    'total_templates' => $totalTemplates,
                    'override_paths' => array_column($overrides, 'path'),
                ],
                'source_file' => $sourcePath,
                'recommendation' => 'Consider using layout XML or view models to minimize template changes.',
                'evidence' => [Evidence::fromInference("{$totalTemplates} template overrides for {$moduleName}")->toArray()],
            ];
        }

        return $deviations;
    }

    private function assessPluginSeverity(string $targetClass): string
    {
        $criticalNamespaces = [
            'Magento\\Sales\\Model\\Order',
            'Magento\\Quote\\Model\\Quote',
            'Magento\\Checkout\\',
            'Magento\\Payment\\',
            'Magento\\Customer\\Model\\Session',
            'Magento\\Customer\\Model\\AccountManagement',
        ];

        foreach ($criticalNamespaces as $ns) {
            if (str_starts_with($targetClass, $ns)) {
                return 'high';
            }
        }

        return 'medium';
    }

    private function buildSummary(array $deviations): array
    {
        $bySeverity = [];
        $byType = [];

        foreach ($deviations as $d) {
            $severity = $d['severity'];
            $type = $d['type'];
            $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        arsort($byType);

        return [
            'total_deviations' => count($deviations),
            'by_severity' => $bySeverity,
            'by_type' => $byType,
        ];
    }
}
