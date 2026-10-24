<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion;

use Closure;
use Illuminate\Http\Request;
use Puneetpal\AppVersion\Contracts\PolicyResolver;
use Puneetpal\AppVersion\Resolvers\CallablePolicyResolver;

/**
 * The package's service, bound as a singleton and exposed through the
 * AppVersion facade. Resolves policies and evaluates client versions.
 */
final class AppVersion
{
    public function __construct(private PolicyResolver $resolver)
    {
    }

    /**
     * Replace the policy source, for example with a closure that reads a table:
     *
     *     AppVersion::resolveUsing(fn (string $platform) => AppRelease::policyFor($platform));
     */
    public function resolveUsing(PolicyResolver|Closure $resolver): static
    {
        $this->resolver = $resolver instanceof Closure ? new CallablePolicyResolver($resolver) : $resolver;

        return $this;
    }

    public function resolver(): PolicyResolver
    {
        return $this->resolver;
    }

    /**
     * The policy for a platform, or null when the platform is not served. The
     * name is normalised first (see Platform); a name that is not a valid
     * platform token is never passed to the resolver.
     */
    public function policy(string $platform): ?VersionPolicy
    {
        $platform = Platform::tryNormalise($platform);

        if ($platform === null) {
            return null;
        }

        return $this->resolver->resolve($platform);
    }

    /**
     * The parsed X-App-* headers of a request, or null when none were sent.
     *
     * @throws Exceptions\InvalidClientHeaders
     */
    public function client(Request $request): ?ClientVersion
    {
        return ClientVersion::fromRequest($request);
    }

    /**
     * What this client must do. A client whose platform has no policy needs nothing.
     */
    public function evaluate(ClientVersion $client): UpdateRequirement
    {
        if ($client->platform === null) {
            return UpdateRequirement::None;
        }

        return $this->policy($client->platform)?->evaluate($client->version) ?? UpdateRequirement::None;
    }
}
