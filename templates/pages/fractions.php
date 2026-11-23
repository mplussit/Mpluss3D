<?php /** Brøksirklene. Tas inn av public/index.php. Kontrollene fylles fra API-skjemaet. */ ?>
<main class="layout">
    <section class="viewer-wrap" aria-label="3D-forhåndsvisning">
        <nav id="sheet-tabs" class="sheet-tabs"></nav>
        <div class="viewer-surface">
            <div id="viewer" class="viewer" tabindex="0" aria-label="3D-modell av brøkbrikkene. Dra for å rotere, rull eller knip for å zoome."></div>
            <div class="view-buttons" role="group" aria-label="Velg visning">
                <button type="button" data-view="iso" aria-pressed="true">Standard</button>
                <button type="button" data-view="top" aria-pressed="false">Ovenfra</button>
            </div>
            <div id="viewer-status" class="viewer-status" role="status" hidden></div>
        </div>

        <div class="viewer-controls">
            <div class="control-group">
                <span class="control-label" id="set-label">Brøksett</span>
                <div id="set-options" class="segmented segmented-compact" role="radiogroup" aria-labelledby="set-label"></div>
            </div>
            <div class="control-group">
                <span class="control-label" id="mode-label">Visning</span>
                <div id="mode-options" class="segmented segmented-compact" role="radiogroup" aria-labelledby="mode-label">
                    <label><input type="radio" name="mode" value="whole" checked> Samlet</label>
                    <label><input type="radio" name="mode" value="separated"> Separert</label>
                    <label><input type="radio" name="mode" value="single"> Én brikke</label>
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
            <h2 class="panel-heading">Brøksirklene</h2>
            <div id="param-fields"><p class="muted">Laster …</p></div>

            <hr class="rule">

            <h2 class="panel-heading" id="den-heading">Nevnere</h2>
            <div class="field" data-field="denominators">
                <div id="den-options" class="den-grid" role="group" aria-labelledby="den-heading"></div>
                <div class="den-presets">
                    <button type="button" class="link-btn" data-preset="default">Standardsett</button>
                    <button type="button" class="link-btn" data-preset="all">Alle 1–12</button>
                </div>
                <p class="field-error" data-error-for="denominators"></p>
            </div>

            <hr class="rule">

            <h2 class="panel-heading">Brøkmerking</h2>
            <label class="check">
                <input type="checkbox" id="labels-on" checked>
                <span>Nedsenket brøkmerking, for eksempel 1/4</span>
            </label>
            <div id="label-fields"></div>
        </form>

        <hr class="rule">

        <h2 class="panel-heading">Dette skal skrives ut</h2>
        <table class="print-list" id="print-list">
            <thead><tr><th scope="col">Brøk</th><th scope="col">Antall</th></tr></thead>
            <tbody></tbody>
            <tfoot><tr><th scope="row">Totalt</th><td id="print-total" class="num"></td></tr></tfoot>
        </table>
        <p class="hint panel-hint">Fargene finnes bare i forhåndsvisningen. Skriv gjerne ut hvert brøksett
            i filament med tilsvarende farge, så blir settene lette å skille fra hverandre.</p>

        <div id="message" class="message" role="alert" hidden></div>

    </aside>
</main>
