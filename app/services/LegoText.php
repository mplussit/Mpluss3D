<?php
declare(strict_types=1);

/**
 * Tekst på Lego-rammene: tillatte tegn, bredde og størrelse.
 *
 * Teksten i STL-fila skrives med Liberation Sans Bold. Den har de samme
 * breddene som Helvetica Bold, så bredden regnes ut fra FPDFs metrikkfil
 * (helveticab.json) uten å kjøre OpenSCAD. Målt i OpenSCAD (se README):
 *   - sifre og store bokstaver er ca. 0,98 · size høye
 *   - blekkbredden er høyst ca. 1,42 · (bredde i em) · size
 * Tekstens størrelse velges så den alltid får plass i feltet med marg.
 * Testene kontrollerer i STL-fila at teksten ligger innenfor feltet.
 */
final class LegoText
{
    /** Tegn som kan brukes i egen tekst. */
    public const PATTERN = '/^[0-9A-Za-zÆØÅæøå +−×÷=·:.,()?\/]+$/u';

    private const WIDTH_FACTOR = 1.45;       // blekkbredde ≤ faktor · em-bredde · size
    private const TALL = '/[ÆØÅæøåjgpqy()]/u'; // tegn som går over versalhøyden eller under linja

    private static ?array $widths = null;

    /** Gjør teksten penere: «-» blir minustegn, «*» gangetegn. */
    public static function normalize(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        return strtr($s, ['-' => '−', '*' => '×']);
    }

    /** Bredde i em (Helvetica Bold / Liberation Sans Bold). */
    public static function em(string $s): float
    {
        if (self::$widths === null) {
            $json = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/vendor/setasign/fpdf/font/helveticab.json'), true);
            self::$widths = $json['cw'];
        }
        $w = 0;
        foreach (mb_str_split($s) as $ch) {
            $b = match ($ch) {
                '−' => '+',          // minustegnet er like bredt som plusstegnet
                default => @iconv('UTF-8', 'windows-1252', $ch),
            };
            $w += self::$widths[ord((string) $b)] ?? 600;
        }
        return $w / 1000;
    }

    /**
     * Største tekststørrelse (OpenSCAD size, ca. versalhøyde i mm) som får
     * plass i en boks på $w × $h mm, høyst $max.
     */
    public static function fit(string $s, float $w, float $h, float $max): float
    {
        $hf = preg_match(self::TALL, $s) ? 1.55 : 1.0;
        return min($max, $h / $hf, $w / (self::WIDTH_FACTOR * max(self::em($s), 0.1)));
    }

    /** Omtrentlig blekkbredde (mm) ved størrelse $size. */
    public static function width(string $s, float $size): float
    {
        return self::WIDTH_FACTOR * self::em($s) * $size;
    }

    /** Tegnkodene (sendes til OpenSCAD som tall og gjøres om med chr()). @return list<int> */
    public static function codes(string $s): array
    {
        return array_map('mb_ord', mb_str_split($s));
    }
}
