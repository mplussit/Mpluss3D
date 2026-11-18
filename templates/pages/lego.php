<?php /** Regn med Lego. Tas inn av public/index.php. Kontrollene fylles fra API-skjemaet. */ ?>
<main class="layout">
    <section class="viewer-wrap" aria-label="Forhåndsvisning">
        <div class="viewer-surface">
            <div id="viewer" class="viewer" tabindex="0" aria-label="3D-modell av rammen. Dra for å rotere, rull eller knip for å zoome."></div>
            <div class="view-buttons" role="group" aria-label="Velg retning">
                <button type="button" data-view="iso" aria-pressed="true" title="Tilbakestill visningen">Standard</button>
                <button type="button" data-view="top" aria-pressed="false">Ovenfra</button>
                <button type="button" data-view="front" aria-pressed="false">Forfra</button>
            </div>
            <div id="viewer-note" class="viewer-note" role="status" hidden></div>
            <div id="viewer-status" class="viewer-status" role="status" hidden></div>
        </div>

        <div class="viewer-controls">
            <div class="control-group">
                <span class="control-label" id="show-label">Vis</span>
                <div id="show-options" class="segmented segmented-compact" role="radiogroup" aria-labelledby="show-label">
                    <label><input type="radio" name="show" value="single" checked> Valgt ramme</label>
                    <label><input type="radio" name="show" value="all"> Alle rammer</label>
                </div>
            </div>
            <div class="control-group">
                <span class="control-label">Illustrasjon</span>
                <label class="check"><input type="checkbox" id="show-bricks" checked> <span>Vis klosser i knottplassene (ikke med i STL)</span></label>
            </div>
        </div>

        <div class="viewer-info">
            <div>
                <p id="model-info" class="model-info"></p>
                <p class="hint">Dra for å rotere · rull eller knip for å zoome · «Standard» tilbakestiller visningen · rutenettet er 10 mm</p>
            </div>
        </div>
    </section>

    <aside class="panel" aria-label="Innstillinger for valgt ramme">
        <section class="lego-card" aria-labelledby="frames-heading">
            <div class="lego-card-head">
                <h2 class="panel-heading" id="frames-heading">Rammer og regnestykke</h2>
                <div class="lego-frame-tools">
                    <button type="button" class="btn btn-secondary btn-small" id="frame-add">Ny ramme</button>
                    <button type="button" class="btn btn-secondary btn-small" id="frame-dup">Dupliser</button>
                    <button type="button" class="btn btn-secondary btn-small" id="frame-del">Slett</button>
                </div>
            </div>
            <div id="frame-strip" class="frame-strip" role="list" aria-label="Rammene i prosjektet"></div>
            <p id="equation" class="equation num" aria-live="polite"></p>
        </section>

        <hr class="rule">

        <form id="controls" novalidate>
            <p class="panel-frame" id="panel-frame">Ramme 1</p>

            <h2 class="panel-heading">1 · Form og størrelse</h2>
            <div class="size-row">
                <label class="type-count"><span>Bredde</span>
                    <input type="number" id="grid-cols" min="1" max="20" step="1" inputmode="numeric"></label>
                <span class="size-x" aria-hidden="true">×</span>
                <label class="type-count"><span>Lengde</span>
                    <input type="number" id="grid-rows" min="1" max="20" step="1" inputmode="numeric"></label>
                <span class="unit">knottplasser</span>
            </div>
            <button type="button" class="btn btn-secondary btn-small" id="make-rect">Lag rektangel</button>
            <div class="field" data-field="cells">
                <div id="grid" class="lego-grid" role="grid" aria-label="Formen. Hver rute er én knottplass (8 × 8 mm)."></div>
                <p class="field-hint">Klikk eller dra for å tegne inn eller fjerne ruter. Det blir bare vegger langs formens kant.</p>
                <p id="cell-count" class="cell-count num" aria-live="polite"></p>
                <p class="field-error" data-error-for="cells"></p>
            </div>

            <hr class="rule">

            <h2 class="panel-heading">2 · Bunn og ramme</h2>
            <div id="mm-frame"></div>
            <label class="check"><input type="checkbox" id="bottom-on"> <span>Bunn (flat, uten knotter)</span></label>
            <p class="field-error" data-error-for="bottom"></p>
            <div id="mm-bottom" class="sub-fields"></div>

            <hr class="rule">

            <h2 class="panel-heading" id="field-heading">3 · Tall og etiketter</h2>
            <span class="control-label">Merkefelt</span>
            <div id="field-options" class="segmented segmented-compact" role="radiogroup" aria-labelledby="field-heading"></div>
            <div id="field-settings">
                <span class="control-label">Side av rammen</span>
                <div id="side-options" class="segmented segmented-compact" role="radiogroup" aria-label="Side av rammen"></div>
                <span class="control-label">Innhold</span>
                <div id="mode-options" class="segmented segmented-compact" role="radiogroup" aria-label="Innhold"></div>
                <p class="field-error" data-error-for="text_mode"></p>
                <div class="field" data-field="text" id="text-box">
                    <label for="f-text" class="field-label">Egen tekst</label>
                    <input type="text" id="f-text" class="text-input" maxlength="12" placeholder="8 + 8" autocomplete="off">
                    <p class="field-help">Høyst 12 tegn. «-» blir minus og «*» blir gangetegn.</p>
                    <p class="field-error" data-error-for="text"></p>
                </div>
                <div id="style-box">
                    <span class="control-label">Tekst</span>
                    <div id="style-options" class="segmented segmented-compact" role="radiogroup" aria-label="Tekst"></div>
                </div>
                <div id="mm-field"></div>
                <div class="field" data-field="copies" id="copies-box">
                    <label class="type-count"><span>Antall etiketter</span>
                        <input type="number" id="f-copies" min="1" max="50" step="1" inputmode="numeric"></label>
                    <p class="field-error" data-error-for="copies"></p>
                </div>
            </div>
            <label class="check"><input type="checkbox" id="operator-cards"> <span>Ta med regnetegnene som papirkort i PDF-en</span></label>
        </form>

        <hr class="rule">

        <section class="lego-card" aria-labelledby="dims-heading">
            <h2 class="panel-heading" id="dims-heading">Mål for valgt ramme</h2>
            <p id="capacity" class="capacity num"></p>
            <dl id="dims" class="props"></dl>
            <p class="hint">Klossene står på bunnen (eller på bordet når rammen er uten bunn). En form kan kreve smale klosser eller 1 × 1-klosser.
                Passformen er uprøvd til den er testet med ekte klosser.</p>
        </section>

        <hr class="rule">

        <h2 class="panel-heading">4 · Nedlasting</h2>
        <div id="message" class="message" role="alert" hidden></div>
        <div class="downloads">
            <button type="button" id="download-stl" class="btn btn-primary" disabled>
                <span class="btn-title">Last ned STL for valgt ramme</span><span class="btn-sub" id="stl-sub">Én ramme</span>
            </button>
            <button type="button" id="download-zip" class="btn btn-secondary" disabled>
                <span class="btn-title">Last ned alle rammer (ZIP)</span><span class="btn-sub" id="zip-sub">Én STL per ramme og en oversikt</span>
            </button>
            <button type="button" id="download-pdf" class="btn btn-secondary" disabled>
                <span class="btn-title">Last ned PDF</span><span class="btn-sub" id="pdf-sub">Oversikt og etiketter i faktisk størrelse</span>
            </button>
        </div>
        <p class="hint panel-hint">LEGO® er et varemerke for LEGO-gruppen, som ikke står bak eller godkjenner M+ 3D.</p>
    </aside>
</main>

<dialog id="help-dialog" class="dialog" aria-labelledby="help-title">
    <h2 id="help-title">Hva er STL og PDF?</h2>
    <p><strong>STL</strong> er 3D-modellen. Hver ramme har sin egen STL-fil (for eksempel <code>lego-ramme-01-4x4-16.stl</code>).
        Åpne den i programmet til 3D-printeren og skriv den ut slik den ligger. Ingen støtte trengs. ZIP-fila har alle rammene.</p>
    <p><strong>PDF-en</strong> har en oversikt over rammene og regnestykket, og papiretiketter i faktisk størrelse til
        rammer med etikettfelt. Skriv den ut i 100 % og kontroller linjalen på 100 mm.</p>
    <p><strong>Passform:</strong> én knottplass er 8 × 8 mm, og klaringen (standard 0,2 mm per side) gir litt luft.
        Passformen er uprøvd til du har testet en ramme med ekte klosser. Skriv ut én ramme først og juster klaringen ved behov.</p>
    <form method="dialog">
        <button class="btn btn-secondary btn-small" value="close">Lukk</button>
    </form>
</dialog>
