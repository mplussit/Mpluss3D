<?php
declare(strict_types=1);

/**
 * Felles validering av tallparametere og norsk tallformat.
 *
 * Parameterspesifikasjonene har formen fra app/config/models/*.php:
 *   ['label' => …, 'unit' => 'mm', 'min' => …, 'max' => …, 'default' => …]
 */
final class ParameterValidator
{
    /**
     * Validerer alle tallparametere i $specs. Manglende verdier får standardverdi.
     *
     * @param array<string,array<string,mixed>> $specs
     * @return array{0:array<string,float>,1:list<array{field:string,message:string}>}
     */
    public static function numbers(array $specs, array $input): array
    {
        $params = [];
        $errors = [];
        foreach ($specs as $key => $spec) {
            if (!array_key_exists($key, $input) || $input[$key] === '' || $input[$key] === null) {
                $params[$key] = (float) $spec['default'];
                continue;
            }
            $value = self::toNumber($input[$key]);
            if ($value === null) {
                $errors[] = ['field' => $key, 'message' => "{$spec['label']} må være et tall."];
                continue;
            }
            if ($value < $spec['min'] || $value > $spec['max']) {
                $errors[] = ['field' => $key, 'message' => sprintf(
                    '%s må være mellom %s og %s %s.',
                    $spec['label'], self::fmt($spec['min']), self::fmt($spec['max']), $spec['unit']
                )];
                continue;
            }
            $params[$key] = round($value, 2);
        }
        return [$params, $errors];
    }

    /** Godtar int, float og numeriske strenger (også med desimalkomma). */
    public static function toNumber(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return is_finite((float) $v) ? (float) $v : null;
        }
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
            if (preg_match('/^-?\d{1,4}(\.\d{1,4})?$/', $v)) {
                return (float) $v;
            }
        }
        return null;
    }

    /** Sann/usann fra bool, 1/0, "on"/"off", "true"/"false", "ja"/"nei". Null hvis ugyldig. */
    public static function toBool(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v === 1 ? true : ($v === 0 ? false : null);
        }
        if (is_string($v)) {
            return match (strtolower(trim($v))) {
                '1', 'true', 'on', 'ja', 'yes' => true,
                '0', 'false', 'off', 'nei', 'no' => false,
                default => null,
            };
        }
        return null;
    }

    /** Norsk tallformat uten unødvendige desimaler: 2.5 → "2,5". */
    public static function fmt(float|int $v): string
    {
        $s = rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
        return $s === '-0' ? '0' : $s;
    }
}
