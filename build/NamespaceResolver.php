<?php

/**
 * Derives the client namespace (and client class prefix) for a service from
 * its API model.
 *
 * The namespace is derived from the `serviceId` metadata only:
 * `ucwords(serviceId)` with the spaces removed, unless
 * src/data/legacy-namespaces.json lists an entry for that `serviceId`. The
 * table holds every shipped service whose namespace was historically derived
 * from `serviceAbbreviation` / `serviceFullName` and differs, including by
 * case, from what `serviceId` derives on its own, so existing namespaces and
 * changelog categories stay exactly as they were. Never change an entry
 * while the service ships; remove an entry only when the service's models
 * are removed from src/data. `serviceFullName` and `serviceAbbreviation`
 * are documentation fields and must not be read here.
 */
final class NamespaceResolver
{
    /** @var array<string, string> serviceId => namespace */
    private array $legacyNamespaces;

    /** @var array<string, string> lowercased namespace => serviceId */
    private array $legacyServiceIds = [];

    /** @var array<string, string> lowercased directory name => directory name */
    private array $possibleNamespaces = [];

    public function __construct(string $sdkRoot)
    {
        $file = "$sdkRoot/src/data/legacy-namespaces.json";
        $table = json_decode((string) @file_get_contents($file), true);
        if (!is_array($table['legacy-namespaces'] ?? null)
            || $table['legacy-namespaces'] === []
        ) {
            throw new \RuntimeException(
                "Cannot read the legacy namespace table from $file"
            );
        }
        $this->legacyNamespaces = $table['legacy-namespaces'];
        foreach ($this->legacyNamespaces as $serviceId => $ns) {
            $this->legacyServiceIds[strtolower($ns)] = $serviceId;
        }

        // A service namespace is a directory under src/ that holds a client.
        foreach (scandir("$sdkRoot/src") as $dir) {
            if (is_file("$sdkRoot/src/$dir/{$dir}Client.php")) {
                $this->possibleNamespaces[strtolower($dir)] = $dir;
            }
        }
    }

    /**
     * Derives the namespace from `serviceId`. This is the value used for
     * changelog categories and for a service that is not in the SDK yet
     * (for example when generating a new service stub); it does not check
     * that a directory for it exists under src/.
     *
     * @param array $metadata The `metadata` object of an api-2.json model.
     *
     * @throws \InvalidArgumentException if the model has no `serviceId`.
     * @throws \RuntimeException if the derived namespace collides with a
     *     legacy namespace that belongs to another service.
     */
    public function derive(array $metadata): string
    {
        if (empty($metadata['serviceId'])) {
            throw new \InvalidArgumentException(
                'Cannot derive a namespace: metadata.serviceId is missing'
                . ' (endpointPrefix: '
                . ($metadata['endpointPrefix'] ?? 'unknown') . ')'
            );
        }
        $serviceId = $metadata['serviceId'];

        if (isset($this->legacyNamespaces[$serviceId])) {
            return $this->legacyNamespaces[$serviceId];
        }

        $ns = str_replace(' ', '', ucwords($serviceId));
        if (isset($this->legacyServiceIds[strtolower($ns)])) {
            throw new \RuntimeException(sprintf(
                "Namespace '%s' derived for serviceId '%s' collides with the"
                . " legacy namespace of serviceId '%s'",
                $ns,
                $serviceId,
                $this->legacyServiceIds[strtolower($ns)]
            ));
        }

        return $ns;
    }

    /**
     * Resolves the namespace of a service that ships in the SDK, using the
     * exact casing of its directory under src/.
     *
     * @param array $metadata The `metadata` object of an api-2.json model.
     *
     * @throws \RuntimeException if no client directory under src/ matches
     *     the namespace.
     */
    public function resolve(array $metadata): string
    {
        $ns = $this->derive($metadata);

        if (!isset($this->possibleNamespaces[strtolower($ns)])) {
            throw new \RuntimeException('NS not found: ' . $ns);
        }

        return $this->possibleNamespaces[strtolower($ns)];
    }

    /**
     * @internal Exposed for tests only.
     * @return array<string, string> serviceId => namespace
     */
    public function getLegacyNamespaces(): array
    {
        return $this->legacyNamespaces;
    }
}
