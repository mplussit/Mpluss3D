<?php /** Tallstaver. Tas inn av public/index.php. Kontrollene fylles fra API-skjemaet. */ ?>
<main class="layout">
    <section class="viewer-wrap" aria-label="3D-forhåndsvisning">
        <nav id="sheet-tabs" class="sheet-tabs"></nav>
        <div class="viewer-surface">
            <div id="viewer" class="viewer" tabindex="0" aria-label="3D-modell av tallstavene. Dra for å rotere, rull eller knip for å zoome."></div>
            <div class="view-buttons" role="group" aria-label="Velg visning">
                <button type="button" data-view="iso" aria-pressed="true">Standard</button>
                <button type="button" data-view="top" aria-pressed="false">Ovenfra</button>
            </div>
            <div id="viewer-note" class="viewer-note" role="status" hidden></div>
            <div id="viewer-status" class="viewer-status" role="status" hidden></div>
        </div>

        <div class="viewer-controls">
            <div class="control-group">
                <span class="control-label" id="mode-label">Visning</span>
                <div id="mode-options" class="segmented segmented-compact" role="radiogroup" aria-labelledby="mode-label">
                    <label><input type="radio" name="mode" value="stairs" checked> Trapp</label>
                    <label><input type="radio" name="mode" value="single"> Enkeltstav</label>
                    <label><input type="radio" name="mode" value="combos"> Tallkombinasjoner</label>
                </div>
            </div>
            <div class="control-group" id="single-group" hidden>
                <span class="control-label" id="single-label">Stav</span>
                <div id="single-options" class="segmented segmented-compact rod-options" role="radiogroup" aria-labelledby="single-label"></div>
            </div>
            <div class="control-group builder-group" id="combo-group" hidden>
                <label class="control-label" for="target">Lengde (2–20)</label>
                <div class="builder-row">
                    <input type="number" id="target" class="number-input" min="2" max="20" step="1" value="10" inputmode="numeric">
                    <div class="builder-expr" aria-live="polite">
                        <p id="combo-count" class="num"></p>
                        <p id="combo-list" class="num muted"></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="viewer-info">
            <div>
                <p id="model-info" class="model-info"></p>
                <p class="hint">Dra for å rotere · rull eller knip for å zoome · rutenettet er 10 mm</p>
            </div>
        </div>
    </section>

    <aside class="panel" aria-label="Innstillinger">
        <form id="controls" novalidate>
            <h2 class="panel-heading">Stavene</h2>
            <div id="param-fields"><p class="muted">Laster …</p></div>

            <label class="check">
                <input type="checkbox" id="grid-on">
                <span>Enhetsinndeling (riller på oversiden)</span>
            </label>
            <div id="grid-fields" class="sub-fields" hidden></div>

            <label class="check">
                <input type="checkbox" id="labels-on">
                <span>Tallmerking (nedsenket tall på oversiden)</span>
            </label>
            <div id="label-fields" class="sub-fields" hidden></div>

            <hr class="rule">

            <h2 class="panel-heading" id="lengths-heading">Lengder og antall</h2>
            <div class="rod-tools">
                <button type="button" class="btn btn-secondary btn-small" id="all-lengths">Alle lengder</button>
                <label class="type-count">
                    <span>Antall for alle</span>
                    <input type="number" id="count-all" min="1" max="100" step="1" inputmode="numeric" placeholder="10">
                    <span class="unit">stk</span>
                </label>
            </div>
            <div class="field" data-field="lengths">
                <div id="rod-list" class="type-list" role="group" aria-labelledby="lengths-heading"></div>
                <p class="field-error" data-error-for="lengths"></p>
            </div>

            <hr class="rule">

            <h2 class="panel-heading" id="colors-heading">Farger</h2>
            <div id="color-modes" class="segmented segmented-compact" role="radiogroup" aria-labelledby="colors-heading"></div>
            <div id="single-color-box" class="color-row" hidden>
                <label for="single-color">Farge for alle staver</label>
                <input type="color" id="single-color" value="#f36d21">
            </div>
            <p class="hint panel-hint">Fargene vises bare i forhåndsvisningen og PDF-en. STL-filer har ingen farge:
                ulike farger krever ulike filamenter (bytt mellom lengdene) eller maling etterpå.</p>
            <p class="field-error" data-error-for="color"></p>
        </form>

        <hr class="rule">

        <h2 class="panel-heading">Dette skal skrives ut</h2>
        <table class="print-list" id="print-list">
            <thead><tr><th scope="col">Stav</th><th scope="col">Antall</th></tr></thead>
            <tbody></tbody>
            <tfoot><tr><th scope="row">Totalt</th><td id="print-total" class="num"></td></tr></tfoot>
        </table>
        <p class="hint panel-hint" id="material-hint"></p>

        <div id="message" class="message" role="alert" hidden></div>

    </aside>
</main>
