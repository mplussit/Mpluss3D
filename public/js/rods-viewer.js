/**
 * 3D-visning av tallstavene.
 *
 * Hver stavlengde har én STL-fil med én stav (langs X, midt over origo,
 * undersiden på z = 0, slik den skrives ut). Alle eksemplarer av en lengde
 * deler samme geometri.
 *
 * Visninger:
 *   stairs   én stav av hver valgt lengde, med venstre ende på linje (trapp)
 *   single   én stav, slik den ligger på byggeplaten
 *   combos   en mållengde øverst og kombinasjoner av staver under, med
 *            venstre ende på linje og en strek der lengden slutter
 */
import * as THREE from 'three';
import { ModelViewer } from './viewer.js';

const VIEWS = {
    iso: [0.45, -1.25, 1.05],
    top: [0, -0.002, 1],
};

const ACCENT = 0xf36d21;

/**
 * Gjør riller og nedsenket tall mørkere i forhåndsvisningen, så de syns
 * også ovenfra. Bare fargen endres.
 */
function shade(geometry, info) {
    const pos = geometry.attributes.position;
    const colors = new Float32Array(pos.count * 3).fill(1);
    const [L, , H] = info.size_mm;
    const u = info.unit;
    const inGroove = (x) => {
        if (!info.grid) return -1;
        const k = Math.round((x + L / 2) / u);
        if (k < 1 || k >= info.length) return -1;
        return Math.abs(x + L / 2 - k * u) <= info.groove.width / 2 + 0.002 ? k : -1;
    };
    const lab = info.label;
    const inLabel = (i) => lab && pos.getZ(i) < H - 1e-3 && pos.getZ(i) > H - lab.depth - 1e-3
        && Math.abs(pos.getX(i) - lab.x) <= lab.width / 2 + 0.3 && Math.abs(pos.getY(i)) <= lab.height / 2 + 0.3;
    for (let i = 0; i < pos.count; i += 3) {
        const k = inGroove(pos.getX(i));
        const groove = k >= 0 && inGroove(pos.getX(i + 1)) === k && inGroove(pos.getX(i + 2)) === k
            && Math.min(pos.getZ(i), pos.getZ(i + 1), pos.getZ(i + 2)) >= H - info.groove.depth - 1e-3;
        const label = lab && [0, 1, 2].every((j) => inLabel(i + j) || Math.abs(pos.getZ(i + j) - H) < 1e-3)
            && [0, 1, 2].some((j) => inLabel(i + j));
        if (groove || label) colors.fill(label ? 0.45 : 0.55, i * 3, i * 3 + 9);
    }
    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
}

/**
 * Navnelapp med bredde etter teksten (lange summer som 10 + 5 + 1 + 1 + 1 + 1 + 1
 * får plass). userData.aspect = bredde / høyde.
 */
function makeLabel(text) {
    const font = '600 46px Figtree, system-ui, Segoe UI, Arial, sans-serif';
    const ctx0 = document.createElement('canvas').getContext('2d');
    ctx0.font = font;
    const h = 80;
    const w = Math.ceil(ctx0.measureText(text).width + 36);
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = 'rgba(255,253,249,0.94)';
    ctx.strokeStyle = '#d9d2c4';
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.roundRect(2, 8, w - 4, h - 16, 6);
    ctx.fill();
    ctx.stroke();
    ctx.font = font;
    ctx.fillStyle = '#161616';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(text, w / 2, h / 2 + 2);
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: texture, transparent: true }));
    sprite.userData.aspect = w / h;
    return sprite;
}

export class RodsViewer extends ModelViewer {
    constructor(container) {
        super(container, VIEWS);
        this.fitFactor = 0.9;
        this.group = new THREE.Group();
        this.scene.add(this.group);
        this.parts = new Map();      // lengde → { id, geometry, material, info }
        this.display = { mode: 'stairs', single: 5, target: 10, combos: [] };
        this.focusKey = '';
        this.focusRadius = 0;
        this.ghost = new THREE.MeshStandardMaterial({ color: 0xb9b2a5, transparent: true, opacity: 0.45, roughness: 0.8 });
        this.lineMaterial = new THREE.MeshBasicMaterial({ color: ACCENT });
        this.setFocus(new THREE.Vector3(0, 0, 5), 60, false);
        this.setView('iso', false);
    }

    /**
     * Erstatter stavene. Geometri som ikke er endret (samme id), beholdes;
     * fargen oppdateres alltid (den lages ikke av serveren som STL).
     * @param {{length:number, id:string, buffer?:ArrayBuffer, info:object}[]} pieces
     */
    setParts(pieces) {
        const keep = new Set();
        for (const p of pieces) {
            keep.add(p.length);
            const old = this.parts.get(p.length);
            if (old?.id === p.id) {
                old.info = p.info;
                old.material.color.set(p.info.color);
                continue;
            }
            old?.geometry.dispose();
            old?.material.dispose();
            const geometry = this.loader.parse(p.buffer);
            geometry.computeBoundingBox();
            shade(geometry, p.info);
            const material = new THREE.MeshStandardMaterial({
                color: new THREE.Color(p.info.color), roughness: 0.5, metalness: 0, flatShading: true, vertexColors: true,
            });
            this.parts.set(p.length, { id: p.id, geometry, material, info: p.info });
        }
        for (const [n, g] of this.parts) {
            if (!keep.has(n)) {
                g.geometry.dispose();
                g.material.dispose();
                this.parts.delete(n);
            }
        }
        this.rebuild();
    }

