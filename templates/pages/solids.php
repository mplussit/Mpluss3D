<?php /** Romfigurer og utbrettingsmodeller. Tas inn av public/index.php. Kontrollene fylles fra API-skjemaet. */ ?>
<main class="layout">
    <section class="viewer-wrap" aria-label="Forhåndsvisning">
        <nav id="sheet-tabs" class="sheet-tabs"></nav>
        <div class="viewer-surface solid-views" id="solid-views" data-show="3d">
            <div class="solid-3d">
                <div id="viewer" class="viewer" tabindex="0" aria-label="3D-modell av romfiguren. Dra for å rotere, rull eller knip for å zoome."></div>
                <div class="view-buttons" role="group" aria-label="Velg retning">
                    <button type="button" data-view="iso" aria-pressed="true" title="Tilbakestill visningen">Standard</button>
                    <button type="button" data-view="front" aria-pressed="false">Forfra</button>
                    <button type="button" data-view="side" aria-pressed="false">Fra siden</button>
                    <button type="button" data-view="top" aria-pressed="false">Ovenfra</button>
                </div>
            </div>
            <div class="solid-net" id="net-view" aria-label="Utbrettingsfigur (2D)"></div>
            <div id="viewer-status" class="viewer-status" role="status" hidden></div>
        </div>

        <div class="viewer-controls">
            <div class="control-group">
                <span class="control-label" id="show-label">Vis</span>
                <div id="show-options" class="segmented segmented-compact" role="radiogroup" aria-labelledby="show-label">
                    <label><input type="radio" name="show" value="3d" checked> 3D-modell</label>
                    <label><input type="radio" name="show" value="net"> Utbrettingsfigur</label>
                    <label class="show-both"><input type="radio" name="show" value="both"> Begge</label>
                </div>
            </div>
        </div>

        <div class="viewer-info">
            <div>
                <p id="model-info" class="model-info"></p>
                <p class="hint">Dra for å rotere · rull eller knip for å zoome · «Standard» tilbakestiller visningen · rutenettet er 10 mm</p>
            </div>
        </div>
    </section>

    <aside class="panel" aria-label="Innstillinger">
        <form id="controls" novalidate>
            <h2 class="panel-heading" id="shape-heading">Romfigur</h2>
            <div id="shape-options" class="segmented shape-options" role="radiogroup" aria-labelledby="shape-heading"></div>
            <p class="field-error" data-error-for="shape"></p>

            <h2 class="panel-heading">Mål</h2>
            <div id="dim-fields"><p class="muted">Laster …</p></div>

            <hr class="rule">

            <h2 class="panel-heading" id="model-heading">3D-modell</h2>
            <div id="hollow-options" class="segmented segmented-compact" role="radiogroup" aria-labelledby="model-heading">
                <label><input type="radio" name="hollow" value="0" checked> Massiv</label>
                <label><input type="radio" name="hollow" value="1"> Hul</label>
            </div>
            <div id="wall-fields" class="sub-fields" hidden></div>
            <p class="hint panel-hint" id="hollow-hint">Massiv: én lukket figur, sliceren bestemmer fyllgraden. Hul: tynt skall,
                tilgjengelig for alle seks figurene.</p>

            <h2 class="panel-heading panel-heading-gap">Visning</h2>
            <label class="check"><input type="checkbox" id="show-faces"> <span>Vis flater (hver flate i sin farge)</span></label>
            <label class="check"><input type="checkbox" id="show-edges"> <span>Vis kanter og hjørner</span></label>

            <hr class="rule">

            <h2 class="panel-heading">Utbrettingsfigur</h2>
            <div id="net-fields"></div>
            <p id="net-status" class="net-status" role="status"></p>
            <div id="net-print-box" hidden>
                <div id="net-print-options" class="segmented segmented-compact stacked" role="radiogroup" aria-label="Store nett"></div>
            </div>

            <hr class="rule">

            <h2 class="panel-heading" id="level-heading">Oppgaver</h2>
            <div id="level-options" class="segmented segmented-compact stacked" role="radiogroup" aria-labelledby="level-heading"></div>
        </form>

        <div id="message" class="message" role="alert" hidden></div>


        <hr class="rule">

        <section class="props-card" aria-labelledby="props-heading">
            <h2 class="panel-heading" id="props-heading">Geometriske egenskaper</h2>
            <dl id="props" class="props"></dl>
            <p id="props-note" class="hint" hidden></p>
            <details class="props-more">
                <summary>Flere mål og formler</summary>
                <table class="props-table" id="measures"><tbody></tbody></table>
            </details>
        </section>
    </aside>
</main>
