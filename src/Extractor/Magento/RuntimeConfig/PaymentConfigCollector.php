<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento\RuntimeConfig;

use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;

/**
 * Collects declared payment method configuration from config.xml and payment.xml.
 *
 * Extracts:
 *  - Method code, configured model class, payment_action, active flag
 *  - Declared capabilities (can_authorize, can_capture, etc.) when present in XML
 *  - Capabilities NOT declared are marked as 'unknown' (not assumed false)
 *
 * Confidence: medium — these are default values from config.xml. Actual behavior
 * can be overridden by:
 *  - Admin configuration (core_config_data)
 *  - DI method model overrides
 *  - Dynamic config providers at runtime
 *  - Vault/tokenization layers
 */
class PaymentConfigCollector
{
    private const CAPABILITY_FIELDS = [
        'can_authorize',
        'can_capture',
        'can_refund',
        'can_void',
        'can_use_checkout',
        'can_use_internal',
        'can_capture_partial',
        'can_refund_partial_per_invoice',
        'can_fetch_transaction_info',
    ];

    public function collect(CollectorContext $ctx): array
    {
        $methods = [];

        foreach ($ctx->scopePaths() as $scopePath) {
            // Parse config.xml for payment/* default values
            $ctx->findAndParseXml($scopePath, 'config.xml', function ($xml, $fileId, $module) use (&$methods) {
                $defaultNode = $xml->default ?? null;
                if ($defaultNode === null) {
                    return;
                }

                $paymentNode = $defaultNode->payment ?? null;
                if ($paymentNode === null) {
                    return;
                }

                foreach ($paymentNode->children() as $methodCode => $methodNode) {
                    $model = (string) ($methodNode->model ?? '');
                    if ($model === '') {
                        continue;
                    }

                    $active = (string) ($methodNode->active ?? '0');
                    $title = (string) ($methodNode->title ?? '');
                    $paymentAction = (string) ($methodNode->payment_action ?? '');
                    $orderStatus = (string) ($methodNode->order_status ?? '');

                    $declaredCapabilities = [];
                    foreach (self::CAPABILITY_FIELDS as $cap) {
                        $val = (string) ($methodNode->$cap ?? '');
                        if ($val !== '') {
                            $declaredCapabilities[$cap] = (bool) (int) $val;
                        }
                    }

                    $isGateway = (string) ($methodNode->is_gateway ?? '');
                    $group = (string) ($methodNode->group ?? '');

                    $methods[] = [
                        'method_code' => $methodCode,
                        'model' => IdentityResolver::normalizeFqcn($model),
                        'active' => (bool) (int) $active,
                        'title' => $title,
                        'payment_action' => $paymentAction,
                        'order_status' => $orderStatus,
                        'is_gateway' => $isGateway !== '' ? (bool) (int) $isGateway : null,
                        'group' => $group,
                        'declared_capabilities' => $declaredCapabilities,
                        'capabilities_complete' => count($declaredCapabilities) === count(self::CAPABILITY_FIELDS),
                        'module' => $module,
                        'evidence' => [Evidence::fromXml($fileId, "Payment method '{$methodCode}' model={$model}")->toArray()],
                    ];
                }
            });

            // Parse payment.xml for vault/method configuration
            $ctx->findAndParseXml($scopePath, 'payment.xml', function ($xml, $fileId, $module) use (&$methods) {
                foreach ($xml->groups->children() ?? [] as $groupCode => $groupNode) {
                    foreach ($groupNode->children() ?? [] as $methodCode => $methodNode) {
                        $existing = array_filter($methods, fn($m) => $m['method_code'] === $methodCode);
                        if (empty($existing)) {
                            $methods[] = [
                                'method_code' => $methodCode,
                                'model' => '',
                                'active' => false,
                                'title' => '',
                                'payment_action' => '',
                                'order_status' => '',
                                'is_gateway' => null,
                                'group' => $groupCode,
                                'declared_capabilities' => [],
                                'capabilities_complete' => false,
                                'module' => $module,
                                'evidence' => [Evidence::fromXml($fileId, "Payment group '{$groupCode}' method '{$methodCode}'")->toArray()],
                            ];
                        }
                    }
                }
            });
        }

        // Deduplicate by method_code (last writer wins, matching Magento merge semantics)
        $deduped = [];
        foreach ($methods as $method) {
            $code = $method['method_code'];
            if (!isset($deduped[$code])) {
                $deduped[$code] = $method;
            } else {
                $deduped[$code]['evidence'] = array_merge($deduped[$code]['evidence'], $method['evidence']);
                foreach ($method as $k => $v) {
                    if ($k === 'evidence' || $k === 'declared_capabilities') {
                        continue;
                    }
                    if ($v !== '' && $v !== null && $v !== false) {
                        $deduped[$code][$k] = $v;
                    }
                }
                $deduped[$code]['declared_capabilities'] = array_merge(
                    $deduped[$code]['declared_capabilities'],
                    $method['declared_capabilities']
                );
                $deduped[$code]['capabilities_complete'] =
                    count($deduped[$code]['declared_capabilities']) === count(self::CAPABILITY_FIELDS);
            }
        }

        $methods = array_values($deduped);
        usort($methods, fn($a, $b) => strcmp($a['method_code'], $b['method_code']));

        return [
            '_meta' => [
                'confidence' => 'declared_config',
                'source_type' => 'repo_file',
                'sources' => ['config.xml', 'payment.xml'],
                'runtime_required' => true,
                'limitations' => 'Shows declared defaults from config.xml only. '
                    . 'Active state, capabilities, and payment_action can be overridden by admin config, DI, or dynamic providers at runtime. '
                    . 'Capabilities not declared in XML are unknown, not assumed false.',
            ],
            'methods' => $methods,
        ];
    }
}