    /** @param {{mode?: 'stairs'|'single'|'combos', single?: number, target?: number, combos?: number[][]}} display */
    setDisplay(display) {
        this.display = { ...this.display, ...display };
        this.rebuild();
    }

    lengths() {
        return [...this.parts.keys()].sort((a, b) => a - b);
    }

    unit() {
        const first = this.parts.values().next().value;
        return first ? first.info.unit : 10;
    }

    /** Avstand mellom radene i trappen og kombinasjonene. */
    pitch() {
        const u = this.unit();
        return u + Math.max(2.5, u * 0.4);
    }

    fitDistance() {
        this.fitFactor = this.camera.aspect < 1 ? 1.15 : 0.9;
        return super.fitDistance();
    }

    /**
     * Legger stav n med venstre ende i x og midten i y. Stavene krympes 0,3 mm
     * på hver ende i visningen, så to like staver etter hverandre ikke flyter
     * sammen. Lengdene på linja er fortsatt riktige.
     */
    rod(n, x, y) {
        const part = this.parts.get(n);
        const mesh = new THREE.Mesh(part.geometry, part.material);
        mesh.castShadow = true;
        const L = part.info.size_mm[0];
        const gap = Math.min(0.6, L * 0.03);
        mesh.scale.x = (L - gap) / L;
        mesh.position.set(x + L / 2, y, 0);
        this.group.add(mesh);
        return L;
    }

    /** Navnelapp til venstre for en rad. Størrelse settes i refocus(). */
    label(text, y) {
        const sprite = makeLabel(text);
        sprite.userData.isLabel = true;
        sprite.userData.rowY = y;
        this.group.add(sprite);
    }

    rebuild() {
        // Strekene og den grå staven lages på nytt hver gang; frigjør de gamle.
        for (const c of this.group.children) if (c.userData.temp) c.geometry.dispose();
        for (const c of this.group.children) if (c.userData.isLabel) { c.material.map.dispose(); c.material.dispose(); }
        this.group.clear();
        this.group.position.set(0, 0, 0);
        const lens = this.lengths();
        if (!lens.length) return;
        const u = this.unit();
        const pitch = this.pitch();
        const { mode } = this.display;
        let key = mode;

        if (mode === 'single') {
            const n = this.parts.has(this.display.single) ? this.display.single : lens[lens.length - 1];
            const L = this.parts.get(n).info.size_mm[0];
            this.rod(n, -L / 2, 0);
            key += `|${n}`;
        } else if (mode === 'combos') {
            key += `|${this.buildCombos(u, pitch)}`;
        } else {
            // Trapp: korteste foran, venstre ender på linje.
            lens.forEach((n, i) => {
                this.rod(n, 0, i * pitch);
                this.label(String(n), i * pitch);
            });
            key += `|${lens.join(',')}`;
        }
        // Midt i bildet
        const box = new THREE.Box3();
        this.group.updateMatrixWorld(true);
        for (const c of this.group.children) if (!c.userData.isLabel) box.expandByObject(c);
        if (!box.isEmpty()) {
            const c = box.getCenter(new THREE.Vector3());
            this.group.position.set(-c.x, -c.y, 0);
        }
        this.refocus(key);
    }

    /**
     * Mållengden øverst (bakerst): staven selv, eller en grå strek-stav når
     * lengden er over 10 eller staven ikke er i settet. Kombinasjonene under.
     */
    buildCombos(u, pitch) {
        const { target, combos } = this.display;
        if (!Number.isInteger(target) || target < 2) return 'none';
        // Bare kombinasjoner der alle stavene er lastet (settet kan nettopp ha endret seg).
        const list = combos.filter((c) => c.every((n) => this.parts.has(n)));
        const rows = list.length;
        const yRef = rows * pitch;
        if (target <= 10 && this.parts.has(target)) {
            this.rod(target, 0, yRef);
        } else {
            const L = target * u;
            const bar = new THREE.Mesh(new THREE.BoxGeometry(L, u, u), this.ghost);
            bar.position.set(L / 2, yRef, u / 2);
            bar.userData.temp = true;
            this.group.add(bar);
        }
        this.label(`${target}`, yRef);
        list.forEach((combo, r) => {
            const y = (rows - 1 - r) * pitch;
            let x = 0;
            for (const n of combo) x += this.rod(n, x, y);
            this.label(combo.join(' + '), y);
        });
        // Streker der lengden starter og slutter, på bakken gjennom alle radene.
        const span = yRef + u;
        for (const x of [0, target * u]) {
            const line = new THREE.Mesh(new THREE.BoxGeometry(0.8, span + pitch * 0.6, 0.15), this.lineMaterial);
            line.position.set(x, yRef / 2, 0.08);
            line.userData.temp = true;
            this.group.add(line);
        }
        return `${target}:${rows}`;
    }

    /** Flytter kameraet når oppsettet får ny form eller størrelse, ellers bare fokus. */
    refocus(key) {
        if (!this.group.children.length) return;
        const labels = this.group.children.filter((c) => c.userData.isLabel);
        const u = this.unit();
        // Lappene skal være lesbare, men ikke høyere enn avstanden mellom radene.
        const s = Math.min(this.pitch() * 0.7, Math.max(u * 0.8, 6));
        // Lappene står til venstre for radene (stavene starter i lokal x = 0).
        for (const l of labels) {
            const w = s * l.userData.aspect;
            l.scale.set(w, s, 1);
            l.position.set(-w / 2 - u * 0.4, l.userData.rowY, u / 2);
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
