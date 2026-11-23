/**
 * 3D-visning av kulespillet: bunnen, hullarket og spillet oppå.
 *
 * Spillet og bunnen er de faste STL-filene (tegnet i SolidCraft). Spillbrettet
 * (A, B eller C) kan byttes med setBoard(). Arket er
 * hullarket fra serveren, tegnet som SVG og lagt som tekstur på en plate på
 * 160 × 250 mm i bunnens lomme, så innholdet syns gjennom hullene slik det
 * vil gjøre på det ferdige spillet.
 */
import * as THREE from 'three';
import { ModelViewer } from './viewer.js';
import { pageSvg } from './sheet-svg.js';

const VIEWS = {
    iso:   [0.55, -1.15, 1.25],
    top:   [0, -0.002, 1],
    front: [0, -1, 0.3],
};

const BOARD = 0xf36d21;
const BASE = 0xcfc8bb;
const FLOOR = 2.0;              // bunnens gulvtykkelse
const PAPER = 0.1;              // arkets tykkelse
const LIFT = 45;                // så høyt spillet løftes for å vise arket
const PX_PER_MM = 8;

export class MarbleViewer extends ModelViewer {
    constructor(container) {
        super(container, VIEWS);
        this.fitFactor = 1.05;
        this.board = null;
        this.base = null;
        this.lift = 0;
        this.liftTarget = 0;
        this.sheetKey = '';
        this.boardUrl = '';
        this.geometries = new Map();     // url → Promise<BufferGeometry>
        this.boardMat = new THREE.MeshStandardMaterial({ color: BOARD, roughness: 0.55, metalness: 0, flatShading: true });
        this.baseMat = new THREE.MeshStandardMaterial({ color: BASE, roughness: 0.7, metalness: 0, flatShading: true });
        this.sheetMat = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.9 });
        this.sheet = new THREE.Mesh(new THREE.PlaneGeometry(160, 250), this.sheetMat);
        this.sheet.position.set(0, -45, FLOOR + PAPER / 2);
        this.sheet.receiveShadow = true;
        this.scene.add(this.sheet);
        this.setFocus(new THREE.Vector3(0, -45, 8), 140, false, { fade: [220, 520], min: 120, max: 1400, shadow: 180 });
        this.setView('iso', false);
    }

    /** Henter en STL-fil (én gang per fil). */
    geometry(url) {
        if (!this.geometries.has(url)) {
            this.geometries.set(url, (async () => {
                const res = await fetch(url);
                if (!res.ok) throw new Error('Kunne ikke hente ' + url);
                return this.loader.parse(await res.arrayBuffer());
            })());
        }
        return this.geometries.get(url);
    }

    /** Laster STL-filene til spillet og bunnen. */
    async load(boardUrl, baseUrl) {
        this.boardUrl = boardUrl;
        const s = await this.geometry(baseUrl);
        // Brettet kan ha blitt byttet mens filene ble hentet: bruk det siste.
        const b = await this.geometry(this.boardUrl);
        this.board = new THREE.Mesh(b, this.boardMat);
        this.board.castShadow = true;
        this.board.receiveShadow = true;
        this.base = new THREE.Mesh(s, this.baseMat);
        this.base.castShadow = true;
        this.base.receiveShadow = true;
        this.scene.add(this.base, this.board);
        this.placeBoard();
    }

    /** Bytter spillbrett (A, B eller C). Bunnen og arket er de samme. */
    async setBoard(url) {
        this.boardUrl = url;
        if (!this.board) return;            // load() bruker boardUrl når filene er hentet
        const g = await this.geometry(url);
        if (url === this.boardUrl) this.board.geometry = g;
    }

    /** Hullarket (en side fra describe().pages) som tekstur på arket. */
    async setSheet(page, sheet) {
        const key = JSON.stringify(page.items);
        if (key === this.sheetKey) return;
        this.sheetKey = key;
        const w = Math.round(sheet.width * PX_PER_MM);
        const h = Math.round(sheet.height * PX_PER_MM);
        // På PDF-siden er arket dreid 90° i en gruppe. Teksturen bruker elementene
        // i gruppen, som har arkets egne (udreide) koordinater.
        const board = page.items.find((it) => it.k === 'group')?.items;
        const svg = pageSvg(board ? { ...page, items: board } : page,
            { view: [sheet.x, sheet.y, sheet.width, sheet.height], width: w, height: h });
        const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
        try {
            const img = new Image();
            img.src = url;
            await img.decode();
            if (key !== this.sheetKey) return;
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            canvas.getContext('2d').drawImage(img, 0, 0, w, h);
            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            tex.anisotropy = this.renderer.capabilities.getMaxAnisotropy();
            this.sheetMat.map?.dispose();
            this.sheetMat.map = tex;
            this.sheetMat.needsUpdate = true;
        } finally {
            URL.revokeObjectURL(url);
        }
    }

    /** Løfter spillet over bunnen så arket syns (animert). */
    setLifted(on) {
        this.liftTarget = on ? LIFT : 0;
    }

    placeBoard() {
        if (this.board) this.board.position.z = FLOOR + PAPER + this.lift;
    }

    tick() {
        if (Math.abs(this.lift - this.liftTarget) > 0.01) {
            this.lift += (this.liftTarget - this.lift) * 0.15;
            if (Math.abs(this.lift - this.liftTarget) < 0.05) this.lift = this.liftTarget;
            this.placeBoard();
        }
        super.tick();
    }
}
