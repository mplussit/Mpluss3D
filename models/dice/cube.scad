// =====================================================================
// M+ 3D – Parametrisk seksidet terning
// ---------------------------------------------------------------------
// Alle mål er i millimeter. Alle variabler under kan overstyres fra
// kommandolinjen med  -D 'navn=verdi'.
//
//   openscad -o terning.stl -D width=30 -D height=30 -D depth=30 \
//            -D corner_radius=2 -D pip_diameter=3 -D pip_depth=0.5 cube.scad
//
// Koordinatsystem (samme som i forhåndsvisningen og PDF-dekoren):
//   X = bredde  (+X = HØYRE,  -X = VENSTRE)
//   Y = dybde   (-Y = FRONT,  +Y = BAK)
//   Z = høyde   (+Z = TOPP,   bunnen ligger på z = 0)
//
// Hver side har et lokalt 2D-system (u = mot høyre, v = opp) slik siden
// ser ut når man ser rett på den utenfra:
//   FRONT/BAK/VENSTRE/HØYRE: v peker mot TOPP
//   TOPP:  v peker mot BAK    (som når toppen brettes opp fra fronten)
//   BUNN:  v peker mot FRONT  (som når bunnen brettes ned fra fronten)
// PdfGenerator.php bruker nøyaktig samme definisjon, slik at etikettene
// og prikkene på den printede terningen har samme orientering.
// =====================================================================

/* [Mål] */
width  = 30;   // X
height = 30;   // Z
depth  = 30;   // Y

/* [Form] */
corner_radius = 2;      // 0 = skarpe hjørner
pip_diameter  = 3;      // diameter i sideflaten
pip_depth     = 0.5;    // 0 = blanke sider (ingen prikker)
pip_spread    = 0.25;   // avstand fra sentrum til ytre prikker, andel av sidens korteste mål

/* [Innhold per side] (antall prikker, 0 = blank) */
face_top    = 1;
face_bottom = 6;
face_front  = 2;
face_back   = 5;
face_right  = 3;   // 1-2-3 møtes mot klokka (vestlig standard)
face_left   = 4;

/* [Oppløsning] (må være delelig med 4 for eksakte mål) */
corner_segments = 32;
pip_segments    = 24;

// ---------------------------------------------------------------------

// Kule der polene og ekvator har hjørnepunkter, slik at kula når nøyaktig
// ±r i X, Y og Z. (OpenSCADs innebygde sphere() gjør ikke det, og
// terningen ville da blitt litt for liten.)
module exact_sphere(r, n) {
    rotate_extrude($fn = n)
        polygon(concat(
            [[0, -r]],
            [for (i = [1 : n / 2 - 1]) [r * sin(i * 360 / n), -r * cos(i * 360 / n)]],
            [[0, r]]
        ));
}

module body() {
    if (corner_radius <= 0) {
        translate([-width / 2, -depth / 2, 0]) cube([width, depth, height]);
    } else {
        r = corner_radius;
        hull()
            for (x = [-1, 1], y = [-1, 1], z = [-1, 1])
                translate([x * (width / 2 - r), y * (depth / 2 - r), height / 2 + z * (height / 2 - r)])
                    exact_sphere(r, corner_segments);
    }
}

// Prikkposisjoner i enheter av pip_spread (u, v) for 1–6.
function pip_pattern(n) =
    n == 1 ? [[0, 0]] :
    n == 2 ? [[-1, 1], [1, -1]] :
    n == 3 ? [[-1, 1], [0, 0], [1, -1]] :
    n == 4 ? [[-1, -1], [-1, 1], [1, -1], [1, 1]] :
    n == 5 ? [[-1, -1], [-1, 1], [0, 0], [1, -1], [1, 1]] :
    n == 6 ? [[-1, -1], [-1, 0], [-1, 1], [1, -1], [1, 0], [1, 1]] :
    [];

// Sidedefinisjoner: [u, v, n (utover), sentrum, bredde, høyde, antall]
function faces() = [
    [[ 1, 0, 0], [0,  1, 0], [ 0,  0,  1], [0, 0, height],          width, depth,  face_top],
    [[ 1, 0, 0], [0, -1, 0], [ 0,  0, -1], [0, 0, 0],               width, depth,  face_bottom],
    [[ 1, 0, 0], [0,  0, 1], [ 0, -1,  0], [0, -depth / 2, height / 2], width, height, face_front],
    [[-1, 0, 0], [0,  0, 1], [ 0,  1,  0], [0,  depth / 2, height / 2], width, height, face_back],
    [[ 0, 1, 0], [0,  0, 1], [ 1,  0,  0], [ width / 2, 0, height / 2], depth, height, face_right],
    [[ 0,-1, 0], [0,  0, 1], [-1,  0,  0], [-width / 2, 0, height / 2], depth, height, face_left]
];

function frame(f) = [
    [f[0][0], f[1][0], f[2][0], f[3][0]],
    [f[0][1], f[1][1], f[2][1], f[3][1]],
    [f[0][2], f[1][2], f[2][2], f[3][2]],
    [0, 0, 0, 1]
];

// Kulekalott: kula skjærer sideplanet i en sirkel med diameter
// pip_diameter og går pip_depth inn i terningen.
pip_r = pip_diameter / 2;
pip_sphere_r = pip_depth > 0 ? (pip_r * pip_r + pip_depth * pip_depth) / (2 * pip_depth) : 0;

module pips() {
    for (f = faces()) {
        a = pip_spread * min(f[4], f[5]);
        multmatrix(frame(f))
            for (p = pip_pattern(f[6]))
                translate([p[0] * a, p[1] * a, pip_sphere_r - pip_depth])
                    exact_sphere(pip_sphere_r, pip_segments);
    }
}

difference() {
    body();
    if (pip_depth > 0) pips();
}
