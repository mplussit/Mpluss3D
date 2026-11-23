<?php /** Base 10. Tas inn av public/index.php. Kontrollene fylles fra API-skjemaet. */ ?>
<main class="layout">
    <section class="viewer-wrap" aria-label="3D-forhåndsvisning">
        <nav id="sheet-tabs" class="sheet-tabs"></nav>
        <div class="viewer-surface">
            <div id="viewer" class="viewer" tabindex="0" aria-label="3D-modell av base 10-klossene. Dra for å rotere, rull eller knip for å zoome."></div>
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
                    <label><input type="radio" name="mode" value="set" checked> Samlet</label>
                    <label><input type="radio" name="mode" value="single"> Enkeltobjekt</label>
                    <label><input type="radio" name="mode" value="builder"> Tallbygger</label>
                </div>
            </div>
            <div class="control-group" id="single-group" hidden>
                <span class="control-label" id="single-label">Objekt</span>
                <div id="single-options" class="segmented segmented-compact" role="radiogroup" aria-labelledby="single-label"></div>
            </div>
            <div class="control-group builder-group" id="builder-group" hidden>
                <label class="control-label" for="number">Tall (0–9999)</label>
                <div class="builder-row">
                    <input type="number" id="number" class="number-input" min="0" max="9999" step="1" value="243" inputmode="numeric">
                    <div class="builder-expr" aria-live="polite">
                        <p id="number-sum" class="num"></p>
                        <p id="number-terms" class="num muted"></p>
                    </div>
                </div>
                <p id="number-words" class="number-words"></p>
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
            <h2 class="panel-heading">Klossene</h2>
            <div id="param-fields"><p class="muted">Laster …</p></div>

            <label class="check">
                <input type="checkbox" id="grid-on" checked>
                <span>Rutenett / inndeling (riller ved hver ener)</span>
            </label>
            <div id="grid-fields" class="sub-fields"></div>

            <hr class="rule">

            <h2 class="panel-heading" id="types-heading">Objekter og antall</h2>
            <div class="field" data-field="types">
                <div id="type-list" class="type-list" role="group" aria-labelledby="types-heading"></div>
                <p class="field-error" data-error-for="types"></p>
            </div>

            <div id="thousand-box" hidden>
                <label class="check">
                    <input type="checkbox" id="hollow-on" checked>
                    <span>Hul tusenerkloss (sparer filament)</span>
                </label>
                <div id="thousand-fields" class="sub-fields"></div>
            </div>
        </form>

        <hr class="rule">

        <h2 class="panel-heading">Dette skal skrives ut</h2>
        <table class="print-list" id="print-list">
            <thead><tr><th scope="col">Objekt</th><th scope="col">Antall</th></tr></thead>
            <tbody></tbody>
            <tfoot><tr><th scope="row">Totalt</th><td id="print-total" class="num"></td></tr></tfoot>
        </table>
        <p class="hint panel-hint" id="material-hint">Fargene finnes bare i forhåndsvisningen. Skriv gjerne ut hver objekttype
            i sin egen farge.</p>

        <div id="message" class="message" role="alert" hidden></div>

    </aside>
</main>
