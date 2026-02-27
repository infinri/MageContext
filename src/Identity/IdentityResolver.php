<?php

declare(strict_types=1);

namespace MageContext\Identity;

/**
 * Centralized canonical ID generation for all entity types.
 *
 * Spec §2.1: "Everything must be addressable and joinable."
 *
 * All extractors MUST use this service to produce IDs, ensuring
 * consistent join keys across modules.json, dependencies.json,
 * plugin_chains.json, event_graph.json, reverse indexes, etc.
 */
class IdentityResolver
{
    /**
     * module_id: "Vendor_Module"
     * Canonical module identifier from module.xml name attribute.
     */
    public static function moduleId(string $vendor, string $module): string
    {
        return $vendor . '_' . $module;
    }

    /**
     * module_id from a fully-qualified class name.
     * E.g., "Vendor\Module\Model\Something" → "Vendor_Module"
     */
    public static function moduleIdFromClass(string $fqcn): string
    {
        $parts = explode('\\', ltrim($fqcn, '\\'));
        if (count($parts) >= 2) {
            return $parts[0] . '_' . $parts[1];
        }
        return 'unknown';
    }

    /**
     * module_id from a relative file path.
     * E.g., "app/code/Vendor/Module/Model/Foo.php" → "Vendor_Module"
     */
    public static function moduleIdFromPath(string $relativePath): string
    {
        if (preg_match('#(?:app/code)/([^/]+)/([^/]+)/#', $relativePath, $match)) {
            return $match[1] . '_' . $match[2];
        }
        return 'unknown';
    }

    /**
     * package_id: composer name "vendor/module"
     */
    public static function packageId(string $composerName): string
    {
        return strtolower($composerName);
    }

    /**
     * file_id: normalized repo-relative path.
     * Always uses forward slashes, no leading slash.
     */
    public static function fileId(string $absolutePath, string $repoPath): string
    {
        $relative = str_replace($repoPath . '/', '', $absolutePath);
        return str_replace('\\', '/', ltrim($relative, '/'));
    }

    /**
     * class_id: fully-qualified class name (FQCN), lowercased.
     * Always without leading backslash.
     * Lowercased because PHP class names are case-insensitive,
     * and this ensures consistent join keys across all outputs.
     */
    public static function classId(string $fqcn): string
    {
        return strtolower(ltrim($fqcn, '\\'));
    }

    /**
     * method_id: "FQCN::methodName"
     */
    public static function methodId(string $fqcn, string $method): string
    {
        return self::classId($fqcn) . '::' . $method;
    }

    /**
     * event_id: event name string (already canonical in Magento).
     */
    public static function eventId(string $eventName): string
    {
        return $eventName;
    }

    /**
     * route_id: "<area>/<frontName>/<routeId>/<controller>/<action>"
     */
    public static function routeId(
        string $area,
        string $frontName,
        string $routeId,
        string $controller = '',
        string $action = ''
    ): string {
        $parts = [$area, $frontName, $routeId];
        if ($controller !== '') {
            $parts[] = $controller;
        }
        if ($action !== '') {
            $parts[] = $action;
        }
        return implode('/', $parts);
    }

    /**
     * di_target_id: interface or class being resolved (FQCN).
     */
    public static function diTargetId(string $fqcn): string
    {
        return self::classId($fqcn);
    }

    /**
     * plugin_id: "PluginFQCN::(before|around|after)::subject_method"
     */
    public static function pluginId(string $pluginFqcn, string $type, string $subjectMethod): string
    {
        return self::classId($pluginFqcn) . '::' . $type . '::' . $subjectMethod;
    }

    /**
     * Normalize a FQCN for consistent comparison.
     * Strips leading backslash, trims whitespace.
     */
    public static function normalizeFqcn(string $fqcn): string
    {
        return ltrim(trim($fqcn), '\\');
    }

    /**
     * Check if a class belongs to Magento core.
     */
    public static function isCoreClass(string $fqcn): bool
    {
        $normalized = self::normalizeFqcn($fqcn);
        return str_starts_with($normalized, 'Magento\\');
    }

    /**
     * Determine if two classes belong to different modules.
     */
    public static function isCrossModule(string $classA, string $classB): bool
    {
        $modA = self::moduleIdFromClass($classA);
        $modB = self::moduleIdFromClass($classB);
        return $modA !== $modB && $modA !== 'unknown' && $modB !== 'unknown';
    }
}
