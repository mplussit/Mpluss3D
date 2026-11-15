<?php
declare(strict_types=1);

/**
 * Plasserer et utbrettingsnett på A4-ark. Felles for PDF-en og 2D-visningen,
 * så de viser nøyaktig samme oppsett.
 *
 * Prioritering (målestokk 1:1 er standard):
 *   1. Ett A4-ark i 1:1, stående eller liggende.
 *   2. Andre nettvarianter og rotasjoner, i rekkefølgen figuren foretrekker.
 *   3. Får ingen plass: flere A4-ark i 1:1 med overlapp og sammenføyningsmerker
 *      ($mode = 'tiles', standard), eller
 *   4. et skalert nett på ett ark, tydelig merket ($mode = 'scaled').
 * Nettet skaleres aldri ned uten at det står på arket og i grensesnittet.
 */
final class NetLayout
{
    public const MARGIN = 10.0;     // mm fra arkkanten
    public const TOP = 33.0;        // topptekst (tittel, målestokk, 100 %-varsel)
    public const BOTTOM = 25.0;     // forklaring og kontrollinje på 50 mm
    public const OVERLAP = 12.0;    // overlapp mellom ark ved flersidig utskrift
    public const SCALES = [0.8, 2 / 3, 0.5, 0.4, 1 / 3, 0.25, 0.2];

    /** @return array{float,float} [bredde, høyde] */
    public static function page(string $o): array
    {
        return $o === 'L' ? [297.0, 210.0] : [210.0, 297.0];
    }

    /** Området nettet kan ligge i: [x, y, w, h] på arket. */
    public static function area(string $o): array
    {
        [$W, $H] = self::page($o);
        return [self::MARGIN, self::TOP, $W - 2 * self::MARGIN, $H - self::TOP - self::BOTTOM];
    }

    /**
     * @param list<SolidNet> $nets nettvarianter, den foretrukne først (normaliserte)
     * @return array<string,mixed>
     */
    public static function plan(array $nets, string $mode = 'tiles'): array
    {
        // 1–2: første variant som får plass på ett ark i 1:1
        foreach ($nets as $i => $net) {
            foreach (['P', 'L'] as $o) {
                [$ax, $ay, $aw, $ah] = self::area($o);
                if ($net->width() <= $aw + 1e-9 && $net->height() <= $ah + 1e-9) {
                    return [
                        'fits'        => true,
                        'mode'        => 'single',
                        'variant'     => $i,
                        'orientation' => $o,
                        'scale'       => 1.0,
                        'size'        => [$net->width(), $net->height()],
                        'pages'       => [[
                            'label'  => null,
                            'window' => [0.0, 0.0, $net->width(), $net->height()],
                            'origin' => [$ax + ($aw - $net->width()) / 2, $ay + ($ah - $net->height()) / 2],
                        ]],
                    ];
                }
            }
        }

        // 3: færrest ark i 1:1 (ved likt antall: den foretrukne varianten)
        $best = null;
        foreach ($nets as $i => $net) {
            foreach (['P', 'L'] as $o) {
                [, , $aw, $ah] = self::area($o);
                $cols = self::count($net->width(), $aw);
                $rows = self::count($net->height(), $ah);
                if ($best === null || $cols * $rows < $best['n']) {
                    $best = ['n' => $cols * $rows, 'i' => $i, 'o' => $o, 'cols' => $cols, 'rows' => $rows];
                }
            }
        }
        $net = $nets[$best['i']];
        $tiles = self::tiles($net, $best['o'], $best['cols'], $best['rows']);
        $scaled = self::scaled($nets);

        if ($mode === 'scaled') {
            return [
                'fits'        => false,
                'mode'        => 'scaled',
                'variant'     => $scaled['variant'],
                'orientation' => $scaled['orientation'],
                'scale'       => $scaled['scale'],
                'scale_label' => $scaled['label'],
                'size'        => [$nets[$scaled['variant']]->width(), $nets[$scaled['variant']]->height()],
                'pages'       => [['label' => null, 'window' => [0.0, 0.0, $nets[$scaled['variant']]->width(), $nets[$scaled['variant']]->height()], 'origin' => $scaled['origin']]],
                'tiles'       => ['count' => $best['n'], 'rows' => $best['rows'], 'cols' => $best['cols']],
            ];
        }
        return [
            'fits'        => false,
            'mode'        => 'tiles',
            'variant'     => $best['i'],
            'orientation' => $best['o'],
            'scale'       => 1.0,
            'size'        => [$net->width(), $net->height()],
            'rows'        => $best['rows'],
            'cols'        => $best['cols'],
            'pages'       => $tiles,
            'overlap'     => self::OVERLAP,
            'overview'    => self::overview($tiles, [self::MARGIN, 62.0, 190.0, 195.0]),
            'scaled_alt'  => ['scale' => $scaled['scale'], 'label' => $scaled['label']],
        ];
    }

