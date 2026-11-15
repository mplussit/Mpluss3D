<?php
declare(strict_types=1);

/** Kube med sidelengde s: et rektangulært prisme med like sider. */
final class SolidCube extends SolidCuboid
{
    public function __construct(array $d, int $segments = 128, array $palette = [])
    {
        $s = (float) $d['side'];
        parent::__construct(['side' => $s, 'length' => $s, 'width' => $s, 'height' => $s], $segments, $palette);
    }

    public function shape(): string
    {
        return 'cube';
    }

    public function name(): string
    {
        return 'Kube';
    }

    public function measures(): array
    {
        $s = $this->l();
        return [
            'side' => ['symbol' => 's', 'label' => 'Sidelengde', 'value' => $s, 'unit' => 'mm'],
            'base_perimeter' => ['symbol' => 'O', 'label' => 'Omkrets av en flate', 'value' => 4 * $s, 'unit' => 'mm', 'formula' => '4 · s'],
            'diagonal' => ['symbol' => 'd', 'label' => 'Romdiagonal', 'value' => $s * sqrt(3), 'unit' => 'mm', 'formula' => 's · √3'],
        ];
    }

    public function annotations(): array
    {
        $x = $this->l() / 2;
        return [[[-$x, -$x, 0], [$x, -$x, 0], 's', 'below']];
    }
}
