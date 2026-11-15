// M+ 3D – én tallstav (Cuisenaire-stav) med lengde 1–10 enheter.
//
// Alle verdier settes fra PHP med -D (bare tall). Standardverdiene under gjør
// at fila også kan åpnes direkte i OpenSCAD (her: stav 5 med inndeling og tall).
//
// Koordinater (mm):
//   Staven ligger langs X, midt over origo i XY, med undersiden på z = 0.
//   Lengde n · enhet, tverrsnitt enhet × enhet. Slik ligger den også på
//   byggeplaten: alle riller og all merking er på oversiden.
//
// Kantavrunding:
//   Bare de fire langsgående kantene. De to øverste avrundes med radius r; de
//   to mot byggeplaten får en 45° fas av samme størrelse, så det ikke blir
//   overheng. Tverrsnittet ekstruderes langs X, så endeflatene er helt plane
//   og lengden er nøyaktig n · enhet: to staver lagt ende mot ende er like
//   lange som summen.
//
// Enhetsinndeling:
//   Ved hver enhetsgrense (k · enhet, k = 1 … n−1) skjæres en rille med bredde
//   w og dybde d ned i oversiden, på tvers av staven. Rillene går ikke på
//   endeflatene og aldri gjennom staven.
//
// Tallmerking:
//   Tallet senkes ned i oversiden, sentrert i boksen som PHP har beregnet
//   (RodModel::labelLayout): innenfor den flate delen av oversiden og, med
//   inndeling, inne i én enhet, så det aldri overlapper en rille.

unit          = 10;
n             = 5;
edge_radius   = 0.5;

grid_on       = 1;
groove_depth  = 0.3;
groove_width  = 0.5;

label_on      = 1;
label_depth   = 0.4;
label_width   = 4.2;    // boksen teksten skaleres til (beregnet i PHP)
label_height  = 6;
label_x       = 0;      // tekstens sentrum langs staven

edge_segments = 16;     // segmenter per hel sirkel i avrundingen
text_segments = 16;

use <../fractions/fonts/LiberationSans-Bold.ttf>

L = n * unit;
u = unit;
r = edge_radius;
q = max(2, ceil(edge_segments / 4));   // segmenter per kvartsirkel

// Tverrsnittet (Y, Z): fas nede, avrunding oppe.
function profile() =
    r <= 0
        ? [[-u / 2, 0], [u / 2, 0], [u / 2, u], [-u / 2, u]]
        : concat(
            [[-u / 2 + r, 0], [u / 2 - r, 0], [u / 2, r]],
            [for (i = [0 : q]) let(a = 90 * i / q) [u / 2 - r + r * cos(a), u - r + r * sin(a)]],
            [for (i = [0 : q]) let(a = 90 + 90 * i / q) [-u / 2 + r + r * cos(a), u - r + r * sin(a)]],
            [[-u / 2, r]]
          );

module body() {
    // 2D-x → Y, 2D-y → Z, ekstruderingen → X
    rotate([90, 0, 90])
        linear_extrude(L, center = true) polygon(profile());
}

module grooves() {
    for (k = [1 : n - 1])
        translate([-L / 2 + k * unit - groove_width / 2, -u / 2 - 1, u - groove_depth])
            cube([groove_width, u + 2, groove_depth + 1]);
}

module label() {
    translate([label_x, 0, u - label_depth])
        linear_extrude(label_depth + 1)
            resize([label_width, label_height])
                text(str(n), size = 10, font = "Liberation Sans:style=Bold",
                     halign = "center", valign = "center", $fn = text_segments);
}

difference() {
    body();
    if (grid_on == 1 && n > 1) grooves();
    if (label_on == 1) label();
}
