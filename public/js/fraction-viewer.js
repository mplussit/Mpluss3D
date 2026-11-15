/**
 * 3D-visning av brøksirklene.
 *
 * Hver nevner har én STL-fil med én brikke (sentrum i origo, halveringslinja
 * langs +Y). Visningen lager hele sirkler ved å rotere den samme geometrien
 * 360/n grader om gangen, så det som vises er nøyaktig det som skrives ut.
 *
 * Visninger:
 *   whole      brikkene ligger samlet som en hel sirkel
 *   separated  brikkene er skjøvet litt ut fra sentrum
 *   single     én brikke
 * Brøksett: én nevner, eller 'all' (alle valgte sett ved siden av hverandre).
 */
import * as THREE from 'three';
import { ModelViewer } from './viewer.js';

const VIEWS = {
    iso: [0.55, -1.25, 1.15],
    top: [0, -0.002, 1],
};

/**
 * Gjør den nedsenkede brøkmerkingen mørkere i forhåndsvisningen, så den er
 * lesbar også rett ovenfra. Bare fargen endres, ikke geometrien.
 * Trekanter i merkingen: innenfor tekstboksen (fra backend), med minst ett
 * hjørne under toppflaten og ingen under bunnen av merkingen.
 */
function shadeLabel(geometry, piece) {
    const pos = geometry.attributes.position;
    const colors = new Float32Array(pos.count * 3).fill(1);
    const L = piece.label;
    if (L?.shown) {
        const top = piece.thickness;
        const floor = top - piece.depth;
        const [hx, hy] = L.rotate ? [L.height / 2 + 0.3, L.width / 2 + 0.3] : [L.width / 2 + 0.3, L.height / 2 + 0.3];
        for (let i = 0; i < pos.count; i += 3) {
            let minZ = Infinity;
            let cx = 0;
            let cy = 0;
            for (let k = 0; k < 3; k++) {
                minZ = Math.min(minZ, pos.getZ(i + k));
                cx += pos.getX(i + k) / 3;
                cy += pos.getY(i + k) / 3;
            }
            if (minZ < top - 0.01 && minZ > floor - 0.01 && Math.abs(cx) <= hx && Math.abs(cy - L.dist) <= hy) {
                colors.fill(0.42, i * 3, i * 3 + 9);
            }
        }
    }
    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
}

export class FractionViewer extends ModelViewer {
    constructor(container) {
        super(container, VIEWS);
        this.fitFactor = 0.95;
        this.group = new THREE.Group();
        this.scene.add(this.group);
        this.geometries = new Map();   // nevner → { id, geometry }
        this.materials = new Map();
        this.diameter = 100;
        this.display = { set: 'all', mode: 'whole' };
        this.focusKey = '';
        this.setFocus(new THREE.Vector3(0, 0, 1.5), 60, false);
        this.setView('iso', false);
    }

    /**
     * Erstatter brikkene.
     * @param {{denominator:number, id:string, buffer?:ArrayBuffer, color:string}[]} pieces
     */
    setPieces(pieces, diameter) {
        const keep = new Set();
        for (const p of pieces) {
            keep.add(p.denominator);
            const old = this.geometries.get(p.denominator);
            if (old?.id === p.id) continue;
            old?.geometry.dispose();
            const geometry = this.loader.parse(p.buffer);
            geometry.computeBoundingBox();
            shadeLabel(geometry, p);
            this.geometries.set(p.denominator, { id: p.id, geometry });
            this.materials.get(p.denominator)?.dispose();
            this.materials.set(p.denominator, new THREE.MeshStandardMaterial({
                color: new THREE.Color(p.color), roughness: 0.55, metalness: 0, flatShading: true, vertexColors: true,
            }));
        }
        for (const [d, g] of this.geometries) {
            if (!keep.has(d)) {
                g.geometry.dispose();
                this.geometries.delete(d);
            }
        }
        this.diameter = diameter;
        this.rebuild();
    }

    /** @param {{set?: number|'all', mode?: 'whole'|'separated'|'single'}} display */
    setDisplay(display) {
        this.display = { ...this.display, ...display };
        this.rebuild();
    }

    denominators() {
        return [...this.geometries.keys()].sort((a, b) => a - b);
    }

    rebuild() {
        this.group.clear();
        const dens = this.denominators();
        if (!dens.length) return;
        let { set, mode } = this.display;
        if (set !== 'all' && !this.geometries.has(set)) set = 'all';
        const shown = set === 'all' ? dens : [set];

        const R = this.diameter / 2;
        const gap = mode === 'separated' ? Math.max(3, this.diameter * 0.06) : 0;
        // Sirklene for «alle» legges i rader på høyst 4.
        const perRow = Math.min(shown.length, shown.length > 4 ? Math.ceil(shown.length / 2) : 4);
        const pitch = this.diameter + 2 * gap + Math.max(12, this.diameter * 0.18);
        const rows = Math.ceil(shown.length / perRow);

        shown.forEach((d, i) => {
            const col = i % perRow;
            const row = Math.floor(i / perRow);
            const inRow = Math.min(perRow, shown.length - row * perRow);
            const ox = (col - (inRow - 1) / 2) * pitch;
            const oy = ((rows - 1) / 2 - row) * pitch;
            const count = mode === 'single' ? 1 : d;
            const { geometry } = this.geometries.get(d);
            for (let k = 0; k < count; k++) {
                const mesh = new THREE.Mesh(geometry, this.materials.get(d));
                mesh.castShadow = true;
                const angle = (2 * Math.PI * k) / d;
                mesh.rotation.z = angle;
                // Halveringslinja peker langs +Y før rotasjon.
                mesh.position.set(ox - Math.sin(angle) * gap, oy + Math.cos(angle) * gap, 0);
                this.group.add(mesh);
            }
        });

        // Fokus: hele gruppen. Én brikke: bare brikka.
        const box = new THREE.Box3().setFromObject(this.group);
        const center = box.getCenter(new THREE.Vector3());
        const radius = Math.max(box.getSize(new THREE.Vector3()).length() / 2, 10);
        const key = `${set}|${mode}|${shown.join(',')}|${this.diameter}`;
        const refit = key !== this.focusKey;
        this.focusKey = key;
        this.setFocus(center, radius, refit, { shadow: Math.max(80, radius * 1.2), fade: [radius * 0.9, radius * 2] });
    }
}
