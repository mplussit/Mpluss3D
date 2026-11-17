// M+ 3D – «Regn med Lego»: én ramme som fylles med LEGO®-klosser.
//
// Alle mål kommer fra LegoModel (PHP) som -D-variabler, også formen (liste
// med ruter) og teksten (liste med tegnkoder). Fila regner ikke ut mål selv.
//
// Åpningen er unionen av rutene utvidet med klaringen (offset med delta gir
// skarpe hjørner, også innvendige). Ytterkanten er rutene utvidet med klaring
// + vegg, så veggen bygges utover. Det blir aldri vegger mellom rutene.

use <../fractions/fonts/LiberationSans-Bold.ttf>

u = 8;                  // modulmål (mm per knottplass)
cells = [0, 0];         // [kolonne, rad, kolonne, rad, …], rad 0 nederst
ox = 0;                 // forskyvning så rammen ligger midt over origo
oy = 0;
clearance = 0.2;        // ekstra klaring per side
wall = 2;               // veggtykkelse (utover)
inner_h = 9.6;          // innvendig høyde over klossenes støtteflate
bottom_on = 1;
bottom_t = 1.5;

field_on = 0;           // merkefelt: rektangel [fx0, fy0] – [fx1, fy1], like høyt som rammen
fx0 = 0; fy0 = 0; fx1 = 1; fy1 = 1;

text_on = 0;
text_codes = [32];      // tegnkoder (Unicode)
text_size = 5;
text_x = 0;
text_y = 0;
text_rot = 0;
text_raised = 0;        // 0 = innfelt, 1 = opphøyd
text_depth = 0.6;
text_segments = 16;

H = (bottom_on == 1 ? bottom_t : 0) + inner_h;

module cells2d() {
    for (i = [0 : 2 : len(cells) - 1])
        translate([ox + cells[i] * u, oy + cells[i + 1] * u]) square(u);
}

module outline(d) {
    offset(delta = d) union() cells2d();
}

module label_text() {
    translate([text_x, text_y]) rotate(text_rot)
        text(chr(text_codes), size = text_size, font = "Liberation Sans:style=Bold",
             halign = "center", valign = "center", $fn = text_segments);
}

difference() {
    // Ytterkant og merkefelt slås sammen i 2D (ingen sammenfallende flater).
    linear_extrude(H) union() {
        outline(clearance + wall);
        if (field_on == 1)
            translate([fx0, fy0]) square([fx1 - fx0, fy1 - fy0]);
    }
    // Åpningen: over bunnen, eller gjennom hele rammen uten bunn.
    if (bottom_on == 1)
        translate([0, 0, bottom_t]) linear_extrude(inner_h + 1) outline(clearance);
    else
        translate([0, 0, -1]) linear_extrude(H + 2) outline(clearance);
    if (text_on == 1 && text_raised == 0)
        translate([0, 0, H - text_depth]) linear_extrude(text_depth + 1) label_text();
}

if (text_on == 1 && text_raised == 1)
    translate([0, 0, H - 0.01]) linear_extrude(text_depth + 0.01) label_text();
