<?php
/**
 * Modellregister: alle modellgeneratorer i M+ 3D.
 *
 * Nøkkelen er id-en som brukes i API-et (api.php?model=…). Klassen må
 * implementere ParametricModel og ha en statisk load(string $configDir).
 * Se README.md, «Legge til en ny modellgenerator».
 */

declare(strict_types=1);

return [
    'dice'      => DiceModel::class,
    'fractions' => FractionModel::class,
    'base10'    => Base10Model::class,
    'rods'      => RodModel::class,
    'solids'    => SolidModel::class,
];
