// M+ 3D – én base 10-kloss: ener, tier, hundrer eller tusener.
//
// Alle verdier settes fra PHP med -D (bare tall). Standardverdiene under gjør
// at fila også kan åpnes direkte i OpenSCAD (her: en tier).
//
// Koordinater (mm):
//   Klossen er nx × ny × nz enheter. Den står midt over origo i XY, med
//   undersiden på z = 0. Slik ligger den også på byggeplaten:
//     ener      1 × 1 × 1
//     tier     10 × 1 × 1    ligger ned (langs X)
//     hundrer  10 × 10 × 1   ligger flatt
//     tusener  10 × 10 × 10  hul: åpen side OPP (se under)
//
// Kantavrunding:
//   Kantene på oversiden og de loddrette kantene avrundes med radius r. Kantene
//   mot byggeplaten får i stedet en 45° fas av samme størrelse. Da blir det
//   ikke overheng, og fasen motvirker «elefantfot» i første lag. De ytre målene
//   er uendret. Klossen lages som hull() av fire sylindre, fire kupler og et
//   rektangel i bunnen – mye raskere enn minkowski().
//
// Rutenett:
//   Ved hver enhetsgrense (k · enhet) skjæres det en rille med bredde w og
//   dybde d inn fra ALLE ytterflatene som krysser grensa: en skive med
//   tykkelse w minus en kjerne som er d mindre på alle sider. Rillene går
//   dermed rundt staven, danner et 10 × 10-rutenett på hundreren og på alle
//   sider av tusenerkuben, og kan aldri gå gjennom modellen.
//
// Hul tusener:
//   Fem lukkede sider og åpen side. Fila står med den åpne siden opp, som en
//   kopp: da trengs verken støtte eller brobygging (med åpen side ned måtte
//   skriveren ha bygd et tak på nesten 10 cm uten noe under). Snu kuben etter
//   utskrift. Toppkanten (åpningen) får ikke riller.

unit          = 10;
nx            = 10;
ny            = 1;
nz            = 1;
edge_radius   = 0.3;

grid_on       = 1;      // 1 = riller ved hver enhetsgrense
groove_depth  = 0.4;
groove_width  = 0.6;

hollow        = 0;      // 1 = hul med åpen overside (bare tusener)
wall          = 2;

edge_segments = 16;     // segmenter per hel sirkel i avrundingen

Lx = nx * unit;
Ly = ny * unit;
Lz = nz * unit;
r  = edge_radius;
fn = max(8, 4 * ceil(edge_segments / 4));   // delelig med 4: punkt rett ut i ±X og ±Y
d  = groove_depth;
w  = groove_width;

// Halvkule med punkt både på ekvator og i toppen, så høyden blir eksakt.
module dome(rad) {
    k = fn / 4;
    rotate_extrude($fn = fn)
        polygon(concat([[0, 0]], [for (i = [0 : k]) let(a = 90 * i / k) rad * [cos(a), sin(a)]]));
}

module body() {
    if (r <= 0) {
        translate([-Lx / 2, -Ly / 2, 0]) cube([Lx, Ly, Lz]);
    } else {
        hull() {
            // bunnen: fas på 45°
            translate([-Lx / 2 + r, -Ly / 2 + r, 0]) cube([Lx - 2 * r, Ly - 2 * r, 0.01]);
            for (sx = [-1, 1], sy = [-1, 1]) {
                translate([sx * (Lx / 2 - r), sy * (Ly / 2 - r), r])
                    cylinder(r = r, h = Lz - 2 * r, $fn = fn);
                translate([sx * (Lx / 2 - r), sy * (Ly / 2 - r), Lz - r])
                    dome(r);
            }
        }
    }
}

module grooves() {
    difference() {
        union() {
            if (nx > 1) for (k = [1 : nx - 1])
                translate([-Lx / 2 + k * unit - w / 2, -Ly / 2 - 1, -1]) cube([w, Ly + 2, Lz + 2]);
            if (ny > 1) for (k = [1 : ny - 1])
                translate([-Lx / 2 - 1, -Ly / 2 + k * unit - w / 2, -1]) cube([Lx + 2, w, Lz + 2]);
            if (nz > 1) for (k = [1 : nz - 1])
                translate([-Lx / 2 - 1, -Ly / 2 - 1, k * unit - w / 2]) cube([Lx + 2, Ly + 2, w]);
        }
        // Kjernen rillene ikke får gå inn i. For hul kube går den gjennom
        // toppen, så åpningen ikke får hakk.
        translate([-Lx / 2 + d, -Ly / 2 + d, d])
            cube([Lx - 2 * d, Ly - 2 * d, hollow == 1 ? Lz : Lz - 2 * d]);
    }
}

module cavity() {
    translate([-Lx / 2 + wall, -Ly / 2 + wall, wall])
        cube([Lx - 2 * wall, Ly - 2 * wall, Lz]);
}

difference() {
    body();
    if (grid_on == 1 && (nx > 1 || ny > 1 || nz > 1)) grooves();
    if (hollow == 1) cavity();
}
