<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Aggregates;

use EugeneErg\Graphs\ValueObjects\Cutout;
use EugeneErg\Graphs\ValueObjects\Piece;
use EugeneErg\Graphs\ValueObjects\Scene;

/**
 * План разреза: что из чего вырезано, куда легло и какими экземплярами.
 *
 * План считается целиком заранее, до первого кадра, и в этом весь смысл.
 * Место куска на столе и экземпляры его вершин назначаются один раз при
 * вырезании и больше не меняются: кусок никогда не переезжает из-за того, что
 * рядом отрезали другой, а экземпляр никогда не достаётся другому куску.
 * Иначе картинка превращается в паровозик, где при каждом разрезе едут все.
 *
 * После каждого шага алгоритма план запоминает состояние — какие куски живы и
 * где лежат. Отрисовке остаётся сравнить соседние состояния: если они
 * различаются, значит на этом шаге и правда что-то произошло.
 */
final class CutPlan
{
    /** Кусок лежит там же, где лежал исходный граф. */
    public const int HOME = -1;

    /** @var Cutout[] */
    private array $cutouts = [];

    /** @var array<int, int> кусок => состояние, в котором он появился */
    private array $births = [];

    /** @var array<int, int> кусок => его место; CutPlan::HOME — исходное место */
    private array $places = [];

    /** @var array<int, int> кусок => стол, на котором он лежит */
    private array $tables = [];

    /** @var array<int, int> стол => сколько мест на нём уже занято */
    private array $slots = [];

    /** @var int[] номера живых кусков */
    private array $live = [];

    /** @var array<int, int> вершина => сколько её экземпляров уже заведено */
    private array $copies = [];

    /** @var array<string, int> ребро => сколько его экземпляров уже заведено */
    private array $edgeCopies = [];

    /** @var array<string, int> ребро => сколько разрезов ему ещё предстоит */
    private array $cuts;

    /** @var array<string, true> экземпляры рёбер, разрезанных один раз из двух */
    private array $half = [];

    /** @var array<int, array{live: int[], places: array<int, int>, acted: ?int, half: array<string, true>}> */
    private array $states = [];

    private ?int $acted = null;

    /**
     * @param array<string, int> $cuts сколько раз каждое ребро ещё разрежут
     */
    public function __construct(Piece $whole, array $cuts)
    {
        $this->cuts = $cuts;
        $this->live = [$this->create($whole, null, 0, self::HOME, [], [])];
        $this->snapshot();
    }

    /**
     * Запомнить, как всё выглядит после очередного шага.
     */
    public function snapshot(): void
    {
        $this->states[] = [
            'live' => $this->live,
            'places' => $this->places,
            'acted' => $this->acted,
            'half' => $this->half,
        ];
        $this->acted = null;
    }

    /**
     * @return array{live: int[], places: array<int, int>, acted: ?int, half: array<string, true>}
     */
    public function getState(int $number): array
    {
        return $this->states[$number] ?? $this->states[count($this->states) - 1];
    }

    public function getLastState(): int
    {
        return count($this->states) - 1;
    }

    /**
     * Изменилось ли что-нибудь на шаге, который привёл в это состояние.
     */
    public function isChanged(int $number): bool
    {
        $before = $this->getState($number - 1);
        $after = $this->getState($number);

        return $before['live'] !== $after['live'] || $before['places'] !== $after['places'];
    }

    /**
     * @return Cutout[]
     */
    public function getCutouts(): array
    {
        return $this->cutouts;
    }

    public function getCutout(int $id): Cutout
    {
        return $this->cutouts[$id];
    }

    public function getBirth(int $id): int
    {
        return $this->births[$id];
    }

    public function getSlotCount(int $table): int
    {
        return $this->slots[$table] ?? 0;
    }

    public function getTable(int $id): int
    {
        return $this->tables[$id];
    }

    /**
     * Столы: у каждого несвязного куска свой. Куски одного стола лежат вокруг
     * него и с чужим столом не пересекаются.
     *
     * @return int[]
     */
    public function getTables(): array
    {
        return array_values(array_unique($this->tables));
    }

