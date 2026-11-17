/**
 * 3D-visning av Lego-rammene.
 *
 * Rammene er STL-filene serveren har laget (én per ramme). Klossene i
 * knottplassene er bare en gjennomsiktig illustrasjon av hvor mye rammen
 * rommer. De lages her i nettleseren og er aldri med i STL-fila.
 *
 * Visninger:
 *   single   valgt ramme
 *   all      alle rammene på rad, med regnetegnene mellom
 */
import * as THREE from 'three';
import { ModelViewer, makeTextSprite } from './viewer.js';

const VIEWS = {
    iso:   [0.55, -1.3, 1.05],
    top:   [0, -0.002, 1],
    front: [0, -1, 0.25],
};

const FRAME = 0xf36d21;
const OTHER = 0xf5a77a;
const BRICK = 0x2e6db4;
const BRICK_H = 9.6;          // høyden på en vanlig kloss
const STUD = { r: 2.4, h: 1.7 };

export class LegoViewer extends ModelViewer {
    constructor(container) {
        super(container, VIEWS);
        this.fitFactor = 1.1;
        this.group = new THREE.Group();
        this.scene.add(this.group);
        this.parts = new Map();          // indeks → { id, geometry, info }
        this.display = { mode: 'single', selected: 0, bricks: true };
        this.frameMat = new THREE.MeshStandardMaterial({ color: FRAME, roughness: 0.55, metalness: 0, flatShading: true });
        this.otherMat = new THREE.MeshStandardMaterial({ color: OTHER, roughness: 0.6, metalness: 0, flatShading: true });
        this.brickMat = new THREE.MeshStandardMaterial({ color: BRICK, roughness: 0.4, transparent: true, opacity: 0.28, depthWrite: false });
        this.brickGeo = new THREE.BoxGeometry(7.8, 7.8, BRICK_H - 0.1);
        this.studGeo = new THREE.CylinderGeometry(STUD.r, STUD.r, STUD.h, 24).rotateX(Math.PI / 2);
        this.focusKey = '';
        this.setFocus(new THREE.Vector3(0, 0, 5), 40, false);
        this.setView('iso', false);
    }

    /**
     * @param {{index:number, id:string, buffer?:ArrayBuffer, info:object}[]} pieces
     */
    setParts(pieces) {
        const keep = new Set();
        for (const p of pieces) {
            keep.add(p.index);
            const old = this.parts.get(p.index);
            if (old?.id === p.id) {
                old.info = p.info;
                continue;
            }
            old?.geometry.dispose();
            const geometry = this.loader.parse(p.buffer);
            geometry.computeBoundingBox();
            this.parts.set(p.index, { id: p.id, geometry, info: p.info });
        }
        for (const [i, g] of this.parts) {
            if (!keep.has(i)) {
                g.geometry.dispose();
                this.parts.delete(i);
            }
        }
        this.rebuild();
    }

    setDisplay(display) {
        this.display = { ...this.display, ...display };
        this.rebuild();
    }

    rebuild() {
        for (const c of this.group.children) {
            if (c.userData.sprite) {
                c.material.map.dispose();
                c.material.dispose();
            }
        }
        this.group.clear();
        const idx = [...this.parts.keys()].sort((a, b) => a - b);
        if (!idx.length) return;
        const { mode, bricks } = this.display;
        const selected = this.parts.has(this.display.selected) ? this.display.selected : idx[0];
        const shown = mode === 'all' ? idx : [selected];
        const gap = 22;
        let x = 0;
        shown.forEach((i, k) => {
            const p = this.parts.get(i);
            const bb = p.geometry.boundingBox;
            const w = bb.max.x - bb.min.x;
            const g = new THREE.Group();
            const mesh = new THREE.Mesh(p.geometry, mode === 'all' && i !== selected ? this.otherMat : this.frameMat);
            mesh.castShadow = true;
            g.add(mesh);
            if (bricks) this.addBricks(g, p.info);
            g.position.x = x - bb.min.x;
            this.group.add(g);
            x += w;
            const op = p.info.operator;
            if (mode === 'all' && k < shown.length - 1) {
                if (op) {
                    const s = makeTextSprite(op);
                    s.userData.sprite = true;
                    s.scale.set(64, 16, 1);
                    s.position.set(x + gap / 2, 0, 8);
                    this.group.add(s);
                }
                x += gap;
            }
        });
        // Midt i bildet
        const box = new THREE.Box3().setFromObject(this.group);
        const c = box.getCenter(new THREE.Vector3());
        this.group.position.set(-c.x, -c.y, 0);
        const size = box.getSize(new THREE.Vector3());
        const radius = Math.max(size.length() / 2, 20);
        const key = `${mode}|${shown.join(',')}|${Math.round(size.x / 10)}|${Math.round(size.y / 10)}`;
        const refit = key !== this.focusKey;
        this.focusKey = key;
        this.setFocus(new THREE.Vector3(0, 0, size.z / 2), radius, refit,
            { shadow: Math.max(80, radius * 1.3), fade: [radius * 1.3, radius * 3] });
    }

    /** Gjennomsiktige 1 × 1-klosser med knott i hver knottplass (bare illustrasjon). */
    addBricks(group, info) {
        const z0 = info.bottom_mm;
        for (const [x, y] of info.studs) {
            const b = new THREE.Mesh(this.brickGeo, this.brickMat);
            b.position.set(x, y, z0 + BRICK_H / 2);
            b.renderOrder = 2;
            group.add(b);
            const s = new THREE.Mesh(this.studGeo, this.brickMat);
            s.position.set(x, y, z0 + BRICK_H + STUD.h / 2);
            s.renderOrder = 2;
            group.add(s);
        }
    }
}