    /** Antall ark langs én retning med overlapp. */
    private static function count(float $len, float $avail): int
    {
        return $len <= $avail + 1e-9 ? 1 : (int) ceil(($len - self::OVERLAP) / ($avail - self::OVERLAP) - 1e-9);
    }

    /**
     * Arkene ved flersidig utskrift. window = utsnitt av nettet (mm, nettets
     * koordinater), origin = hvor utsnittets øvre venstre hjørne havner på arket.
     */
    private static function tiles(SolidNet $net, string $o, int $cols, int $rows): array
    {
        [$ax, $ay, $aw, $ah] = self::area($o);
        $W = $net->width();
        $H = $net->height();
        // Fordel overskuddet jevnt, så nettet havner midt i rutenettet av ark.
        $stepX = $aw - self::OVERLAP;
        $stepY = $ah - self::OVERLAP;
        $x0 = -($cols * $stepX + self::OVERLAP - $W) / 2;
        $y0 = -($rows * $stepY + self::OVERLAP - $H) / 2;
        $pages = [];
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $pages[] = [
                    'label'  => chr(65 + $r) . ($c + 1),      // A1, A2, …, B1 …
                    'row'    => $r,
                    'col'    => $c,
                    'window' => [$x0 + $c * $stepX, $y0 + $r * $stepY, $aw, $ah],
                    'origin' => [$ax, $ay],
                ];
            }
        }
        return $pages;
    }

    /** Største «pene» målestokk der en variant får plass på ett ark. */
    private static function scaled(array $nets): array
    {
        foreach (self::SCALES as $s) {
            foreach ($nets as $i => $net) {
                foreach (['P', 'L'] as $o) {
                    [$ax, $ay, $aw, $ah] = self::area($o);
                    if ($net->width() * $s <= $aw && $net->height() * $s <= $ah) {
                        return [
                            'variant' => $i, 'orientation' => $o, 'scale' => $s, 'label' => self::scaleLabel($s),
                            'origin'  => [$ax + ($aw - $net->width() * $s) / 2, $ay + ($ah - $net->height() * $s) / 2],
                        ];
                    }
                }
            }
        }
        throw new LogicException('Nettet er for stort selv i målestokk 1:5.');
    }

    /**
     * Oversiktsbildet: alle arkene (og dermed hele nettet) forminsket inn i $box.
     * origin er der nettets (0, 0) havner.
     */
    private static function overview(array $tiles, array $box): array
    {
        [$bx, $by, $bw, $bh] = $box;
        $x0 = min(array_map(fn($t) => $t['window'][0], $tiles));
        $y0 = min(array_map(fn($t) => $t['window'][1], $tiles));
        $x1 = max(array_map(fn($t) => $t['window'][0] + $t['window'][2], $tiles));
        $y1 = max(array_map(fn($t) => $t['window'][1] + $t['window'][3], $tiles));
        $s = min($bw / ($x1 - $x0), $bh / ($y1 - $y0));
        return [
            'scale'  => $s,
            'label'  => self::scaleLabel($s),
            'origin' => [$bx + ($bw - ($x1 - $x0) * $s) / 2 - $x0 * $s, $by + ($bh - ($y1 - $y0) * $s) / 2 - $y0 * $s],
        ];
    }

    /** «1 : 2» eller «1 : 1,5». */
    public static function scaleLabel(float $s): string
    {
        $d = 1 / $s;
        $txt = rtrim(rtrim(number_format($d, 2, ',', ''), '0'), ',');
        return "1 : $txt";
    }
}
