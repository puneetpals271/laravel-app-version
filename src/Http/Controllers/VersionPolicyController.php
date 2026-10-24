<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Puneetpal\AppVersion\AppVersion;
use Puneetpal\AppVersion\Headers;
use Puneetpal\AppVersion\Platform;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /app/version-policy?platform=ios
 *
 * The platform comes from the query string, falling back to X-App-Platform.
 * Without either the answer is 400; with a malformed name 400; with a name
 * that has no policy 404.
 */
final class VersionPolicyController
{
    public function __construct(
        private readonly AppVersion $appVersion,
        private readonly Repository $config,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $requested = $request->query('platform');
        $fromHeader = false;

        if (! is_string($requested) || trim($requested) === '') {
            $requested = $request->headers->get(Headers::PLATFORM);
            $fromHeader = true;
        }

        if (! is_string($requested) || trim($requested) === '') {
            return $this->error(Response::HTTP_BAD_REQUEST, 'platform_required', sprintf(
                'Pass ?platform=ios or ?platform=android, or send the %s header.',
                Headers::PLATFORM,
            ));
        }

        $platform = Platform::tryNormalise($requested);

        if ($platform === null) {
            return $this->error(Response::HTTP_BAD_REQUEST, 'invalid_app_platform', sprintf(
                'The platform is not a valid platform name. Send a short lower-case name such as ios or android%s.',
                $fromHeader ? ' in the '.Headers::PLATFORM.' header' : '',
            ));
        }

        $policy = $this->appVersion->policy($platform);

        if ($policy === null) {
            return $this->error(Response::HTTP_NOT_FOUND, 'unknown_app_platform', sprintf(
                'No app version policy exists for the platform "%s".',
                $platform,
            ));
        }

        $response = new JsonResponse($policy->toArray(), Response::HTTP_OK, [], JSON_UNESCAPED_SLASHES);

        if ($fromHeader) {
            // The body was chosen by a request header, so a shared cache must key on it.
            $response->setVary(Headers::PLATFORM);
        }

        $maxAge = (int) $this->config->get('app-version.route.cache_max_age', 300);

        if ($maxAge > 0) {
            $response->setPublic();
            $response->setMaxAge($maxAge);
        } else {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'message' => $message], $status, [], JSON_UNESCAPED_SLASHES);
    }
}