    /**
     * Куски, которым досталось место на столе: по ним и меряют, какой шириной
     * раскладывать стол. Остаток лежит там же, где лежал граф, и места
     * не занимает.
     *
     * @return Cutout[]
     */
    public function getSlotted(int $table): array
    {
        $result = [];

        foreach ($this->cutouts as $id => $cutout) {
            if ($this->places[$id] !== self::HOME && $this->tables[$id] === $table) {
                $result[$id] = $cutout;
            }
        }

        return $result;
    }

    /**
     * Вершины стола: по ним считается, где стол стоит и какого он размера.
     *
     * @return int[]
     */
    public function getTableVertexes(int $table): array
    {
        $result = [];

        foreach ($this->cutouts as $id => $cutout) {
            if ($this->tables[$id] === $table) {
                foreach ($cutout->piece->vertexes as $vertex) {
                    $result[$vertex] = $vertex;
                }
            }
        }

        return array_values($result);
    }

    /**
     * Разрезать кусок на части: первая остаётся на месте родителя, остальные
     * уезжают на свои места. Общая вершина достаётся каждой части — своим
     * экземпляром, поэтому связи между частями не остаётся.
     *
     * @param array<int, int[]> $parts
     * @param bool $tables развести части по своим столам: так расходятся
     *                     несвязные куски, у каждого свой стол
     */
    public function split(array $parts, bool $tables = false): void
    {
        $parts = array_values(array_filter($parts, static fn (array $item): bool => $item !== []));

        if (count($parts) < 2) {
            return;
        }

        $parent = $this->findByVertexes(array_merge(...$parts));

        if ($parent === null) {
            return;
        }

        $place = $this->places[$parent];
        $table = $this->tables[$parent];
        $created = [];
        $takenVertexes = [];
        $takenEdges = [];

        foreach ($parts as $number => $vertexes) {
            $part = $this->getInduced($parent, $vertexes);
            [$keptVertexes, $keptEdges] = $this->takeKeys($parent, $part, $takenVertexes, $takenEdges);
            $created[] = $this->create(
                $part,
                $parent,
                $tables ? $this->nextTable() : $table,
                $tables || $number === 0 ? self::HOME : $this->nextSlot($table),
                $keptVertexes,
                $keptEdges,
            );
        }

        $this->kill($parent);

        foreach ($created as $id) {
            $this->live[] = $id;
        }

        $this->acted = $created[1] ?? null;
    }

    /**
     * Вырезать поле из куска, в котором оно целиком лежит.
     *
     * Ребро лежит ровно между двумя полями, поэтому из куска оно уходит только
     * после второго разреза: до тех пор у куска остаётся своя копия. Всё, чего
     * у куска после разреза не осталось, поле забирает вместе с экземплярами.
     */
    public function cutOut(Piece $field): void
    {
        if ($field->edges === []) {
            return;
        }

        $parent = $this->findByEdges($field);

        if ($parent === null) {
            return;
        }

        foreach ($field->edges as [$vertexA, $vertexB]) {
            $name = Scene::edgeName($vertexA, $vertexB);
            $this->cuts[$name] = ($this->cuts[$name] ?? 1) - 1;
        }

        $from = $this->cutouts[$parent];
        $rest = Piece::ofEdges(array_values(array_filter(
            $from->piece->edges,
            fn (array $edge): bool => ($this->cuts[Scene::edgeName($edge[0], $edge[1])] ?? 0) > 0,
        )));
        $taken = $this->getKeysOutside($from, $rest);
        $table = $this->tables[$parent];
        $cut = $this->create($field, $parent, $table, $this->nextSlot($table), $taken[0], $taken[1]);

        $this->kill($parent);

        if (! $rest->isEmpty()) {
            $kept = $this->getKeysInside($from, $rest);
            $this->live[] = $this->create($rest, $parent, $table, $this->places[$parent], $kept[0], $kept[1]);
        }

        $this->live[] = $cut;
        $this->acted = $cut;

        // Ребро лежит между двумя полями, и разрезают его дважды. После
        // первого разреза оно помечается: видно, какие рёбра уже надрезаны,
        // а какие ещё целы.
        foreach ($this->cutouts[$cut]->edges as $key) {
            unset($this->half[$key]);
        }

        if ($rest->isEmpty()) {
            return;
        }

        foreach ($this->cutouts[count($this->cutouts) - 1]->edges as $name => $key) {
            if (($this->cuts[$name] ?? 0) === 1) {
                $this->half[$key] = true;
            }
        }
    }

