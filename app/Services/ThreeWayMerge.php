<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ThreeWayMerge
{
    private array $conflicts = [];

    private array $resolutions = [];

    public function merge(?array $base, array $local, ?array $server, array $resolutions = []): array
    {
        $this->conflicts = [];
        $this->resolutions = $resolutions;
        $document = $this->value($base, $local, $server, '', $base === null, false, $server === null);

        return ['document' => $document, 'conflicts' => $this->conflicts];
    }

    private function value(mixed $base, mixed $local, mixed $server, string $path, bool $bm = false, bool $lm = false, bool $sm = false): mixed
    {
        if ($local === $server && $lm === $sm) {
            return $local;
        }
        if ($base === $local && $bm === $lm) {
            return $server;
        }
        if ($base === $server && $bm === $sm) {
            return $local;
        }
        if (! $lm && ! $sm && is_array($local) && is_array($server) && (is_array($base) || $bm)) {
            if (array_is_list($local) && array_is_list($server)) {
                if ($this->identified($local) && $this->identified($server) && ($bm || $this->identified($base))) {
                    $b = array_column($base ?? [], null, 'id');
                    $l = array_column($local, null, 'id');
                    $s = array_column($server, null, 'id');
                    $order = $this->value(array_keys($b), array_keys($l), array_keys($s), $path.'/@order');
                    if (! is_array($order) || ! array_is_list($order) || count(array_filter($order, fn (mixed $id): bool => is_string($id) || is_int($id))) !== count($order)) {
                        throw ValidationException::withMessages(['resolutions' => 'Manual order resolutions must be arrays of content IDs.']);
                    }
                    $result = [];
                    foreach (array_unique([...$order, ...array_keys($l), ...array_keys($s)]) as $id) {
                        $item = $this->value($b[$id] ?? null, $l[$id] ?? null, $s[$id] ?? null, $path.'/'.$this->escape((string) $id), ! isset($b[$id]), ! isset($l[$id]), ! isset($s[$id]));
                        if ($item !== null) {
                            $result[] = $item;
                        }
                    }

                    return $result;
                }
            } elseif (! array_is_list($local) && ! array_is_list($server)) {
                $result = [];
                foreach (array_unique([...array_keys($base ?? []), ...array_keys($local), ...array_keys($server)]) as $key) {
                    $item = $this->value($base[$key] ?? null, $local[$key] ?? null, $server[$key] ?? null, $path.'/'.$this->escape((string) $key), ! array_key_exists($key, $base ?? []), ! array_key_exists($key, $local), ! array_key_exists($key, $server));
                    if ($item !== null || (array_key_exists($key, $local) && $local[$key] === null)) {
                        $result[$key] = $item;
                    }
                }

                return $result;
            }
        }
        $resolution = $this->resolutions[$path] ?? null;
        if ($resolution) {
            return match ($resolution['choice'] ?? '') {
                'local' => $local, 'server' => $server, 'manual' => $resolution['value'] ?? null, default => $local
            };
        }
        $this->conflicts[] = ['path' => $path, 'base' => $base, 'local' => $local, 'server' => $server, 'base_missing' => $bm, 'local_missing' => $lm, 'server_missing' => $sm];

        return $local;
    }

    private function identified(array $items): bool
    {
        return count(array_filter($items, fn (mixed $item): bool => is_array($item) && isset($item['id']))) === count($items);
    }

    private function escape(string $value): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $value);
    }
}
