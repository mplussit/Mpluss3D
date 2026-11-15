/**
 * 3D-visning av base 10-klossene.
 *
 * Hver objekttype har én STL-fil med én kloss (midt over origo, undersiden
 * på z = 0, slik den skrives ut). Visningen bruker den samme geometrien for
 * alle eksemplarer, så også store tall i tallbyggeren er lette å vise
 * (9999 = 36 klosser som deler fire geometrier).
 *
 * Visninger:
 *   set      én av hver valgt type, i riktig innbyrdes størrelse
 *   single   én type, slik den ligger på byggeplaten
 *   builder  et tall bygd av klosser (tusenere, hundrere, tiere, enere)
 *
 * I «set» og «builder» ligger tieren langs Y (som en søyle i en
 * plassverditabell), og den hule tusenerkuben er snudd med åpningen ned.
 */
import * as THREE from 'three';
import { ModelViewer, makeTextSprite } from './viewer.js';

const VIEWS = {
    iso: [0.45, -1.25, 1.05],
    top: [0, -0.002, 1],
};

const ORDER = ['tusener', 'hundrer', 'tier', 'ener'];
const VALUE = { tusener: 1000, hundrer: 100, tier: 10, ener: 1 };

/**
 * Gjør rillene mørkere i forhåndsvisningen, så rutenettet syns også rett
 * ovenfra. Bare fargen endres. En trekant hører til en rille hvis alle
 * hjørnene ligger innenfor samme rille (k · enhet ± bredde/2) langs en akse.
 */
function shadeGrooves(geometry, info) {
    const pos = geometry.attributes.position;
    const colors = new Float32Array(pos.count * 3).fill(1);
    if (info.grid && info.groove.width > 0) {
        const u = info.unit;
        const half = info.groove.width / 2 + 0.002;
        const axes = [];
        // X og Y er sentrert om origo; Z starter på byggeplaten (0).
        info.units.forEach((n, a) => { if (n > 1) axes.push({ a, n, start: a === 2 ? 0 : -info.size_mm[a] / 2 }); });
        const get = [(i) => pos.getX(i), (i) => pos.getY(i), (i) => pos.getZ(i)];
        const bandOf = (ax, v) => {
            const k = Math.round((v - ax.start) / u);
            if (k < 1 || k >= ax.n) return -1;
            return Math.abs(v - ax.start - k * u) <= half ? k : -1;
        };
        for (let i = 0; i < pos.count; i += 3) {
            for (const ax of axes) {
                const g = get[ax.a];
                const k = bandOf(ax, g(i));
                if (k >= 0 && bandOf(ax, g(i + 1)) === k && bandOf(ax, g(i + 2)) === k) {
                    colors.fill(0.5, i * 3, i * 3 + 9);
                    break;
                }
            }
        }
    }
    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
}

export class Base10Viewer extends ModelViewer {
    constructor(container) {
        super(container, VIEWS);
        this.fitFactor = 0.85;
        this.group = new THREE.Group();
        this.scene.add(this.group);
        this.parts = new Map();      // type → { id, geometry, material, info }
        this.display = { mode: 'set', single: 'tier', number: 243 };
        this.focusKey = '';
        this.focusRadius = 0;
        this.setFocus(new THREE.Vector3(0, 0, 10), 80, false);
        this.setView('iso', false);
    }

    /**
     * Erstatter klossene. Geometri som ikke er endret (samme id), beholdes.
     * @param {{type:string, id:string, buffer?:ArrayBuffer, info:object}[]} pieces
     */
    setParts(pieces) {
        const keep = new Set();
        for (const p of pieces) {
            keep.add(p.type);
            const old = this.parts.get(p.type);
            if (old?.id === p.id) {
                old.info = p.info;
                continue;
            }
            old?.geometry.dispose();
            old?.material.dispose();
            const geometry = this.loader.parse(p.buffer);
            geometry.computeBoundingBox();
            shadeGrooves(geometry, p.info);
            const material = new THREE.MeshStandardMaterial({
                color: new THREE.Color(p.info.color), roughness: 0.55, metalness: 0, flatShading: true, vertexColors: true,
            });
            this.parts.set(p.type, { id: p.id, geometry, material, info: p.info });
        }
        for (const [t, g] of this.parts) {
            if (!keep.has(t)) {
                g.geometry.dispose();
                g.material.dispose();
                this.parts.delete(t);
            }
        }
        this.rebuild();
    }

    /** @param {{mode?: 'set'|'single'|'builder', single?: string, number?: number}} display */
    setDisplay(display) {
        this.display = { ...this.display, ...display };
        this.rebuild();
    }

    types() {
        return ORDER.filter((t) => this.parts.has(t));
    }

    /** Lager en kloss i «brukt» stilling: tier langs Y, hul tusener med åpningen ned. */
    block(type, used = true) {
        const part = this.parts.get(type);
        const mesh = new THREE.Mesh(part.geometry, part.material);
        mesh.castShadow = true;
        const [X, Y, Z] = part.info.size_mm;
        let fx = X;
        let fy = Y;
        if (used && type === 'tier') {
            mesh.rotation.z = Math.PI / 2;
            [fx, fy] = [Y, X];
        }
        if (used && part.info.hollow) {
            mesh.rotation.x = Math.PI;      // åpningen ned
            mesh.position.z = Z;
        }
        // fotavtrykk (fx × fy) og høyde, brukt til plassering
        return { mesh, fx, fy, h: Z };
    }

