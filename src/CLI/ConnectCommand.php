<?php

declare(strict_types=1);

namespace WpMcp\CLI;

use WP_CLI;
use WpMcp\ApiKeyStore;
use WpMcp\Connection;

class ConnectCommand
{
    public static function register(): void
    {
        WP_CLI::add_command('mcp connect', [self::class, 'run'], [
            'shortdesc' => 'Create an API key and print the MCP connection details for this site.',
            'synopsis'  => [
                [
                    'type'        => 'assoc',
                    'name'        => 'name',
                    'description' => 'Name stored with the API key.',
                    'optional'    => true,
                    'default'     => 'WordPress WiZZard',
                ],
                [
                    'type'        => 'assoc',
                    'name'        => 'server',
                    'description' => 'Server name used in the generated client snippets.',
                    'optional'    => true,
                    'default'     => 'wordpress',
                ],
                [
                    'type'        => 'flag',
                    'name'        => 'replace',
                    'description' => 'Revoke existing keys with the same name before creating a new one.',
                    'optional'    => true,
                ],
                [
                    'type'        => 'assoc',
                    'name'        => 'format',
                    'description' => 'Output format.',
                    'optional'    => true,
                    'default'     => 'json',
                    'options'     => ['json', 'text'],
                ],
            ],
        ]);
    }

    /**
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     */
    public static function run(array $args, array $assocArgs): void
    {
        $name = trim((string) ($assocArgs['name'] ?? 'WordPress WiZZard'));
        $serverName = trim((string) ($assocArgs['server'] ?? 'wordpress'));
        $format = (string) ($assocArgs['format'] ?? 'json');

        if ($name === '') {
            WP_CLI::error('The --name option cannot be empty.');
        }

        if ($serverName === '') {
            $serverName = 'wordpress';
        }

        $revoked = 0;
        if (! empty($assocArgs['replace'])) {
            $revoked = ApiKeyStore::revokeByName($name);
        }

        $token = ApiKeyStore::create($name);
        $endpoint = Connection::endpointUrl();
        $command = Connection::claudeCommand($endpoint, $token, $serverName);

        if ($format === 'text') {
            WP_CLI::line($command);
            return;
        }

        WP_CLI::line((string) json_encode([
            'endpoint'      => $endpoint,
            'token'         => $token,
            'command'       => $command,
            'config'        => Connection::configJson($endpoint, $token, $serverName),
            'server_name'   => $serverName,
            'key_name'      => $name,
            'revoked_keys'  => $revoked,
            'site_name'     => get_bloginfo('name'),
            'site_url'      => home_url(),
            'plugin_version' => WP_MCP_VERSION,
        ], JSON_UNESCAPED_SLASHES));
    }
}
