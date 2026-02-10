<?php

declare(strict_types=1);

namespace WpMcp;

use Evenement\EventEmitterTrait;
use PhpMcp\Schema\JsonRpc\Message;
use PhpMcp\Schema\JsonRpc\Parser;
use PhpMcp\Schema\JsonRpc\Request;
use PhpMcp\Schema\JsonRpc\BatchRequest;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Custom MCP transport for WordPress REST API integration.
 *
 * Bridges a synchronous WP REST request to the async MCP SDK:
 * 1. Parses JSON-RPC from the HTTP request body
 * 2. Emits 'client_connected' for session creation
 * 3. Emits 'message' event for the Protocol to handle
 * 4. Collects responses via sendMessage()
 * 5. Returns them synchronously
 */
class WordPressTransport implements ServerTransportInterface
{
    use EventEmitterTrait;

    private string $requestBody;
    private string $requestMethod;
    private array $requestHeaders;
    private ?string $sessionId = null;

    /** @var Message[] */
    private array $responses = [];

    public function __construct(string $requestBody, string $requestMethod, array $requestHeaders = [])
    {
        $this->requestBody = $requestBody;
        $this->requestMethod = strtoupper($requestMethod);
        $this->requestHeaders = $requestHeaders;
    }

    public function listen(): void
    {
        // Extract session ID from headers (WP REST lowercases headers)
        $this->sessionId = $this->getHeader('mcp-session-id');

        if ($this->requestMethod === 'POST') {
            $this->handlePost();
        } elseif ($this->requestMethod === 'DELETE') {
            $this->handleDelete();
        }
    }

    private function handlePost(): void
    {
        $body = trim($this->requestBody);
        if ($body === '') {
            return;
        }

        try {
            $parsed = Parser::parseRequestMessage($body);
        } catch (\Throwable $e) {
            // Invalid JSON-RPC - return parse error
            $error = \PhpMcp\Schema\JsonRpc\Error::forParseError($e->getMessage());
            $this->responses[] = $error;
            return;
        }

        // Determine or generate session ID
        $sessionId = $this->sessionId ?? $this->generateSessionId($parsed);
        $this->sessionId = $sessionId;

        // If this is a new session (no existing session ID header), emit client_connected
        // so the SessionManager creates the session before we process the message
        if (! $this->getHeader('mcp-session-id')) {
            $this->emit('client_connected', [$sessionId]);
        }

        $context = [
            'response_mode' => 'post_json',
        ];

        $this->emit('message', [$parsed, $sessionId, $context]);
    }

    private function handleDelete(): void
    {
        if ($this->sessionId) {
            $this->emit('client_disconnected', [$this->sessionId, 'client_request']);
        }
    }

    private function generateSessionId(Request|BatchRequest|\PhpMcp\Schema\JsonRpc\Notification $message): string
    {
        return bin2hex(random_bytes(16));
    }

    private function getHeader(string $name): ?string
    {
        // Check various header key formats (WP REST normalizes differently)
        $variants = [
            $name,
            strtolower($name),
            strtoupper($name),
            str_replace('-', '_', $name),
            str_replace('-', '_', strtolower($name)),
        ];

        foreach ($variants as $key) {
            if (isset($this->requestHeaders[$key])) {
                $value = $this->requestHeaders[$key];
                return is_array($value) ? ($value[0] ?? null) : $value;
            }
        }

        return null;
    }

    public function sendMessage(Message $message, string $sessionId, array $context = []): PromiseInterface
    {
        $this->sessionId = $sessionId;
        $this->responses[] = $message;
        return resolve(null);
    }

    public function close(): void
    {
        $this->emit('close');
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * @return Message[]
     */
    public function getResponses(): array
    {
        return $this->responses;
    }

    /**
     * Build the JSON response body from collected messages.
     */
    public function getResponseBody(): string
    {
        if (empty($this->responses)) {
            return '';
        }

        if (count($this->responses) === 1) {
            return json_encode($this->responses[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return json_encode($this->responses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
