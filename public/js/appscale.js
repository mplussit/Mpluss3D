/* ==========================================================================
   M+ 3D – fast appflate, som i M+
   --------------------------------------------------------------------------
   M+ har en fast flate på 840 × 640 som forstørres jevnt til vinduet
   (skala 1–2) og midtstilles vannrett (www/libs/appscale.js i M+). M+ 3D får
   nøyaktig det samme rektangelet, så appen har samme høyde og bredde når man
   går fra menyen i M+ til en 3D-side og tilbake.

   Innholdet er laget for en flate på 1120 × 853 (samme proporsjoner som
   840 × 640) og skaleres med CSS zoom inn i rektangelet. Panelet og innholdet
   under 3D-visningen ruller inne i flaten.

   Smale vinduer (under 840 px, f.eks. mobil) får den vanlige, responsive
   siden i stedet. Utskrift påvirkes ikke.
   ========================================================================== */
(function () {
    'use strict';

    var MPLUS_W = 840;      // M+-flaten
    var MPLUS_H = 640;
    var MIN_SCALE = 1;
    var MAX_SCALE = 2;
    var DESIGN_W = 1120;    // M+ 3D tegnes for denne flaten
    var DESIGN_H = 853;

    var root = document.documentElement;
    var frame = document.getElementById('app-frame');
    var zoom = 1;

    function fit() {
        var fixed = window.innerWidth >= MPLUS_W;
        root.classList.toggle('app-fixed', fixed);
        if (!fixed) {
            zoom = 1;
            frame.style.zoom = '';
            root.style.overflow = '';
            return;
        }
        var s = Math.min(window.innerWidth / MPLUS_W, window.innerHeight / MPLUS_H);
        s = Math.max(MIN_SCALE, Math.min(MAX_SCALE, s));
        zoom = (MPLUS_W * s) / DESIGN_W;
        frame.style.zoom = zoom;
        // Flaten ruller selv. Får den plass i vinduet, skal ikke vinduet rulle
        // (ellers gir innhold som ruller inne i flaten et unødvendig rullefelt).
        root.style.overflow = MPLUS_H * s <= window.innerHeight + 1 ? 'hidden' : '';
        root.style.setProperty('--app-w', DESIGN_W + 'px');
        root.style.setProperty('--app-h', DESIGN_H + 'px');
    }

    var pending = false;
    window.addEventListener('resize', function () {
        if (pending) return;
        pending = true;
        window.requestAnimationFrame(function () {
            pending = false;
            fit();
            window.dispatchEvent(new Event('m3d-scale'));
        });
    });
    window.addEventListener('beforeprint', function () { frame.style.zoom = ''; });
    window.addEventListener('afterprint', fit);

    fit();

    /** Gjeldende forstørrelse av flaten (1 når siden er responsiv). */
    window.m3dScale = { get: function () { return zoom; }, fit: fit };
})();
