<?php /** Kulespill med ark. Tas inn av public/index.php. Arkene lages av serveren (MarbleSheets) og vises i PDF-fanene. */ ?>
<main class="layout">
    <section class="viewer-wrap" aria-label="Forhåndsvisning">
        <nav id="sheet-tabs" class="sheet-tabs"></nav>
        <div class="viewer-surface marble-views" id="marble-views" data-show="3d">
            <div class="marble-3d">
                <div id="viewer" class="viewer" tabindex="0" aria-label="3D-modell av kulespillet med bunnen og hullarket. Dra for å rotere, rull eller knip for å zoome."></div>
                <div class="view-buttons" role="group" aria-label="Velg retning">
                    <button type="button" data-view="iso" aria-pressed="true" title="Tilbakestill visningen">Standard</button>
                    <button type="button" data-view="top" aria-pressed="false">Ovenfra</button>
                    <button type="button" data-view="front" aria-pressed="false">Forfra</button>
                </div>
            </div>
            <div id="viewer-status" class="viewer-status" role="status" hidden></div>
        </div>

        <div class="viewer-controls">
            <div class="control-group" id="lift-group">
                <span class="control-label">Arket</span>
                <label class="check"><input type="checkbox" id="lift-board"> <span>Løft spillet og se arket</span></label>
            </div>
        </div>

        <div class="viewer-info">
            <div>
                <p id="model-info" class="model-info"></p>
                <p class="hint" id="view-hint">Dra for å rotere · rull eller knip for å zoome · arket i 3D er hullarket til første sett</p>
            </div>
        </div>
    </section>

    <aside class="panel" aria-label="Innstillinger for arkene">
        <form id="controls" novalidate>
            <h2 class="panel-heading" id="layout-heading">1 · Kulespill</h2>
            <div id="layout-options" class="segmented segmented-compact layout-options" role="radiogroup" aria-labelledby="layout-heading"></div>
            <p class="field-help" id="layout-help"></p>
            <p class="field-error" data-error-for="layout"></p>

            <hr class="rule">

            <h2 class="panel-heading" id="content-heading">2 · I hullene</h2>
            <div id="content-options" class="segmented segmented-compact content-options" role="radiogroup" aria-labelledby="content-heading"></div>
            <p class="field-error" data-error-for="content"></p>

            <div id="opt-numbers" class="opt-box">
                <div class="range-row" data-field="num_max">
                    <span class="range-label">Tall fra</span>
                    <input type="number" class="small-number" id="f-num_min" min="0" max="999" step="1" inputmode="numeric" aria-label="Tall fra">
                    <span>til</span>
                    <input type="number" class="small-number" id="f-num_max" min="0" max="999" step="1" inputmode="numeric" aria-label="Tall til">
                </div>
                <p class="field-error" data-error-for="num_min"></p>
                <p class="field-error" data-error-for="num_max"></p>
            </div>

            <div id="opt-expressions" class="opt-box">
                <span class="control-label">Regnearter</span>
                <div id="ops-options" class="segmented segmented-compact op-options" role="group" aria-label="Regnearter"></div>
                <p class="field-error" data-error-for="ops"></p>
                <span class="control-label">Tallområde</span>
                <div id="max-options" class="segmented segmented-compact" role="radiogroup" aria-label="Tallområde"></div>
                <p class="field-help">Bare hele, positive svar. Gange og dele bruker gangetabellen opptil 10.</p>
            </div>

            <div id="opt-values" class="opt-box">
                <div class="range-row" data-field="value_max">
                    <span class="range-label">Verdier fra</span>
                    <input type="number" class="small-number" id="f-value_min" min="0" max="99" step="1" inputmode="numeric" aria-label="Verdier fra">
                    <span>til</span>
                    <input type="number" class="small-number" id="f-value_max" min="0" max="99" step="1" inputmode="numeric" aria-label="Verdier til">
                </div>
                <p class="field-error" data-error-for="value_min"></p>
                <p class="field-error" data-error-for="value_max"></p>
                <p class="field-help" id="values-help">Verdiene står på et eget verdiark.</p>
            </div>

            <label class="check" id="unique-box"><input type="checkbox" id="f-unique"> <span>Ulike verdier i alle hullene</span></label>
            <p class="field-error" data-error-for="unique"></p>

            <hr class="rule">

            <h2 class="panel-heading">3 · Oppgaver</h2>
            <div class="task-block">
                <label class="check"><input type="checkbox" id="f-task_round"> <span><strong>Kulerunde</strong> – skyt kuler og regn ut summen</span></label>
                <div class="sub-fields" id="sub-round">
                    <label class="type-count"><span>Kuler per runde</span>
                        <input type="number" id="f-balls" min="2" max="8" step="1" inputmode="numeric"></label>
                    <p class="field-error" data-error-for="balls"></p>
                </div>
            </div>
            <div class="task-block" id="calc-block">
                <label class="check"><input type="checkbox" id="f-task_calc"> <span><strong>Regn med verdiene</strong> – <span id="calc-example">A + B – C</span></span></label>
                <div class="sub-fields" id="sub-calc">
                    <label class="type-count"><span>Antall stykker</span>
                        <input type="number" id="f-calc_count" min="1" max="12" step="1" inputmode="numeric"></label>
                    <label class="type-count"><span>Ledd per stykke</span>
                        <input type="number" id="f-calc_terms" min="2" max="5" step="1" inputmode="numeric"></label>
                    <p class="field-error" data-error-for="calc_count"></p>
                    <p class="field-error" data-error-for="calc_terms"></p>
                    <div id="calc-ops" class="segmented segmented-compact op-options" role="group" aria-label="Regnetegn i stykkene"></div>
                    <p class="field-error" data-error-for="calc_ops"></p>
                    <label class="check"><input type="checkbox" id="f-no_negative"> <span>Aldri under null underveis</span></label>
                </div>
            </div>
            <div class="task-block">
                <label class="check"><input type="checkbox" id="f-task_find"> <span><strong>Finn hullene</strong> – hvilke hull gir summen?</span></label>
                <div class="sub-fields" id="sub-find">
                    <label class="type-count"><span>Antall oppgaver</span>
                        <input type="number" id="f-find_count" min="1" max="8" step="1" inputmode="numeric"></label>
                    <span class="control-label">Hull per svar</span>
                    <div id="find-size" class="segmented segmented-compact" role="radiogroup" aria-label="Hull per svar">
                        <label><input type="radio" name="find_size" value="2"> To</label>
                        <label><input type="radio" name="find_size" value="3"> Tre</label>
                    </div>
                    <p class="field-error" data-error-for="find_count"></p>
                </div>
            </div>

            <hr class="rule">

            <h2 class="panel-heading">4 · Sett</h2>
            <div class="variant-row">
                <label class="type-count"><span>Antall sett med ulike verdier</span>
                    <input type="number" id="f-variants" min="1" max="10" step="1" inputmode="numeric"></label>
            </div>
            <p class="field-error" data-error-for="variants"></p>
            <button type="button" class="btn btn-secondary make-btn" id="make-new">
                <span class="btn-title">Lag stykker</span><span class="btn-sub">Nye verdier og stykker i alle settene</span>
            </button>
        </form>

        <div id="message" class="message" role="alert" hidden></div>

    </aside>
</main>
