<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Puneetpal\AppVersion\AppVersion;
use Puneetpal\AppVersion\ClientVersion;
use Puneetpal\AppVersion\Events\AppVersionSeen;
use Puneetpal\AppVersion\Exceptions\InvalidClientHeaders;
use Puneetpal\AppVersion\Headers;
use Puneetpal\AppVersion\UpdateRequirement;
use Puneetpal\AppVersion\VersionPolicy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads X-App-Version, X-App-Build and X-App-Platform, attaches the parsed
 * ClientVersion to the request, and applies the platform's policy:
 *
 * - below minimum: 426 Upgrade Required with the policy JSON as the body
 * - below recommended: the response gains Deprecation, Sunset and Link headers
 * - otherwise: untouched
 *
 * Requests without the headers pass through unless strict mode is on, in
 * which case they get 400. Malformed headers always get 400.
 *
 * JSON bodies are written with JSON_UNESCAPED_SLASHES so a store URL reads as
 * it was configured; the apps decode it either way.
 */
final class EnforceAppVersion
{
    public const ATTRIBUTE = 'app_version';

    public function __construct(
        private readonly AppVersion $appVersion,
        private readonly Repository $config,
        private readonly Dispatcher $events,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $client = ClientVersion::fromRequest($request);
        } catch (InvalidClientHeaders $e) {
            return $this->badRequest($e->errorCode, $e->getMessage());
        }

        $strict = (bool) $this->config->get('app-version.strict', false);

        if ($client === null) {
            if ($strict) {
                return $this->badRequest(
                    'app_version_headers_required',
                    sprintf('This API requires the %s and %s headers on every request.', Headers::VERSION, Headers::PLATFORM),
                );
            }

            return $next($request);
        }

        $request->attributes->set(self::ATTRIBUTE, $client);
        $this->addToContext($client);

        if ($client->platform === null) {
            if ($strict) {
                return $this->badRequest(
                    'app_platform_required',
                    sprintf('This API requires the %s header (ios or android) on every request.', Headers::PLATFORM),
                );
            }

            $this->record($client, UpdateRequirement::None, null, $request);

            return $next($request);
        }

        $policy = $this->appVersion->policy($client->platform);

        if ($policy === null) {
            if ($strict) {
                return $this->badRequest(
                    'unknown_app_platform',
                    sprintf('No app version policy exists for the platform "%s".', $client->platform),
                );
            }

            $this->record($client, UpdateRequirement::None, null, $request);

            return $next($request);
        }

        $requirement = $policy->evaluate($client->version);
        $this->record($client, $requirement, $policy, $request);

        if ($requirement->isBlocking()) {
            return $this->vary($this->applyDeprecationHeaders(
                new JsonResponse($policy->toArray(), Response::HTTP_UPGRADE_REQUIRED, [], JSON_UNESCAPED_SLASHES),
                $policy,
            ));
        }

        $response = $next($request);

        if ($requirement->isDeprecated()) {
            $this->applyDeprecationHeaders($response, $policy);
        }

        return $this->vary($response);
    }

    /**
     * The status and headers of this response depend on the client's version, so a
     * shared cache or CDN must key on these request headers, not on the URL alone.
     */
    private function vary(Response $response): Response
    {
        $response->setVary(Headers::VERSION.', '.Headers::PLATFORM, false);

        return $response;
    }

    private function applyDeprecationHeaders(Response $response, VersionPolicy $policy): Response
    {
        foreach ($policy->deprecationHeaders() as $name => $value) {
            // Link may already carry pagination or other relations; add to it rather than replace it.
            $response->headers->set($name, $value, $name !== Headers::LINK);
        }

        return $response;
    }

    private function record(ClientVersion $client, UpdateRequirement $requirement, ?VersionPolicy $policy, Request $request): void
    {
        if (! $this->config->get('app-version.usage.enabled', false)) {
            return;
        }

        $this->events->dispatch(new AppVersionSeen($client, $requirement, $policy, $request->path()));
    }

    private function addToContext(ClientVersion $client): void
    {
        if (! $this->config->get('app-version.context', true)) {
            return;
        }

        Context::add([
            'app_version' => $client->version->toString(),
            'app_build' => $client->build,
            'app_platform' => $client->platform,
        ]);
    }

    private function badRequest(string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'message' => $message], Response::HTTP_BAD_REQUEST, [], JSON_UNESCAPED_SLASHES);
    }
}
