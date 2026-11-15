// M+ 3D – romfigurer (kube, rektangulært prisme, pyramide, trekantprisme,
// sylinder, kjegle).
//
// Alle mål kommer fra den felles geometrimodellen i PHP (app/services/Solid*.php)
// som -D-variabler. Fila regner ikke ut mål selv: den bygger figuren og
// eventuelt hulrommet av tallene den får.
//
// Figuren står på byggeplaten (z = 0), midt over origo, med skarpe kanter.

shape = 1;          // 1 rett firkantet prisme (også kube), 2 firkantet pyramide,
                    // 3 trekantet prisme, 4 sylinder, 5 kjegle
sx = 40;            // ytre mål: x (lengde / diameter / grunnside)
sy = 40;            // ytre mål: y (bredde / grunnlinje i trekanten)
sz = 40;            // ytre mål: z (høyde)

hollow = 0;         // 1 = hul figur med lukket hulrom
wall = 2;           // veggtykkelse (gulvet ligger i z = wall)
c_x = 0;            // prisme: indre lengde
c_y = 0;            // prisme: indre bredde
c_top = 0;          // høyeste punkt i hulrommet (møne / topp)
c_half = 0;         // pyramide, trekantprisme, kjegle: halv bredde / radius ved gulvet
c_len = 0;          // trekantprisme: indre lengde
c_r = 0;            // sylinder: indre radius
ridge_x = 0;        // prisme: lengden av mønet i x-retning (0 = punkt)
ridge_y = 0;        // prisme: lengden av mønet i y-retning

segments = 128;     // sider i mangekanten for sylinder og kjegle

PYR_FACES = [[0, 1, 2, 3], [0, 4, 1], [1, 4, 2], [2, 4, 3], [3, 4, 0]];

module pyramid(half, z0, z1) {
    polyhedron(
        points = [[-half, -half, z0], [half, -half, z0], [half, half, z0], [-half, half, z0], [0, 0, z1]],
        faces = PYR_FACES);
}

// Trekant i yz-planet (grunnlinje 2·half ved z0, spiss i z1), langs x.
module tri_prism(len, half, z0, z1) {
    rotate([90, 0, 90]) linear_extrude(height = len, center = true)
        polygon([[-half, z0], [half, z0], [0, z1]]);
}

module outer() {
    if (shape == 1)      translate([-sx / 2, -sy / 2, 0]) cube([sx, sy, sz]);
    else if (shape == 2) pyramid(sx / 2, 0, sz);
    else if (shape == 3) tri_prism(sx, sy / 2, 0, sz);
    else if (shape == 4) cylinder(h = sz, d = sx, $fn = segments);
    else if (shape == 5) cylinder(h = sz, d1 = sx, d2 = 0, $fn = segments);
}

// Saltak langs x (snitt i xz-planet), mønet i c_top. Sider på 45°.
module gable_x(half_ridge, depth) {
    X = c_x / 2 + 1;
    low = wall - 1;
    top = half_ridge > 0.001
        ? [[-half_ridge, c_top], [half_ridge, c_top]]
        : [[0, c_top]];
    rotate([90, 0, 0]) linear_extrude(height = depth, center = true)
        polygon(concat([[-X, c_top - (X - half_ridge)]], top, [[X, c_top - (X - half_ridge)], [X, low], [-X, low]]));
}

// Samme langs y.
module gable_y(half_ridge, depth) {
    Y = c_y / 2 + 1;
    low = wall - 1;
    top = half_ridge > 0.001
        ? [[-half_ridge, c_top], [half_ridge, c_top]]
        : [[0, c_top]];
    rotate([90, 0, 90]) linear_extrude(height = depth, center = true)
        polygon(concat([[-Y, c_top - (Y - half_ridge)]], top, [[Y, c_top - (Y - half_ridge)], [Y, low], [-Y, low]]));
}

module cavity() {
    if (shape == 1) {
        // Indre boks med valmtak: skjæringen av to saltak på 45°.
        intersection() {
            translate([-c_x / 2, -c_y / 2, wall]) cube([c_x, c_y, c_top - wall + 1]);
            gable_x(ridge_x / 2, c_y + 2);
            gable_y(ridge_y / 2, c_x + 2);
        }
    } else if (shape == 2) {
        pyramid(c_half, wall, c_top);
    } else if (shape == 3) {
        tri_prism(c_len, c_half, wall, c_top);
    } else if (shape == 4) {
        // Indre sylinder med kjegleformet tak (45°), toppen i c_top. Bygd som
        // ett omdreiningslegeme (ingen skjæring, så ingen tynne restflater).
        k = c_top - wall;
        rotate_extrude($fn = segments)
            polygon(k > c_r
                ? [[0, wall], [c_r, wall], [c_r, c_top - c_r], [0, c_top]]
                : [[0, wall], [k, wall], [0, c_top]]);
    } else if (shape == 5) {
        translate([0, 0, wall]) cylinder(h = c_top - wall, r1 = c_half, r2 = 0, $fn = segments);
    }
}

if (hollow == 1) {
    difference() {
        outer();
        cavity();
    }
} else {
    outer();
}
