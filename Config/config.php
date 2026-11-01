<?php

declare(strict_types=1);

use MauticPlugin\KopexaMcpBundle\Controller\McpController;

return [
    'name'        => 'MCP',
    'description' => 'Model Context Protocol server for AI assistants (Claude, ChatGPT). Read access to contacts, segments, emails and campaigns; write access to drafts only.',
    'version'     => '0.2.0',
    'author'      => 'Kopexa',
    'routes'      => [
        'public' => [
            'kopexa_mcp_endpoint' => [
                'path'       => '/mcp',
                'controller' => McpController::class.'::mcpAction',
                'method'     => 'GET|POST|DELETE|OPTIONS',
            ],
            // RFC 9728: both the plain and the path-inserted location.
            'kopexa_mcp_resource_metadata' => [
                'path'       => '/.well-known/oauth-protected-resource',
                'controller' => McpController::class.'::resourceMetadataAction',
                'method'     => 'GET|OPTIONS',
            ],
            'kopexa_mcp_resource_metadata_path' => [
                'path'       => '/.well-known/oauth-protected-resource/mcp',
                'controller' => McpController::class.'::resourceMetadataAction',
                'method'     => 'GET|OPTIONS',
            ],
        ],
    ],
    'parameters' => [
        // Maximum items a list tool returns.
        'kopexa_mcp_page_limit' => 50,
    ],
];
