<?php

namespace Psalm\Internal\Codebase;

use Psalm\Internal\DataFlow\Path;
use Psalm\Internal\DataFlow\DataFlowNode;
use function substr;
use function strlen;
use function array_reverse;
use function array_sum;
use function array_walk;
use function array_merge;
use function array_keys;

abstract class DataFlowGraph
{
    /** @var array<string, array<string, Path>> */
    protected $forward_edges = [];

    abstract public function addNode(DataFlowNode $node) : void;

    /**
     * @param array<string> $added_taints
     * @param array<string> $removed_taints
     */
    public function addPath(
        DataFlowNode $from,
        DataFlowNode $to,
        string $path_type,
        ?array $added_taints = null,
        ?array $removed_taints = null
    ) : void {
        $from_id = $from->id;
        $to_id = $to->id;

        if ($from_id === $to_id) {
            return;
        }

        $length = 0;

        if ($from->code_location
            && $to->code_location
            && $from->code_location->file_path === $to->code_location->file_path
        ) {
            $to_line = $to->code_location->raw_line_number;
            $from_line = $from->code_location->raw_line_number;
            $length = \abs($to_line - $from_line);
        }

        $this->forward_edges[$from_id][$to_id] = new Path($path_type, $length, $added_taints, $removed_taints);
    }

    /**
     * @param array<string> $previous_path_types
     *
     * @psalm-pure
     */
    protected static function shouldIgnoreFetch(
        string $path_type,
        string $expression_type,
        array $previous_path_types
    ) : bool {
        $el = strlen($expression_type);

        if (substr($path_type, 0, $el + 7) === $expression_type . '-fetch-') {
            $fetch_nesting = 0;

            $previous_path_types = array_reverse($previous_path_types);

            foreach ($previous_path_types as $previous_path_type) {
                if ($previous_path_type === $expression_type . '-assignment') {
                    if ($fetch_nesting === 0) {
                        return false;
                    }

                    $fetch_nesting--;
                }

                if (substr($previous_path_type, 0, $el + 6) === $expression_type . '-fetch') {
                    $fetch_nesting++;
                }

                if (substr($previous_path_type, 0, $el + 12) === $expression_type . '-assignment-') {
                    if ($fetch_nesting > 0) {
                        $fetch_nesting--;
                        continue;
                    }

                    if (substr($previous_path_type, $el + 12) === substr($path_type, $el + 7)) {
                        return false;
                    }

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{int, int, int, float}
     */
    public function getEdgeStats() : array
    {
        $lengths = 0;

        $destination_counts = [];
        $origin_counts = [];

        foreach ($this->forward_edges as $from_id => $destinations) {
            foreach ($destinations as $to_id => $path) {
                if ($path->length === 0) {
                    continue;
                }

                $lengths += $path->length;

                if (!isset($destination_counts[$to_id])) {
                    $destination_counts[$to_id] = 0;
                }

                $destination_counts[$to_id]++;

                $origin_counts[$from_id] = true;
            }
        }

        $count = array_sum($destination_counts);

        if (!$count) {
            return [0, 0, 0, 0.0];
        }

        $mean = $lengths / $count;

        return [$count, \count($origin_counts), \count($destination_counts), $mean];
    }

    /**
     * @psalm-return list<list<string>>
     */
    public function summarizeEdges(): array
    {
        $edges = [];
        array_walk($this->forward_edges, function (array $destinations, string $source) use (&$edges) {
            $edges[] = array_merge([$source], array_keys($destinations));
        });

        return $edges;
    }
}