    /**
     * Раздать места в порядке сборки.
     *
     * Куски не переставляются на глазах: очередь видна сразу, потому что
     * вырезанное сразу ложится на то место, с которого его и возьмут.
     * Перестановка уже разложенных кусков — лишнее движение, за которым
     * не уследить.
     *
     * @param array<int, int> $order вершина => каким по счёту шагом её уложат
     */
    public function orderSlots(array $order): void
    {
        $ranked = [];

        foreach ($this->places as $id => $place) {
            if ($place !== self::HOME) {
                $ranked[$this->tables[$id]][] = [$this->getRank($id, $order), $place, $id];
            }
        }

        $places = [];

        foreach ($ranked as $table) {
            sort($table);

            foreach ($table as $number => [, , $id]) {
                $places[$id] = $number;
            }
        }

        foreach ($places as $id => $place) {
            $this->places[$id] = $place;
        }

        foreach ($this->states as $number => $state) {
            foreach ($places as $id => $place) {
                if (isset($state['places'][$id])) {
                    $this->states[$number]['places'][$id] = $place;
                }
            }
        }
    }

    /**
     * Кусок готов, когда уложена последняя его вершина: по этому шагу он
     * и встаёт в очередь.
     *
     * @param array<int, int> $order
     */
    public function getRank(int $id, array $order): int
    {
        $result = -1;

        foreach ($this->cutouts[$id]->piece->vertexes as $vertex) {
            $result = max($result, $order[$vertex] ?? PHP_INT_MAX);
        }

        return $result;
    }

    /**
     * Живой кусок, на котором сейчас лежит ещё не отрезанный: пока разреза
     * нет, копии стоят поверх своих оригиналов.
     *
     * @param array<int, mixed> $live
     */
    public function getHost(int $id, array $live): ?int
    {
        $result = $this->cutouts[$id]->parent;

        while ($result !== null && ! isset($live[$result])) {
            $result = $this->cutouts[$result]->parent;
        }

        return $result;
    }

    /**
     * @param array<int, string> $keptVertexes вершина => ключ, доставшийся от родителя
     * @param array<string, string> $keptEdges
     */
    private function create(Piece $piece, ?int $parent, int $table, int $place, array $keptVertexes, array $keptEdges): int
    {
        $id = count($this->cutouts);
        $vertexes = [];

        foreach ($piece->vertexes as $vertex) {
            if (isset($keptVertexes[$vertex])) {
                $vertexes[$vertex] = $keptVertexes[$vertex];

                continue;
            }

            $copy = $this->copies[$vertex] ?? 0;
            $this->copies[$vertex] = $copy + 1;
            $vertexes[$vertex] = Scene::vertexKey($vertex, $copy);
        }

        $edges = [];

        foreach ($piece->edges as [$vertexA, $vertexB]) {
            $name = Scene::edgeName($vertexA, $vertexB);

            if (isset($keptEdges[$name])) {
                $edges[$name] = $keptEdges[$name];

                continue;
            }

            $copy = $this->edgeCopies[$name] ?? 0;
            $this->edgeCopies[$name] = $copy + 1;
            $edges[$name] = Scene::edgeKey($vertexA, $vertexB, $copy);
        }

        $this->cutouts[$id] = new Cutout($piece, $parent, $vertexes, $edges);
        $this->births[$id] = count($this->states);
        $this->places[$id] = $place;
        $this->tables[$id] = $table;

        return $id;
    }

    private function kill(int $id): void
    {
        $this->live = array_values(array_filter($this->live, static fn (int $item): bool => $item !== $id));
    }

    private function nextSlot(int $table): int
    {
        $result = $this->slots[$table] ?? 0;
        $this->slots[$table] = $result + 1;

        return $result;
    }