    /** Legger en kloss med fremre venstre hjørne i (x, y). */
    place(b, x, y) {
        b.mesh.position.x = x + b.fx / 2;
        b.mesh.position.y = y + b.fy / 2;
        this.group.add(b.mesh);
    }

    /**
     * Scenen er bred og lav, så kulen rundt den er en romslig tilnærming i
     * liggende format. I stående format (mobil) trengs mer avstand, ellers
     * klippes sidene.
     */
    fitDistance() {
        this.fitFactor = this.camera.aspect < 1 ? 1.12 : 0.85;
        return super.fitDistance();
    }

    /** Navnelapp foran en gruppe. Størrelse og avstand settes i refocus(), etter hvor stor scenen er. */
    label(text, x) {
        const sprite = makeTextSprite(text);
        sprite.position.x = x;
        sprite.userData.isLabel = true;
        this.group.add(sprite);
    }

    unit() {
        const first = this.parts.values().next().value;
        return first ? first.info.unit : 10;
    }

    rebuild() {
        this.group.clear();
        this.group.position.x = 0;
        const types = this.types();
        if (!types.length) return;
        const u = this.unit();
        const { mode } = this.display;
        let key = mode;

        if (mode === 'single') {
            const t = this.parts.has(this.display.single) ? this.display.single : types[types.length - 1];
            const b = this.block(t, false);
            b.mesh.position.set(0, 0, 0);
            this.group.add(b.mesh);
            key += `|${t}`;
        } else if (mode === 'builder') {
            const r = this.buildNumber(this.display.number, u);
            key += `|${r}`;
        } else {
            // Samlet: én av hver, størst til venstre, fremkantene på linje.
            let x = 0;
            for (const t of types) {
                const b = this.block(t);
                this.place(b, x, 0);
                const name = t[0].toUpperCase() + t.slice(1);
                this.label(`${name} · ${VALUE[t]}`, x + b.fx / 2);
                x += b.fx + 3 * u;
            }
            this.group.position.x = -(x - 3 * u) / 2;
            key += `|${types.join(',')}`;
        }
        this.refocus(key);
    }

    /**
     * Bygger tallet med grupper fra venstre: tusenere, hundrere, tiere, enere.
     * Returnerer en nøkkel som endres når oppsettet får en ny størrelse.
     * Kalleren har allerede sjekket at typene finnes; mangler en, vises ingenting.
     */
    buildNumber(n, u) {
        if (!Number.isInteger(n) || n <= 0 || n > 9999) return `none${n}`;
        const digits = { tusener: Math.floor(n / 1000) % 10, hundrer: Math.floor(n / 100) % 10, tier: Math.floor(n / 10) % 10, ener: n % 10 };
        if (ORDER.some((t) => digits[t] > 0 && !this.parts.has(t))) return 'missing';
        let x = 0;
        const groups = [];
        for (const t of ORDER) {
            const k = digits[t];
            if (!k) continue;
            // klosser per rad og mellomrom (i enheter)
            const perRow = { tusener: 5, hundrer: 5, tier: 10, ener: 5 }[t];
            const gap = { tusener: 1, hundrer: 0.6, tier: 0.4, ener: 0.35 }[t] * u;
            let w = 0;
            for (let i = 0; i < k; i++) {
                const b = this.block(t);
                const col = i % perRow;
                const row = Math.floor(i / perRow);
                this.place(b, x + col * (b.fx + gap), row * (b.fy + gap));
                w = Math.max(w, (col + 1) * (b.fx + gap) - gap);
            }
            const name = k === 1 ? t : { tusener: 'tusenere', hundrer: 'hundrere', tier: 'tiere', ener: 'enere' }[t];
            this.label(`${k} ${name}`, x + w / 2);
            groups.push(`${t}${k > 5 && t !== 'tier' ? 2 : 1}:${Math.min(k, t === 'tier' ? 10 : 5)}`);
            x += w + 3 * u;
        }
        this.group.position.x = -(x - 3 * u) / 2;
        return groups.join(',');
    }

    /** Flytter kameraet når oppsettet får ny form eller størrelse, ellers bare fokus. */
    refocus(key) {
        if (!this.group.children.length) return;
        // Mål klossene først (uten navnelapper), og gi lappene en størrelse som passer scenen.
        const labels = this.group.children.filter((c) => c.userData.isLabel);
        const blocks = new THREE.Box3();
        this.group.updateMatrixWorld(true);
        for (const c of this.group.children) if (!c.userData.isLabel) blocks.expandByObject(c);
        const span = blocks.getSize(new THREE.Vector3());
        const s = Math.max(this.unit() * 0.9, 6, Math.hypot(span.x, span.y) * 0.035);
        for (const l of labels) {
            l.scale.set(s * 4, s, 1);
            l.position.y = -s * 0.9;
            l.position.z = s * 0.5 + 0.5;    // over bakken, så lappen ikke skjæres av rutenettet
        }
        const box = new THREE.Box3().setFromObject(this.group);
        const center = box.getCenter(new THREE.Vector3());
        const radius = Math.max(box.getSize(new THREE.Vector3()).length() / 2, 12);
        const refit = key !== this.focusKey || Math.abs(radius - this.focusRadius) > this.focusRadius * 0.1;
        this.focusKey = key;
        if (refit) this.focusRadius = radius;
        this.setFocus(center, radius, refit, { shadow: Math.max(80, radius * 1.2), fade: [radius * 1.1, radius * 2.4] });
    }
}
