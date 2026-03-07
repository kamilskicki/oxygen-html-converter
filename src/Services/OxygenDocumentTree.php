<?php

namespace OxyHtmlConverter\Services;

class OxygenDocumentTree
{
    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    public function build(array $tree): array
    {
        $documentTree = (isset($tree['root']) && is_array($tree['root']))
            ? $tree
            : ['root' => $tree];

        if (!isset($documentTree['_nextNodeId']) || !is_int($documentTree['_nextNodeId']) || $documentTree['_nextNodeId'] < 1) {
            $documentTree['_nextNodeId'] = $this->calculateNextNodeId($documentTree['root'] ?? []);
        }

        if (!isset($documentTree['status']) || !is_string($documentTree['status']) || trim($documentTree['status']) === '') {
            $documentTree['status'] = 'exported';
        }

        return $documentTree;
    }

    /**
     * @param array<string, mixed> $root
     */
    public function calculateNextNodeId(array $root): int
    {
        $maxId = $this->findMaxNodeId($root);

        return max(1, $maxId + 1);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function findMaxNodeId(array $node): int
    {
        $maxId = 0;

        if (isset($node['id']) && is_numeric($node['id'])) {
            $maxId = (int) $node['id'];
        }

        $children = $node['children'] ?? [];
        if (!is_array($children)) {
            return $maxId;
        }

        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }

            $maxId = max($maxId, $this->findMaxNodeId($child));
        }

        return $maxId;
    }
}
