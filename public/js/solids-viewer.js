/**
 * 3D-visning av romfigurene.
 *
 * Geometrien er STL-fila serveren har laget. Flatene, kantene og hjørnene
 * kommer fra den felles geometrimodellen (describe().geometry), ikke fra
 * trekantene i STL-fila:
 *
 *   Flater   hver STL-trekant tilordnes den geometriske flaten den ligger i
 *            (planet for plane flater, sylinder-/kjegleflaten for krumme), så
 *            den krumme sideflaten blir én flate med én farge.
 *   Kanter   tegnes fra modellens kantliste (12 for kuben, 2 sirkler for
 *            sylinderen), aldri fra trianguleringen.
 *   Hjørner  små kuler i modellens hjørner (og toppunktet på kjeglen).
 */
import * as THREE from 'three';
import { ModelViewer } from './viewer.js';

const VIEWS = {
    iso:   [1, -1.35, 0.95],
    front: [0, -1, 0.0001],
    side:  [1, 0, 0.0001],
    top:   [0, -0.002, 1],
};

const BODY = 0xf36d21;
const INNER = [0.75, 0.75, 0.75];

export class SolidViewer extends ModelViewer {
    constructor(container) {
        super(container, VIEWS);
        this.fitFactor = 1.35;
        this.material = new THREE.MeshStandardMaterial({ color: BODY, roughness: 0.6, metalness: 0 });
        this.mesh = null;
        this.overlay = new THREE.Group();
        this.scene.add(this.overlay);
        this.showFaces = false;
        this.showEdges = false;
        this.size = [40, 40, 40];
        this.setFocus(new THREE.Vector3(0, 0, 20), 35, false);
        this.setView('iso', false);
    }

    /**
     * @param {ArrayBuffer} buffer STL
     * @param {object} geo geometrimodellen (describe().geometry)
     */
    setModel(buffer, geo) {
        const geometry = this.loader.parse(buffer);
        classify(geometry, geo);
        if (this.mesh) {
            this.scene.remove(this.mesh);
            this.mesh.geometry.dispose();
        }
        this.mesh = new THREE.Mesh(geometry, this.material);
        this.mesh.castShadow = true;
        this.scene.add(this.mesh);
        this.geo = geo;
        this.buildOverlay();
        this.applyMode();

        const prev = Math.max(...this.size);
        this.size = geo.size_mm;
        const max = Math.max(...this.size);
        const refit = !this.hasModel || Math.abs(max - prev) > prev * 0.15;
        this.setFocus(new THREE.Vector3(0, 0, this.size[2] / 2), Math.hypot(...this.size) / 2, refit,
            { fade: [max * 1.4, max * 3.6], min: max * 1.1, max: max * 12, shadow: Math.max(80, max * 1.3) });
        this.hasModel = true;
    }

    setShowFaces(on) {
        this.showFaces = on;
        this.applyMode();
    }

    setShowEdges(on) {
        this.showEdges = on;
        this.applyMode();
    }

    applyMode() {
        this.material.vertexColors = this.showFaces;
        this.material.color.set(this.showFaces ? 0xffffff : BODY);
        this.material.needsUpdate = true;
        this.overlay.visible = this.showEdges;
    }

    /** Kanter (rør langs linjer og sirkler) og hjørner (kuler). */
    buildOverlay() {
        for (const c of this.overlay.children) c.geometry.dispose();
        this.overlay.clear();
        const geo = this.geo;
        const t = Math.max(0.35, Math.max(...geo.size_mm) * 0.009);
        const edgeMat = this.edgeMat ??= new THREE.MeshStandardMaterial({ color: 0x161616, roughness: 0.5 });
        const vertMat = this.vertMat ??= new THREE.MeshStandardMaterial({ color: 0xfffdf9, roughness: 0.4 });
        for (const e of geo.edges) {
            if (e.kind === 'line') {
                const a = new THREE.Vector3(...e.a);
                const b = new THREE.Vector3(...e.b);
                const len = a.distanceTo(b);
                const m = new THREE.Mesh(new THREE.CylinderGeometry(t, t, len, 12), edgeMat);
                m.position.copy(a).add(b).multiplyScalar(0.5);
                m.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), b.clone().sub(a).normalize());
                this.overlay.add(m);
            } else {
                const m = new THREE.Mesh(new THREE.TorusGeometry(e.r, t, 10, 180), edgeMat);
                m.position.set(...e.center);
                this.overlay.add(m);
            }
        }
        for (const v of geo.vertices) {
            const m = new THREE.Mesh(new THREE.SphereGeometry(t * 2.6, 20, 14), vertMat);
            m.position.set(...v.p);
            this.overlay.add(m);
        }
    }
}

/**
 * Tilordner hver STL-trekant til en geometrisk flate, og setter farger og
 * normaler: plane flater får flatenormalen, krumme flater glatte normaler
 * fra den eksakte flaten. Trekanter i hulrommet (hul modell) blir grå.
 */
function classify(geometry, geo) {
    const pos = geometry.attributes.position;
    const n = pos.count;
    const colors = new Float32Array(n * 3);
    const normals = new Float32Array(n * 3);
    const faceRgb = geo.faces.map((f) => new THREE.Color(f.color).toArray());
    const tol = 0.05;
    const v = [new THREE.Vector3(), new THREE.Vector3(), new THREE.Vector3()];
    const nrm = new THREE.Vector3();
    const planar = geo.faces.filter((f) => f.plane);
    const curved = geo.faces.filter((f) => f.surface);
    const onSurface = (s, p) => {
        const rho = Math.hypot(p.x, p.y);
        if (s.type === 'cylinder') return Math.abs(rho - s.r) < tol && p.z > s.z0 - tol && p.z < s.z1 + tol;
        return Math.abs(rho - s.r * (1 - p.z / s.h)) < tol && p.z > -tol;
    };
    const surfaceNormal = (s, p, out) => {
        const rho = Math.hypot(p.x, p.y);
        if (rho < 1e-6) return false;
        if (s.type === 'cylinder') out.set(p.x / rho, p.y / rho, 0);
        else out.set((p.x / rho) * s.h, (p.y / rho) * s.h, s.r).normalize();
        return true;
    };
    for (let i = 0; i < n; i += 3) {
        for (let k = 0; k < 3; k++) v[k].fromBufferAttribute(pos, i + k);
        nrm.subVectors(v[1], v[0]).cross(new THREE.Vector3().subVectors(v[2], v[0])).normalize();
        let face = planar.find((f) => {
            const fn = f.plane.n;
            return nrm.x * fn[0] + nrm.y * fn[1] + nrm.z * fn[2] > 0.999
                && v.every((p) => Math.abs(p.x * fn[0] + p.y * fn[1] + p.z * fn[2] - f.plane.d) < tol);
        });
        let smooth = false;
        if (!face) {
            face = curved.find((f) => v.every((p) => onSurface(f.surface, p)) && nrm.z > -0.999);
            smooth = !!face;
        }
        const rgb = face ? faceRgb[face.index] : INNER;
        for (let k = 0; k < 3; k++) {
            colors.set(rgb, (i + k) * 3);
            const out = new THREE.Vector3();
            if (!(smooth && surfaceNormal(face.surface, v[k], out))) out.copy(nrm);
            normals.set([out.x, out.y, out.z], (i + k) * 3);
        }
    }
    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    geometry.setAttribute('normal', new THREE.BufferAttribute(normals, 3));
}
