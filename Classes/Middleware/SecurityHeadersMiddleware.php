<?php
namespace JvMTECH\Flow\SecurityHeaders\Middleware;

use Neos\Flow\Annotations as Flow;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @Flow\InjectConfiguration(path="headers")
     * @var array
     */
    protected array $headers;

    /**
     * Named contexts evaluated against each request to determine which
     * context-specific header additions are active.
     *
     * Each context entry supports:
     *   position:     integer priority; higher wins when multiple contexts match (default: 100)
     *   uriPrefixes:  list of URI prefixes — active if the request URI starts with any of them
     *   flowContexts: list of Flow contexts — active if FLOW_CONTEXT matches any of them (subcontexts included)
     *   operator:     'and' (default) or 'or' — how uriPrefixes and flowContexts are combined
     *
     * @Flow\InjectConfiguration(path="contexts")
     * @var array
     */
    protected array $contexts = [];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$response instanceof Response) {
            return $response;
        }

        $activeContexts = $this->resolveActiveContexts($request);

        foreach ($this->headers as $headerName => $directive) {
            $headerValue = $this->buildHeaderValue($directive, $request, $activeContexts);
            if ($headerValue !== '') {
                $response = $response->withHeader($headerName, $headerValue);
            }
        }

        return $response;
    }

    /**
     * Returns active context names sorted ascending by position (lowest first, highest last).
     * 'default' is always included at position 0 and acts as the fallback when no
     * higher-priority context defines a value for a given directive.
     */
    private function resolveActiveContexts(ServerRequestInterface $request): array
    {
        $uri = $request->getServerParams()['REQUEST_URI'] ?? '';
        $flowContext = $request->getServerParams()['FLOW_CONTEXT'] ?? '';

        $active = ['default' => 0];
        foreach ($this->contexts as $name => $conditions) {
            if ($this->contextMatches($conditions, $uri, $flowContext)) {
                $active[$name] = (int)($conditions['position'] ?? 100);
            }
        }

        asort($active);

        return array_keys($active);
    }

    private function contextMatches(array $conditions, string $uri, string $flowContext): bool
    {
        $uriPrefixes = $conditions['uriPrefixes'] ?? [];
        $flowContexts = $conditions['flowContexts'] ?? [];
        $operator = $conditions['operator'] ?? 'and';

        if (empty($uriPrefixes) && empty($flowContexts)) {
            return false;
        }

        $uriMatch = !empty($uriPrefixes) && $this->uriMatchesPrefixes($uri, $uriPrefixes);
        $contextMatch = !empty($flowContexts) && $this->flowContextMatches($flowContext, $flowContexts);

        if (empty($uriPrefixes)) {
            return $contextMatch;
        }
        if (empty($flowContexts)) {
            return $uriMatch;
        }

        return $operator === 'or' ? ($uriMatch || $contextMatch) : ($uriMatch && $contextMatch);
    }

    private function uriMatchesPrefixes(string $uri, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function flowContextMatches(string $flowContext, array $flowContexts): bool
    {
        foreach ($flowContexts as $candidate) {
            if ($flowContext === $candidate || str_starts_with($flowContext, $candidate . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string|array $directive
     */
    private function buildHeaderValue($directive, ServerRequestInterface $request, array $activeContexts): string
    {
        if (is_string($directive)) {
            return $this->replaceVariables($request, $directive);
        }

        $parts = [];
        foreach ($directive as $directiveName => $value) {
            if (is_string($value)) {
                $parts[] = $directiveName . ' ' . $this->replaceVariables($request, $value) . ';';
            } elseif (is_array($value)) {
                $resolved = $this->resolveContextualValue($value, $request, $activeContexts);
                if ($resolved !== '') {
                    $parts[] = $directiveName . ' ' . $resolved . ';';
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Resolves a context-keyed value map to a string.
     *
     * Active contexts are sorted ascending by position. The highest-position active context
     * that defines a value for this directive wins exclusively — lower-priority contexts
     * (including 'default') are not concatenated. 'default' acts as a position-0 fallback
     * when no higher-priority context defines a value.
     */
    private function resolveContextualValue(array $contextValues, ServerRequestInterface $request, array $activeContexts): string
    {
        foreach (array_reverse($activeContexts) as $context) {
            if (isset($contextValues[$context])) {
                return $this->replaceVariables($request, $contextValues[$context]);
            }
        }
        return '';
    }

    private function replaceVariables(ServerRequestInterface $request, string $value): string
    {
        $params = $request->getServerParams();
        return str_replace(
            ['{HTTP_HOST}', '{REQUEST_URI}', '{FLOW_CONTEXT}'],
            [$params['HTTP_HOST'] ?? '', $params['REQUEST_URI'] ?? '', $params['FLOW_CONTEXT'] ?? ''],
            $value
        );
    }
}