    /**
     * Новый стол: столько же, сколько уже есть.
     */
    private function nextTable(): int
    {
        return count(array_unique($this->tables));
    }

    /**
     * Часть куска, попавшая в заданный набор вершин.
     *
     * @param int[] $vertexes
     */
    private function getInduced(int $parent, array $vertexes): Piece
    {
        $inside = array_flip($vertexes);
        $edges = [];

        foreach ($this->cutouts[$parent]->piece->edges as $edge) {
            if (isset($inside[$edge[0]], $inside[$edge[1]])) {
                $edges[] = $edge;
            }
        }

        return $edges === [] ? new Piece(array_values($vertexes), []) : Piece::ofEdges($edges);
    }

    /**
     * Экземпляры родителя, которые достаются этой части: каждый достаётся
     * только одной, остальные заводят себе новые.
     *
     * @param array<int, true> $takenVertexes
     * @param array<string, true> $takenEdges
     *
     * @return array{array<int, string>, array<string, string>}
     */
    private function takeKeys(int $parent, Piece $part, array &$takenVertexes, array &$takenEdges): array
    {
        $from = $this->cutouts[$parent];
        $vertexes = [];
        $edges = [];

        foreach ($part->vertexes as $vertex) {
            if (isset($from->vertexes[$vertex]) && ! isset($takenVertexes[$vertex])) {
                $takenVertexes[$vertex] = true;
                $vertexes[$vertex] = $from->vertexes[$vertex];
            }
        }

        foreach ($part->edges as [$vertexA, $vertexB]) {
            $name = Scene::edgeName($vertexA, $vertexB);

            if (isset($from->edges[$name]) && ! isset($takenEdges[$name])) {
                $takenEdges[$name] = true;
                $edges[$name] = $from->edges[$name];
            }
        }

        return [$vertexes, $edges];
    }

    /**
     * Экземпляры родителя, которых в остатке больше нет: их забирает
     * отрезанное.
     *
     * @return array{array<int, string>, array<string, string>}
     */
    private function getKeysOutside(Cutout $from, Piece $rest): array
    {
        [$inside, $insideEdges] = $this->getKeysInside($from, $rest);
        $vertexes = [];

        foreach ($from->vertexes as $vertex => $key) {
            if (! isset($inside[$vertex])) {
                $vertexes[$vertex] = $key;
            }
        }

        $edges = [];

        foreach ($from->edges as $name => $key) {
            if (! isset($insideEdges[$name])) {
                $edges[$name] = $key;
            }
        }

        return [$vertexes, $edges];
    }

    /**
     * Экземпляры родителя, которые у него остались.
     *
     * @return array{array<int, string>, array<string, string>}
     */
    private function getKeysInside(Cutout $from, Piece $rest): array
    {
        $vertexes = [];

        foreach ($rest->vertexes as $vertex) {
            if (isset($from->vertexes[$vertex])) {
                $vertexes[$vertex] = $from->vertexes[$vertex];
            }
        }

        $edges = [];

        foreach ($rest->edges as [$vertexA, $vertexB]) {
            $name = Scene::edgeName($vertexA, $vertexB);

            if (isset($from->edges[$name])) {
                $edges[$name] = $from->edges[$name];
            }
        }

        return [$vertexes, $edges];
    }

    /**
     * Живой кусок, в котором целиком лежит это поле.
     */
    private function findByEdges(Piece $piece): ?int
    {
        foreach ($this->live as $id) {
            $edges = $this->cutouts[$id]->edges;
            $inside = true;

            foreach ($piece->edges as [$vertexA, $vertexB]) {
                if (! isset($edges[Scene::edgeName($vertexA, $vertexB)])) {
                    $inside = false;

                    break;
                }
            }

            if ($inside) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Живой кусок, которому принадлежат все эти вершины.
     *
     * @param int[] $vertexes
     */
    private function findByVertexes(array $vertexes): ?int
    {
        foreach ($this->live as $id) {
            $inside = true;

            foreach ($vertexes as $vertex) {
                if (! isset($this->cutouts[$id]->vertexes[$vertex])) {
                    $inside = false;

                    break;
                }
            }

            if ($inside) {
                return $id;
            }
        }

        return null;
    }
}
