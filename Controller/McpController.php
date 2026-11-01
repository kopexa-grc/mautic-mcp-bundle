<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaMcpBundle\Controller;

use GuzzleHttp\Psr7\ServerRequest;
use MauticPlugin\KopexaMcpBundle\Service\McpServerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streamable HTTP endpoint of the MCP server and its RFC 9728 metadata. */
final class McpController
{
    public function __construct(
        private readonly McpServerFactory $factory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function mcpAction(Request $request): Response
    {
        if (!$this->factory->isConfigured()) {
            return new JsonResponse(['error' => 'MCP needs the OAuth authorization server (mautic.oauth) to be configured.'], 503);
        }

        try {
            $psr = (new ServerRequest(
                $request->getMethod(),
                $request->getUri(),
                $request->headers->all(),
                $request->getContent(),
                '1.1',
                $request->server->all()
            ))->withQueryParams($request->query->all());

            $response = $this->factory->server()->run($this->factory->transport($psr));
        } catch (\Throwable $e) {
            $this->logger->error('kopexa_mcp: request failed', ['exception' => $e]);

            return new JsonResponse(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'Internal error']], 500);
        }

        return $this->toSymfony($response);
    }

    public function resourceMetadataAction(Request $request): Response
    {
        if (!$this->factory->isConfigured()) {
            return new JsonResponse(['error' => 'not configured'], 503);
        }
        $response = new JsonResponse($this->factory->resourceMetadata()->jsonSerialize(), 200, [
            'Cache-Control'               => 'public, max-age=300',
            'Access-Control-Allow-Origin' => '*',
        ]);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES);

        return $response;
    }

    /** SSE responses are streamed; everything else is buffered. */
    private function toSymfony(ResponseInterface $psr): Response
    {
        $headers = $psr->getHeaders();
        $body    = $psr->getBody();

        if (str_starts_with($psr->getHeaderLine('Content-Type'), 'text/event-stream')) {
            $response = new StreamedResponse(static function () use ($body): void {
                while (!$body->eof()) {
                    $chunk = $body->read(8192);
                    if ('' === $chunk) {
                        continue;
                    }
                    echo $chunk;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            }, $psr->getStatusCode(), $headers);
            $response->headers->set('X-Accel-Buffering', 'no');

            return $response;
        }

        return new Response((string) $body, $psr->getStatusCode(), $headers);
    }
}
