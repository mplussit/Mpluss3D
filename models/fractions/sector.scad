// M+ 3D – én brøkbrikke (sektor av en sirkel).
//
// Alle verdier settes fra PHP med -D (bare tall). Standardverdiene under gjør
// at fila også kan åpnes direkte i OpenSCAD.
//
// Koordinater (mm):
//   Sirkelens sentrum ligger i origo. Brikka ligger flatt med undersiden på
//   z = 0, og halveringslinja peker langs +Y. Da kan forhåndsvisningen lage en
//   hel sirkel ved å rotere den samme STL-fila 360/nevner grader om gangen.
//
// Klaring (dokumentert metode):
//   Hver radielle kant flyttes parallelt innover med klaring/2. To brikker som
//   ligger inntil hverandre, får dermed en spalte på nøyaktig «klaring» mm langs
//   hele kanten. Ytterradiusen beholdes (diameter/2), slik at sirkelen har samme
//   nominelle diameter i alle brøksett. Spissen havner da litt utenfor sentrum
//   (klaring/2 / sin(halv vinkel)), og det gir også en liten åpning i midten.
//
// Kantavrunding:
//   Bare de øvre kantene avrundes. Undersiden er helt flat og har skarpe kanter,
//   så brikka ligger stødig på byggeplaten og trenger ikke støtte.
//   Alle brikker er konvekse (nevner >= 2 gir vinkel <= 180°), så avrundingen
//   lages som hull() av et prisme og små kuppler i de innfelte hjørnene.

diameter      = 100;
thickness     = 3;
clearance     = 0.3;
edge_radius   = 0.5;
denominator   = 4;

label_on      = 1;      // 1 = nedsenket brøkmerking, 0 = ingen
label_depth   = 0.4;
label_width   = 18;     // boksen teksten skaleres til (beregnet i PHP)
label_height  = 10;
label_dist    = 25;     // tekstens sentrum, avstand fra sirkelsentrum langs +Y
label_rotate  = 0;      // 0 = vannrett tekst, 90 = tekst langs halveringslinja

arc_segments  = 30;     // antall segmenter i ytterbuen (beregnet i PHP)
edge_segments = 12;
text_segments = 16;

use <fonts/LiberationSans-Bold.ttf>

R   = diameter / 2;
phi = 180 / denominator;          // halve sektorvinkelen
gap = clearance / 2;

// Omrisset av brikka i XY, mot klokka.
//   off: avstand fra de radielle kantene (klaring/2, eller mer for innfelt omriss)
//   rr:  radius på ytterbuen
// Samme formel brukes i FractionModel::outline() for kontroll av målene.
function outline(off, rr) =
    denominator == 1
        ? [for (i = [0 : arc_segments - 1]) let(a = 90 + 360 * i / arc_segments) rr * [cos(a), sin(a)]]
        : let(al = asin(off / rr), a0 = 90 - phi + al, a1 = 90 + phi - al)
          concat(
              denominator == 2 ? [] : [[0, off / sin(phi)]],
              [for (i = [0 : arc_segments]) let(a = a0 + (a1 - a0) * i / arc_segments) rr * [cos(a), sin(a)]]
          );

// Kuppel med punkt både på ekvator og i toppen, så høyden blir eksakt.
module dome(r) {
    k = max(2, ceil(edge_segments / 4));
    rotate_extrude($fn = edge_segments)
        polygon(concat([[0, 0]], [for (i = [0 : k]) let(a = 90 * i / k) r * [cos(a), sin(a)]]));
}

module body() {
    if (edge_radius <= 0) {
        linear_extrude(thickness) polygon(outline(gap, R));
    } else {
        hull() {
            linear_extrude(thickness - edge_radius) polygon(outline(gap, R));
            for (p = outline(gap + edge_radius, R - edge_radius))
                translate([p[0], p[1], thickness - edge_radius]) dome(edge_radius);
        }
    }
}

module label() {
    translate([0, label_dist, thickness - label_depth])
        linear_extrude(label_depth + 1)
            rotate(label_rotate)
                resize([label_width, label_height])
                    text(denominator == 1 ? "1" : str("1/", denominator),
                         size = 10, font = "Liberation Sans:style=Bold",
                         halign = "center", valign = "center", $fn = text_segments);
}

difference() {
    body();
    if (label_on == 1) label();
}
