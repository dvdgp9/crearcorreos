<?php

/**
 * Organiza dominios disponibles en Plesk como un árbol padre/subdominio.
 */
class DomainHierarchy
{
    /**
     * @param array<int, array{name?: mixed}> $domains
     * @return array<int, array{name:string, depth:int, is_subdomain:bool, root:string}>
     */
    public static function build(array $domains): array
    {
        $names = [];
        foreach ($domains as $domain) {
            $name = strtolower(trim((string) ($domain['name'] ?? '')));
            if ($name !== '') {
                $names[$name] = $name;
            }
        }

        $names = array_values($names);
        natcasesort($names);
        $names = array_values($names);

        $parents = [];
        $children = [];

        foreach ($names as $name) {
            $parent = null;
            foreach ($names as $candidate) {
                if ($candidate === $name || !str_ends_with($name, '.' . $candidate)) {
                    continue;
                }

                if ($parent === null || substr_count($candidate, '.') > substr_count($parent, '.')) {
                    $parent = $candidate;
                }
            }

            $parents[$name] = $parent;
            if ($parent !== null) {
                $children[$parent][] = $name;
            }
        }

        foreach ($children as &$childNames) {
            natcasesort($childNames);
            $childNames = array_values($childNames);
        }
        unset($childNames);

        $result = [];
        foreach ($names as $name) {
            if ($parents[$name] !== null) {
                continue;
            }

            self::appendBranch($name, $name, 0, $children, $result);
        }

        return $result;
    }

    /**
     * @param array<string, string[]> $children
     * @param array<int, array{name:string, depth:int, is_subdomain:bool, root:string}> $result
     */
    private static function appendBranch(string $name, string $root, int $depth, array $children, array &$result): void
    {
        $result[] = [
            'name' => $name,
            'depth' => $depth,
            'is_subdomain' => $depth > 0,
            'root' => $root,
        ];

        foreach ($children[$name] ?? [] as $child) {
            self::appendBranch($child, $root, $depth + 1, $children, $result);
        }
    }
}
