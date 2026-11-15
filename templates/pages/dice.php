<?php /** Terninggeneratoren. Tas inn av public/index.php. */ ?>
<main class="layout">
    <section class="viewer-wrap" aria-label="3D-forhåndsvisning">
        <div class="viewer-surface">
            <div id="viewer" class="viewer" tabindex="0" aria-label="3D-modell. Dra for å rotere, rull eller knip for å zoome."></div>
            <div class="view-buttons" role="group" aria-label="Velg visning">
                <button type="button" data-view="iso" aria-pressed="true">Standard</button>
                <button type="button" data-view="top" aria-pressed="false">Topp</button>
                <button type="button" data-view="front" aria-pressed="false">Front</button>
                <button type="button" data-view="right" aria-pressed="false">Høyre</button>
                <button type="button" data-view="back" aria-pressed="false">Bak</button>
                <button type="button" data-view="left" aria-pressed="false">Venstre</button>
                <button type="button" data-view="bottom" aria-pressed="false">Bunn</button>
            </div>
            <div id="viewer-status" class="viewer-status" role="status" hidden></div>
        </div>
        <div class="viewer-info">
            <div>
                <p id="model-info" class="model-info"></p>
                <p class="hint">Dra for å rotere · rull eller knip for å zoome · rutenettet er 10 mm</p>
            </div>
            <label class="check"><input type="checkbox" id="show-names" checked> Vis sidenavn</label>
        </div>
    </section>

    <aside class="panel" aria-label="Innstillinger">
        <form id="controls" novalidate>
            <h2 class="panel-heading">Terningen</h2>
            <div id="param-fields"><p class="muted">Laster …</p></div>

            <hr class="rule">

            <h2 class="panel-heading">Dekor (PDF)</h2>
            <div id="decor-options" class="segmented" role="radiogroup" aria-label="Dekor"></div>
            <div id="sheet-preview" class="sheet-preview" aria-hidden="true"></div>
            <label class="check">
                <input type="checkbox" id="label-auto" checked>
                <span>Automatisk etikettstørrelse <span id="label-auto-value" class="num"></span></span>
            </label>
            <div class="field" id="label-size-field" data-field="label" hidden>
                <label for="label-size" class="field-label">Etikettstørrelse</label>
                <div class="input-row">
                    <input type="number" id="label-size" min="5" max="50" step="0.1" inputmode="decimal">
                    <span class="unit">mm</span>
                </div>
                <p class="field-error" data-error-for="label"></p>
            </div>
        </form>

        <div id="message" class="message" role="alert" hidden></div>

        <div class="downloads">
            <button type="button" id="download-stl" class="btn btn-primary" disabled>
                <span class="btn-title">Last ned STL</span><span class="btn-sub">3D-modell til printeren</span>
            </button>
            <button type="button" id="download-pdf" class="btn btn-secondary" disabled>
                <span class="btn-title">Last ned PDF-dekor</span><span class="btn-sub">Etiketter til terningen</span>
            </button>
        </div>
    </aside>
</main>

<dialog id="help-dialog" class="dialog" aria-labelledby="help-title">
    <h2 id="help-title">Hva er STL og PDF?</h2>
    <p><strong>STL</strong> er 3D-modellen. Åpne den i programmet til 3D-printeren
        (for eksempel Bambu Studio, PrusaSlicer eller Cura) og skriv ut.
        Terningen kan printes uten support. Vi anbefaler 0,4 mm dyse og 0,2 mm laghøyde.</p>
    <p><strong>PDF-dekor</strong> er et ark med seks etiketter som passer på sidene av terningen.
        Skriv ut med «Faktisk størrelse» / 100 %, ikke «Tilpass til side».</p>
    <form method="dialog">
        <button class="btn btn-secondary btn-small" value="close">Lukk</button>
    </form>
</dialog>
