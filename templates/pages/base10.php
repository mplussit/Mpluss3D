<?php /** Base 10. Tas inn av public/index.php. Kontrollene fylles fra API-skjemaet. */ ?>
<main class="layout">
    <section class="viewer-wrap" aria-label="3D-forhåndsvisning">
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
            <thead><tr><th scope="col">Objekt</th><th scope="col">Antall</th><th scope="col"><span class="visually-hidden">Last ned</span></th></tr></thead>
            <tbody></tbody>
            <tfoot><tr><th scope="row">Totalt</th><td id="print-total" class="num" colspan="2"></td></tr></tfoot>
        </table>
        <p class="hint panel-hint" id="material-hint">Fargene finnes bare i forhåndsvisningen. Skriv gjerne ut hver objekttype
            i sin egen farge.</p>

        <div id="message" class="message" role="alert" hidden></div>

        <div class="downloads">
            <button type="button" id="download-zip" class="btn btn-primary" disabled>
                <span class="btn-title">Last ned alle STL (ZIP)</span><span class="btn-sub" id="zip-sub">Én fil per objekt, parametere og utskriftsoversikt</span>
            </button>
            <button type="button" id="download-pdf" class="btn btn-secondary" disabled>
                <span class="btn-title">Last ned PDF</span><span class="btn-sub">Oversikt, oppgaver og plassverditabell</span>
            </button>
        </div>
    </aside>
</main>

<dialog id="help-dialog" class="dialog" aria-labelledby="help-title">
    <h2 id="help-title">Hva er STL og PDF?</h2>
    <p><strong>STL</strong> er 3D-modellen. Hver objekttype (ener, tier, hundrer, tusener) har én STL-fil med én kloss.
        Åpne fila i programmet til 3D-printeren (for eksempel Bambu Studio, PrusaSlicer eller Cura) og skriv ut så mange
        eksemplarer som står i lista. ZIP-fila har alle STL-filene, parameterne og en utskriftsoversikt.
        Klossene ligger slik de skal skrives ut og trenger ikke support. Den hule tusenerkuben står med åpningen opp.</p>
    <p><strong>PDF-en</strong> har seks sider: oversikt til læreren, «Bygg tall», «Hvilket tall?», veksling,
        addisjon og subtraksjon, og en plassverditabell. Oppgavene passer til klossene i settet.
        Oppgaver som krever flere klosser enn settet har, er merket «Papiroppgave».</p>
    <p><strong>Tallbyggeren</strong> viser et tall fra 0 til 9999 med klosser. Den er bare en visning og lager ingen nye filer.</p>
    <form method="dialog">
        <button class="btn btn-secondary btn-small" value="close">Lukk</button>
    </form>
</dialog>
