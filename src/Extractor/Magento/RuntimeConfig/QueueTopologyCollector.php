<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento\RuntimeConfig;

use MageContext\Identity\Evidence;

/**
 * Collects declared queue topology from XML configuration files.
 *
 * Detects:
 *  - Topic declarations (communication.xml)
 *  - Publisher bindings (queue_publisher.xml)
 *  - Exchange/binding topology with DLQ arguments (queue_topology.xml)
 *  - Consumer declarations (queue_consumer.xml)
 *  - Queues missing DLQ configuration (FW-M2-RT-005)
 *
 * Confidence: high for declared topology; does NOT capture broker-level
 * policies, deployment overrides, or runtime consumer runner settings.
 *
 * @see FW-M2-RT-005
 */
class QueueTopologyCollector
{
    public function collect(CollectorContext $ctx): array
    {
        $topics = [];
        $publishers = [];
        $consumers = [];
        $exchanges = [];
        $queuesMissingDlq = [];

        foreach ($ctx->scopePaths() as $scopePath) {
            // communication.xml → topics
            $ctx->findAndParseXml($scopePath, 'communication.xml', function ($xml, $fileId, $module) use (&$topics) {
                foreach ($xml->topic ?? [] as $topicNode) {
                    $topicName = (string) ($topicNode['name'] ?? '');
                    if ($topicName === '') {
                        continue;
                    }
                    $topics[] = [
                        'topic' => $topicName,
                        'request' => (string) ($topicNode['request'] ?? ''),
                        'response' => (string) ($topicNode['response'] ?? ''),
                        'module' => $module,
                        'evidence' => [Evidence::fromXml($fileId, "Topic '{$topicName}'")->toArray()],
                    ];
                }
            });

            // queue_publisher.xml → publishers
            $ctx->findAndParseXml($scopePath, 'queue_publisher.xml', function ($xml, $fileId, $module) use (&$publishers) {
                foreach ($xml->publisher ?? [] as $pubNode) {
                    $topicName = (string) ($pubNode['topic'] ?? '');
                    if ($topicName === '') {
                        continue;
                    }
                    $connections = [];
                    foreach ($pubNode->connection ?? [] as $connNode) {
                        $connections[] = [
                            'name' => (string) ($connNode['name'] ?? ''),
                            'exchange' => (string) ($connNode['exchange'] ?? ''),
                        ];
                    }
                    $publishers[] = [
                        'topic' => $topicName,
                        'connections' => $connections,
                        'module' => $module,
                        'evidence' => [Evidence::fromXml($fileId, "Publisher for topic '{$topicName}'")->toArray()],
                    ];
                }
            });

            // queue_topology.xml → exchanges, bindings, DLQ arguments
            $ctx->findAndParseXml($scopePath, 'queue_topology.xml', function ($xml, $fileId, $module) use (&$exchanges) {
                foreach ($xml->exchange ?? [] as $exchangeNode) {
                    $exchangeName = (string) ($exchangeNode['name'] ?? '');
                    $exchangeType = (string) ($exchangeNode['type'] ?? 'topic');
                    $connection = (string) ($exchangeNode['connection'] ?? 'amqp');

                    $bindings = [];
                    foreach ($exchangeNode->binding ?? [] as $bindingNode) {
                        $bindingId = (string) ($bindingNode['id'] ?? '');
                        $destination = (string) ($bindingNode['destination'] ?? '');
                        $topic = (string) ($bindingNode['topic'] ?? '');

                        $arguments = [];
                        foreach ($bindingNode->arguments->argument ?? [] as $argNode) {
                            $argName = (string) ($argNode['name'] ?? '');
                            $argValue = (string) $argNode;
                            if ($argName !== '') {
                                $arguments[$argName] = $argValue;
                            }
                        }

                        $bindings[] = [
                            'binding_id' => $bindingId,
                            'destination' => $destination,
                            'topic' => $topic,
                            'has_dlq' => isset($arguments['x-dead-letter-exchange']),
                            'dlq_exchange' => $arguments['x-dead-letter-exchange'] ?? null,
                            'delivery_limit' => isset($arguments['x-delivery-limit']) ? (int) $arguments['x-delivery-limit'] : null,
                            'message_ttl' => isset($arguments['x-message-ttl']) ? (int) $arguments['x-message-ttl'] : null,
                            'arguments' => $arguments,
                        ];
                    }

                    $exchanges[] = [
                        'exchange' => $exchangeName,
                        'type' => $exchangeType,
                        'connection' => $connection,
                        'bindings' => $bindings,
                        'module' => $module,
                        'evidence' => [Evidence::fromXml($fileId, "Exchange '{$exchangeName}'")->toArray()],
                    ];
                }
            });

            // queue_consumer.xml → consumers
            $ctx->findAndParseXml($scopePath, 'queue_consumer.xml', function ($xml, $fileId, $module) use (&$consumers) {
                foreach ($xml->consumer ?? [] as $consumerNode) {
                    $consumerName = (string) ($consumerNode['name'] ?? '');
                    if ($consumerName === '') {
                        continue;
                    }
                    $consumers[] = [
                        'consumer' => $consumerName,
                        'queue' => (string) ($consumerNode['queue'] ?? ''),
                        'handler' => (string) ($consumerNode['handler'] ?? ''),
                        'connection' => (string) ($consumerNode['connection'] ?? 'amqp'),
                        'max_messages' => ($consumerNode['maxMessages'] ?? null) !== null
                            ? (int) (string) $consumerNode['maxMessages']
                            : null,
                        'module' => $module,
                        'evidence' => [Evidence::fromXml($fileId, "Consumer '{$consumerName}'")->toArray()],
                    ];
                }
            });
        }

        // Detect queues missing DLQ (per FW-M2-RT-005)
        $queuesWithDlq = [];
        foreach ($exchanges as $exchange) {
            foreach ($exchange['bindings'] as $binding) {
                if ($binding['has_dlq']) {
                    $queuesWithDlq[$binding['destination']] = true;
                }
            }
        }
        foreach ($consumers as $consumer) {
            $queueName = $consumer['queue'];
            if ($queueName !== '' && !isset($queuesWithDlq[$queueName])) {
                $queuesMissingDlq[] = [
                    'queue' => $queueName,
                    'consumer' => $consumer['consumer'],
                    'module' => $consumer['module'],
                ];
            }
        }

        usort($topics, fn($a, $b) => strcmp($a['topic'], $b['topic']));
        usort($publishers, fn($a, $b) => strcmp($a['topic'], $b['topic']));
        usort($consumers, fn($a, $b) => strcmp($a['consumer'], $b['consumer']));
        usort($exchanges, fn($a, $b) => strcmp($a['exchange'], $b['exchange']));
        usort($queuesMissingDlq, fn($a, $b) => strcmp($a['queue'], $b['queue']));

        return [
            '_meta' => [
                'confidence' => 'authoritative_static',
                'source_type' => 'repo_file',
                'sources' => ['communication.xml', 'queue_publisher.xml', 'queue_topology.xml', 'queue_consumer.xml'],
                'runtime_required' => true,
                'limitations' => 'Authoritative for declared topology only (topics, exchanges, bindings, consumers from XML). '
                    . 'Does not inspect broker runtime state, DLQ binding behavior, retry policies, deployment overrides, or consumer runner configuration.',
            ],
            'topics' => $topics,
            'publishers' => $publishers,
            'consumers' => $consumers,
            'exchanges' => $exchanges,
            'queues_missing_dlq' => $queuesMissingDlq,
        ];
    }
}
