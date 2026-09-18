<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\Scene;
use LogicException;

/**
 * Рисует последовательность кадров в SVG.
 *
 * Граф виден целиком всё время: ничего не исчезает, всё только переезжает.
 * То, что сейчас не в работе, приглушается, и гаснет оно заранее — на время
 * переезда, а не по прибытии: иначе яркая линия летит через рисунок и на глаз
 * пересекает его.
 *
 * Все экземпляры вершин заводятся заранее — и те, что нужны, лишь когда граф
 * разрезан по точкам сочленения. Пока разреза нет, копии стоят поверх
 * оригинала, поэтому раздвоение выглядит как расхождение, а не как вспышка.
 *
 * Движение задаётся тегами SMIL прямо в документе, поэтому картинка
 * самодостаточна — ни скриптов, ни внешних файлов.
 */
final readonly class SvgService
{
    /** Цвета групп: заливки, куски графа, ветви, грани. */
    private const array PALETTE = [
        '#c96442',
        '#5a8f7b',
        '#7b6ea8',
        '#bf8a3d',
        '#4f7ca6',
        '#a2596f',
        '#6f8f45',
        '#8a6a5c',
    ];

    /** Отложенное в сторону не прячется, а приглушается: граф виден целиком. */
    private const string FADED = '0.22';

    public function __construct(
        private float $vertexRadius = 11.0,
        private float $margin = 24.0,
        private float $minVertexGap = 3.2,
        private float $minEdgeGap = 1.2,
        private GeometryService $geometry = new GeometryService(),
    ) {
    }

    /**
     * Неподвижная картинка одной укладки.
     *
     * @param Point2D[] $coordinates
     * @param true[][] $connections
     */
    public function render(array $coordinates, array $connections): string
    {
        $vertexes = [];
        $edges = [];

        foreach ($coordinates as $vertex => $point) {
            $vertexes[Scene::vertexKey($vertex)] = $point;
        }

        foreach ($connections as $vertexA => $connection) {
            foreach (array_keys($connection) as $vertexB) {
                if ($vertexA < $vertexB && isset($coordinates[$vertexA], $coordinates[$vertexB])) {
                    $edges[Scene::edgeKey($vertexA, $vertexB)] = [$coordinates[$vertexA], $coordinates[$vertexB]];
                }
            }
        }

        return $this->animate([new Scene($vertexes, $edges)]);
    }

    /**
     * Картинка проигрывается один раз и замирает на готовой укладке: смотреть
     * на неё интереснее, чем на вечно начинающийся заново рассказ.
     *
     * @param Scene[] $scenes
     * @param float $duration длительность анимации в секундах
     */
    public function animate(array $scenes, float $duration = 30.0, bool $repeat = false): string
    {
        $scenes = $this->scale(array_values($scenes));

        if ($scenes === []) {
            throw new LogicException('Нужен хотя бы один кадр.');
        }

        $keyTimes = $this->getKeyTimes($scenes);
        $body = '';

        foreach ($this->getEdgeKeys($scenes) as $key) {
            $body .= $this->renderEdge($key, $scenes, $keyTimes, $duration, $repeat);
        }

        foreach ($this->getVertexKeys($scenes) as $key) {
            $body .= $this->renderVertex($key, $scenes, $keyTimes, $duration, $repeat);
        }

        return $this->renderDocument($this->getViewBox($scenes), $body);
    }

    /**
     * Масштабирует рассказ так, чтобы в итоговом кадре ближайшие вершины
     * не налезали друг на друга: иначе картинку приходится приближать.
     *
     * @param Scene[] $scenes
     *
     * @return Scene[]
     */
    private function scale(array $scenes): array
    {
        if ($scenes === []) {
            return $scenes;
        }

        $final = $scenes[count($scenes) - 1];
        $distance = $this->getMinVertexDistance($final->vertexes);

        if ($distance === null || $distance < GeometryService::EPSILON) {
            return $scenes;
        }

        // Мало развести вершины: вершина, налезшая на чужое ребро, читается
        // как пересечение, которого нет. Поэтому масштаб берётся такой, чтобы
        // и между вершинами, и между вершиной и чужим ребром был просвет.
        $clearance = $this->getMinEdgeClearance($final);
        $scale = $this->vertexRadius * $this->minVertexGap / $distance;

        if ($clearance !== null && $clearance > GeometryService::EPSILON) {
            $scale = max($scale, $this->vertexRadius * $this->minEdgeGap / $clearance);
        }

        $point = static fn (Point2D $item): Point2D => new Point2D($item->x * $scale, $item->y * $scale);

        return array_map(
            static fn (Scene $scene): Scene => new Scene(
                vertexes: array_map($point, $scene->vertexes),
                edges: array_map(
                    static fn (array $edge): array => [$point($edge[0]), $point($edge[1])],
                    $scene->edges,
                ),
                groups: $scene->groups,
                highlight: $scene->highlight,
                faded: $scene->faded,
                weight: $scene->weight,
                kind: $scene->kind,
            ),
            $scenes,
        );
    }

    /**
     * Насколько близко вершина подошла к чужому ребру в готовой укладке.
     */
    private function getMinEdgeClearance(Scene $scene): ?float
    {
        $result = null;

        foreach ($scene->vertexes as $point) {
            foreach ($scene->edges as [$from, $to]) {
                if ($this->geometry->distance($point, $from) < GeometryService::EPSILON
                    || $this->geometry->distance($point, $to) < GeometryService::EPSILON
                ) {
                    continue;
                }

                $distance = $this->geometry->distanceToSegment($point, $from, $to);
                $result = $result === null ? $distance : min($result, $distance);
            }
        }

        return $result;
    }

    /**
     * Расстояние между ближайшими соседями в кадре.
     *
     * Считается между разными вершинами: экземпляры одной вершины в готовой
     * укладке лежат друг на друге, и если их считать, расстояние всегда ноль
     * и масштабировать нечего.
     *
     * @param Point2D[] $vertexes
     */
    private function getMinVertexDistance(array $vertexes): ?float
    {
        $keys = array_keys($vertexes);
        $count = count($keys);
        $result = null;

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (Scene::vertexOf($keys[$i]) === Scene::vertexOf($keys[$j])) {
                    continue;
                }

                $distance = $this->geometry->distance($vertexes[$keys[$i]], $vertexes[$keys[$j]]);

                if ($result === null || $distance < $result) {
                    $result = $distance;
                }
            }
        }

        return $result;
    }

    /**
     * @param Scene[] $scenes
     *
     * @return string[]
     */
    private function getVertexKeys(array $scenes): array
    {
        $result = [];

        foreach ($scenes as $scene) {
            foreach (array_keys($scene->vertexes) as $key) {
                $result[$key] = true;
            }
        }

        return array_keys($result);
    }

    /**
     * @param Scene[] $scenes
     *
     * @return string[]
     */
    private function getEdgeKeys(array $scenes): array
    {
        $result = [];

        foreach ($scenes as $scene) {
            foreach (array_keys($scene->edges) as $key) {
                $result[$key] = true;
            }
        }

        return array_keys($result);
    }

    /**
     * @param Scene[] $scenes
     * @param float[] $keyTimes
     */
    private function renderEdge(string $key, array $scenes, array $keyTimes, float $duration, bool $repeat): string
    {
        $points = [];
        $shown = [];
        $classes = [];
        $last = null;

        foreach ($scenes as $scene) {
            $last = $scene->edges[$key] ?? $last;
            $points[] = $last;
            $shown[] = ! isset($scene->edges[$key]) ? '0' : (isset($scene->faded[$key]) ? self::FADED : '1');
            // Цвет ребра задаётся классом, а не атрибутом: правило стиля всё
            // равно перебило бы и атрибут, и его анимацию, а внутри анимации
            // переменная темы не работает.
            $classes[] = $this->getEdgeClass($scene->groups[$key] ?? null);
        }

        $first = $this->getFirstDefined($points);

        if ($first === null) {
            return '';
        }

        $shown = $this->fadeWhileMoving($shown);
        $animations = '';

        foreach (['x1' => [0, 'x'], 'y1' => [0, 'y'], 'x2' => [1, 'x'], 'y2' => [1, 'y']] as $attribute => [$end, $axis]) {
            $values = array_map(
                static fn (?array $item): string => self::number(($item ?? $first)[$end]->$axis),
                $points,
            );
            $animations .= $this->renderAnimate($attribute, $values, $keyTimes, $duration, $repeat);
        }

        return sprintf(
            '<line class="%s" x1="%s" y1="%s" x2="%s" y2="%s" opacity="%s">%s%s%s</line>',
            $classes[0],
            self::number($first[0]->x),
            self::number($first[0]->y),
            self::number($first[1]->x),
            self::number($first[1]->y),
            $shown[0],
            $animations,
            $this->renderAnimate('opacity', $shown, $keyTimes, $duration, $repeat, discrete: true),
            count(array_unique($classes)) < 2
                ? ''
                : $this->renderAnimate('class', $classes, $keyTimes, $duration, $repeat, discrete: true),
        );
    }

    /**
     * @param Scene[] $scenes
     * @param float[] $keyTimes
     */
    private function renderVertex(string $key, array $scenes, array $keyTimes, float $duration, bool $repeat): string
    {
        $points = [];
        $shown = [];
        $fills = [];
        $opacities = [];
        $rings = [];
        $last = null;

        foreach ($scenes as $scene) {
            $last = $scene->vertexes[$key] ?? $last;
            $points[] = $last;
            $shown[] = ! isset($scene->vertexes[$key]) ? '0' : (isset($scene->faded[$key]) ? self::FADED : '1');
            $group = $scene->groups[$key] ?? null;
            $fills[] = $this->getGroupColor($group);
            $opacities[] = $group === null ? '0' : '0.35';
            $rings[] = isset($scene->highlight[$key]) ? '1' : '0';
        }

        $first = $this->getFirstDefined($points);

        if ($first === null) {
            return '';
        }

        $shown = $this->fadeWhileMoving($shown);
        $positions = array_map(
            static fn (?Point2D $item): string => self::number(($item ?? $first)->x) . ',' . self::number(($item ?? $first)->y),
            $points,
        );
        $radius = self::number($this->vertexRadius);

        return sprintf(
            '<g class="vertex" transform="translate(%s)" opacity="%s">%s%s'
                . '<circle class="base" r="%s"/>'
                . '<circle class="group" r="%s" fill="%s" fill-opacity="%s">%s%s</circle>'
                . '<circle class="ring" r="%s" opacity="%s">%s</circle>'
                . '<text dominant-baseline="central" text-anchor="middle">%d</text></g>',
            $positions[0],
            $shown[0],
            $this->renderAnimate('transform', $positions, $keyTimes, $duration, $repeat, 'animateTransform'),
            $this->renderAnimate('opacity', $shown, $keyTimes, $duration, $repeat, discrete: true),
            $radius,
            $radius,
            $fills[0],
            $opacities[0],
            $this->renderAnimate('fill', $fills, $keyTimes, $duration, $repeat, discrete: true),
            $this->renderAnimate('fill-opacity', $opacities, $keyTimes, $duration, $repeat, discrete: true),
            self::number($this->vertexRadius + 3),
            $rings[0],
            $this->renderAnimate('opacity', $rings, $keyTimes, $duration, $repeat, discrete: true),
            Scene::vertexOf($key),
        );
    }

    /**
     * Гасит элемент на время переезда, а не по прибытии.
     *
     * Иначе отложенное в сторону летит через рисунок в полную яркость и
     * на глаз пересекает его. Элемент показывается ярко только там, где он
     * на месте и в этом кадре, и в следующем.
     *
     * @param string[] $values
     *
     * @return string[]
     */
    private function fadeWhileMoving(array $values): array
    {
        $count = count($values);

        for ($i = 0; $i < $count - 1; $i++) {
            // Именно приглушение, а не исчезновение: то, чего в кадре нет вовсе,
            // не должно гаснуть заранее и пропадать раньше времени.
            if ($values[$i + 1] === self::FADED && $values[$i] === '1') {
                $values[$i] = self::FADED;
            }
        }

        return $values;
    }

    /**
     * @template T
     *
     * @param array<int, T|null> $values
     *
     * @return T|null
     */
    private function getFirstDefined(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function getGroupColor(?int $group): string
    {
        return self::PALETTE[$group === null ? 0 : abs($group) % count(self::PALETTE)];
    }

    /**
     * Класс ребра: своего цвета у отрезанного поля, особый — у надрезанного
     * ребра, которое ждёт второго разреза.
     */
    private function getEdgeClass(?int $group): string
    {
        if ($group === null) {
            return 'edge';
        }

        return $group < 0 ? 'edge half' : 'edge g' . ($group % count(self::PALETTE));
    }

    /**
     * Доли времени, на которых стоит каждый кадр.
     *
     * @param Scene[] $scenes
     *
     * @return float[]
     */
    private function getKeyTimes(array $scenes): array
    {
        $count = count($scenes);

        if ($count < 2) {
            return [0.0];
        }

        $total = .0;

        for ($i = 1; $i < $count; $i++) {
            $total += $scenes[$i]->weight;
        }

        $result = [0.0];
        $passed = .0;

        for ($i = 1; $i < $count; $i++) {
            $passed += $scenes[$i]->weight;
            $result[] = min($passed / $total, 1.0);
        }

        $result[$count - 1] = 1.0;

        return $result;
    }


    /**
     * @param string[] $values
     * @param float[] $keyTimes
     */
    private function renderAnimate(
        string $attribute,
        array $values,
        array $keyTimes,
        float $duration,
        bool $repeat,
        string $tag = 'animate',
        bool $discrete = false,
    ): string {
        if (count($values) < 2 || count(array_unique($values)) < 2) {
            return '';
        }

        $smoothing = $discrete
            ? ' calcMode="discrete"'
            : sprintf(' calcMode="spline" keySplines="%s"', implode(';', array_fill(0, count($values) - 1, '0.4 0 0.2 1')));

        return sprintf(
            '<%s attributeName="%s"%s dur="%ss" values="%s" keyTimes="%s"%s repeatCount="%s" fill="freeze"/>',
            $tag,
            $attribute,
            $tag === 'animateTransform' ? ' type="translate"' : '',
            self::number($duration),
            implode(';', $values),
            implode(';', array_map(self::number(...), $keyTimes)),
            $smoothing,
            $repeat ? 'indefinite' : '1',
        );
    }


    /**
     * @param Scene[] $scenes
     *
     * @return array{float, float, float, float}
     */
    private function getViewBox(array $scenes): array
    {
        $minX = $minY = INF;
        $maxX = $maxY = -INF;

        foreach ($scenes as $scene) {
            foreach ($scene->vertexes as $point) {
                $minX = min($minX, $point->x);
                $minY = min($minY, $point->y);
                $maxX = max($maxX, $point->x);
                $maxY = max($maxY, $point->y);
            }
        }

        $padding = $this->vertexRadius + $this->margin;

        return [
            $minX - $padding,
            $minY - $padding,
            max($maxX - $minX + $padding * 2, 1.0),
            max($maxY - $minY + $padding * 2, 1.0),
        ];
    }

    /**
     * Правила для цветных рёбер: по одному на цвет палитры.
     */
    private function renderEdgeColors(): string
    {
        $result = '';

        foreach (self::PALETTE as $number => $color) {
            $result .= sprintf('.edge.g%d{stroke:%s}', $number, $color);
        }

        return $result;
    }

    /**
     * @param array{float, float, float, float} $viewBox
     */
    private function renderDocument(array $viewBox, string $body): string
    {
        [$x, $y, $width, $height] = $viewBox;

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s %s %s %s" width="%s" height="%s">'
            . '<style>'
            . ':root{color-scheme:light dark}'
            . 'svg{--paper:#fdfdfc;--line:#3d3929;--edge:#8a8781;--half:#bf8a3d;background:var(--paper)}'
            . '.edge{stroke:var(--edge);stroke-width:1.6;stroke-linecap:round}'
            // Надрезанное ребро: одно поле его уже забрало, второе ещё нет.
            . '.edge.half{stroke:var(--half);stroke-width:2.2}'
            . $this->renderEdgeColors()
            . '.vertex .base{fill:var(--paper);stroke:var(--line);stroke-width:1.8}'
            . '.vertex .group{stroke:none}'
            . '.vertex .ring{fill:none;stroke:#c96442;stroke-width:2.4}'
            . '.vertex text{fill:var(--line);font:600 11px/1 ui-monospace,SFMono-Regular,Menlo,monospace}'
            . '@media (prefers-color-scheme:dark){'
            . 'svg{--paper:#1f1e1d;--line:#e8e6dc;--edge:#6b6862;--half:#d6a354}'
            . '}'
            . '</style>%s</svg>',
            self::number($x),
            self::number($y),
            self::number($width),
            self::number($height),
            self::number($width),
            self::number($height),
            $body,
        );
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
