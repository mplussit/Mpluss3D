/**
 * 3D-forhåndsvisning med Three.js.
 *
 * ModelViewer er felles for alle modellene: scene, lys, rutenett på bakken,
 * kamera med rotasjon/zoom, faste visninger og tilpasning til modellens
 * størrelse. Hver modell har sin egen underklasse (DiceViewer her,
 * FractionViewer i fraction-viewer.js) som laster STL-filene den trenger.
 *
 * Forhåndsvisningen bruker STL-filene backend har generert, altså nøyaktig
 * samme geometri som brukeren laster ned. Enheten er millimeter, og Z peker
 * opp, som i OpenSCAD. Rutenettet på bakken har 10 mm ruter.
 */
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { STLLoader } from 'three/addons/loaders/STLLoader.js';

const PAPER = 0xfffdf9;

export class ModelViewer {
    /**
     * @param {HTMLElement} container
     * @param {Record<string, number[]>} views kameraretninger (fra fokuspunktet)
     */
    constructor(container, views) {
        this.container = container;
        this.views = views;
        this.loader = new STLLoader();
        this.animation = null;
        this.onUserInteract = null;     // kalles når brukeren roterer/zoomer selv
        this.focus = { center: new THREE.Vector3(0, 0, 15), radius: 26 };
        this.fitFactor = 1.45;

        this.renderer = new THREE.WebGLRenderer({ antialias: true });
        this.renderer.setPixelRatio(ModelViewer.pixelRatio());
        this.renderer.shadowMap.enabled = true;
        this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        container.appendChild(this.renderer.domElement);

        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color(PAPER);

        this.camera = new THREE.PerspectiveCamera(35, 1, 1, 8000);
        this.camera.up.set(0, 0, 1);

        this.controls = new OrbitControls(this.camera, this.renderer.domElement);
        this.controls.enableDamping = true;
        this.controls.dampingFactor = 0.12;
        this.controls.enablePan = false;
        this.controls.addEventListener('start', () => {
            this.animation = null;
            this.userMoved = true;
            this.onUserInteract?.();
        });

        this.addLights();
        this.addGround();

        new ResizeObserver(() => this.resize()).observe(container);
        // Når appflaten forstørres (js/appscale.js), endres ikke størrelsen i CSS-piksler,
        // men bildet må tegnes med flere piksler for å bli skarpt.
        window.addEventListener('m3d-scale', () => {
            this.renderer.setPixelRatio(ModelViewer.pixelRatio());
            this.resize();
        });
        this.resize();
        this.renderer.setAnimationLoop(() => this.tick());
    }

    /** Pikseltetthet: skjermens, ganger forstørrelsen av appflaten, høyst 3. */
    static pixelRatio() {
        const zoom = window.m3dScale ? window.m3dScale.get() : 1;
        return Math.min(window.devicePixelRatio * zoom, 3);
    }

    addLights() {
        this.scene.add(new THREE.HemisphereLight(0xffffff, 0x9aa6b5, 1.0));
        this.key = new THREE.DirectionalLight(0xffffff, 2.6);
        this.key.position.set(-50, -110, 150);
        this.key.castShadow = true;
        this.key.shadow.mapSize.set(1024, 1024);
        this.setShadowExtent(80, 1);
        this.scene.add(this.key);
        this.scene.add(this.key.target);
        const fill = new THREE.DirectionalLight(0xffffff, 0.7);
        fill.position.set(-80, 70, 40);
        this.scene.add(fill);
    }

    /** Skyggekameraet må dekke modellen, men ikke mer (skarpere skygge). */
    setShadowExtent(s, scale) {
        Object.assign(this.key.shadow.camera, { left: -s, right: s, top: s, bottom: -s, near: 1, far: 400 * scale });
        this.key.shadow.camera.updateProjectionMatrix();
    }

    addGround() {
        // Lyst ruteark: 10 mm-ruter (#d9d2c4) og 5 mm-ruter (#e9e3d7) som tones
        // ut mot kantene. Linjene tegnes i shaderen, så de er skarpe ved alle zoomnivåer.
        // Fargene skrives direkte (sRGB) og blir derfor nøyaktig som angitt.
        this.gridMaterial = new THREE.ShaderMaterial({
            transparent: true,
            depthWrite: false,
            uniforms: {
                uMajor: { value: new THREE.Color(0xd9d2c4) },
                uMinor: { value: new THREE.Color(0xe9e3d7) },
                uFade: { value: new THREE.Vector2(45, 110) },
                uCenter: { value: new THREE.Vector2(0, 0) },
            },
            vertexShader: /* glsl */`
                varying vec2 vPos;
                void main() {
                    vPos = position.xy;
                    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
                }`,
            fragmentShader: /* glsl */`
                uniform vec3 uMajor; uniform vec3 uMinor; uniform vec2 uFade; uniform vec2 uCenter;
                varying vec2 vPos;
                float lines(vec2 p, float step, float width) {
                    vec2 q = p / step;
                    vec2 g = abs(fract(q - 0.5) - 0.5) / fwidth(q);
                    return 1.0 - min(min(g.x, g.y) / width, 1.0);
                }
                // Tones ut når rutene blir så små på skjermen at de flyter sammen.
                float visible(float step) {
                    float px = max(fwidth(vPos.x), fwidth(vPos.y)) / step;   // ruter per piksel
                    return 1.0 - smoothstep(0.12, 0.22, px);
                }
                void main() {
                    float minor = lines(vPos, 5.0, 1.0) * visible(5.0);
                    float major = lines(vPos, 10.0, 1.3) * visible(10.0);
                    vec3 col = mix(uMinor, uMajor, major);
                    float a = max(minor, major);
                    float fade = 1.0 - smoothstep(uFade.x, uFade.y, length(vPos - uCenter));
                    gl_FragColor = vec4(col, a * fade);
                }`,
        });
        const grid = new THREE.Mesh(new THREE.PlaneGeometry(4000, 4000), this.gridMaterial);
        grid.position.z = -0.01;
        grid.renderOrder = -1;
        this.scene.add(grid);

        const shadow = new THREE.Mesh(
            new THREE.PlaneGeometry(4000, 4000),
            new THREE.ShadowMaterial({ opacity: 0.12 }),
        );
        shadow.receiveShadow = true;
        shadow.position.z = -0.02;
        this.scene.add(shadow);
    }

    /**
     * Setter hva kameraet ser på: sentrum og radius på en kule rundt modellen.
     * Med refit flyttes kameraet (samme retning) slik at modellen fyller bildet.
     * @param {{fade?: number[], min?: number, max?: number, shadow?: number}} opts
     */
    setFocus(center, radius, refit, opts = {}) {
        this.focus = { center: center.clone(), radius };
        this.controls.target.copy(center);
        if (refit) {
            this.userMoved = false;
            const dir = this.camera.position.clone().sub(center);
            if (dir.lengthSq() < 1e-6) dir.set(0, -1, 0.8);
            dir.normalize();
            this.animation = null;
            this.camera.position.copy(center).addScaledVector(dir, this.fitDistance());
        }
        const fade = opts.fade ?? [radius * 1.6, radius * 4];
        this.gridMaterial.uniforms.uFade.value.set(fade[0], fade[1]);
        this.gridMaterial.uniforms.uCenter.value.set(center.x, center.y);
        this.controls.minDistance = opts.min ?? radius * 1.2;
        this.controls.maxDistance = opts.max ?? radius * 12;
        const s = opts.shadow ?? Math.max(80, radius * 1.4);
        const scale = Math.max(1, s / 80);
        this.key.position.set(-50, -110, 150).multiplyScalar(scale).add(center);
        this.key.target.position.copy(center);
        this.setShadowExtent(s, scale);
    }

    fitDistance() {
        // Bruk den minste av loddrett og vannrett synsvinkel (viktig på smale skjermer).
        const vHalf = THREE.MathUtils.degToRad(this.camera.fov / 2);
        const hHalf = Math.atan(Math.tan(vHalf) * this.camera.aspect);
        return (this.focus.radius * this.fitFactor) / Math.sin(Math.min(vHalf, hHalf));
    }

    /** Animerer kameraet til en av de faste visningene. */
    setView(name, animate = true) {
        const dir = new THREE.Vector3(...this.views[name]).normalize();
        const target = this.focus.center.clone();
        const end = target.clone().addScaledVector(dir, this.fitDistance());
        this.userMoved = false;
        if (!animate) {
            this.controls.target.copy(target);
            this.camera.position.copy(end);
            return;
        }
        const startPos = this.camera.position.clone();
        const startDist = startPos.distanceTo(target);
        const startDir = startPos.clone().sub(target).normalize();
        const endDist = this.fitDistance();
        const t0 = performance.now();
        this.animation = (now) => {
            const t = Math.min(1, (now - t0) / 450);
            const e = t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
            const d = startDir.clone().lerp(dir, e);
            if (d.lengthSq() < 1e-6) d.set(0, -1, 0);   // motsatt retning: gå via front
            d.normalize();
            this.camera.position.copy(target).addScaledVector(d, startDist + (endDist - startDist) * e);
            this.controls.target.copy(target);
            if (t >= 1) this.animation = null;
        };
    }

    resize() {
        const { clientWidth: w, clientHeight: h } = this.container;
        if (!w || !h) return;
        this.renderer.setSize(w, h, false);
        this.camera.aspect = w / h;
        this.camera.updateProjectionMatrix();
        // Har ikke brukeren flyttet kameraet selv, tilpasses avstanden til den nye størrelsen.
        if (!this.userMoved && !this.animation) {
            const dir = this.camera.position.clone().sub(this.focus.center).normalize();
            if (dir.lengthSq() > 0.5) this.camera.position.copy(this.focus.center).addScaledVector(dir, this.fitDistance());
        }
    }

    tick() {
        if (this.animation) this.animation(performance.now());
        this.controls.update();
        this.renderer.render(this.scene, this.camera);
    }
}

/* ===================================================================== Terning */

// Kameraretninger. Ved å se på hver side på denne måten står den rett opp,
// slik som i PDF-ens utbrettede terning (TOPP: BAK opp, BUNN: FRONT opp).
const DICE_VIEWS = {
    iso:    [1, -1.25, 0.95],
    front:  [0, -1, 0],
    back:   [0, 1, 0],
    right:  [1, 0, 0],
    left:   [-1, 0, 0],
    top:    [0, -0.002, 1],
    bottom: [0, -0.002, -1],
};

const FACE_NAMES = {
    top: 'TOPP', bottom: 'BUNN', front: 'FRONT', back: 'BAK', left: 'VENSTRE', right: 'HØYRE',
};

export class DiceViewer extends ModelViewer {
    constructor(container) {
        super(container, DICE_VIEWS);
        this.mesh = null;
        this.labels = new THREE.Group();
        this.size = { width: 30, height: 30, depth: 30 };
        this.showNames = true;
        this.scene.add(this.labels);

        this.material = new THREE.MeshStandardMaterial({
            color: 0xf36d21, roughness: 0.6, metalness: 0.0, flatShading: true,
        });
        this.applyFocus(false);
        this.setView('iso', false);
    }

    applyFocus(refit) {
        const { width: w, height: h, depth: d } = this.size;
        const max = Math.max(w, h, d);
        this.setFocus(new THREE.Vector3(0, 0, h / 2), Math.hypot(w, h, d) / 2, refit,
            { fade: [max * 1.4, max * 3.6], min: max * 1.2, max: max * 12, shadow: 80 });
    }

    /** Laster en STL (ArrayBuffer) og erstatter modellen. */
    setModel(buffer, dimensions) {
        const geometry = this.loader.parse(buffer);
        geometry.computeBoundingBox();
        if (this.mesh) {
            this.scene.remove(this.mesh);
            this.mesh.geometry.dispose();
        }
        this.mesh = new THREE.Mesh(geometry, this.material);
        this.mesh.castShadow = true;
        this.scene.add(this.mesh);

        const prevMax = Math.max(this.size.width, this.size.height, this.size.depth);
        this.size = dimensions;
        this.updateLabels();

        // Behold brukerens vinkel, men juster avstanden når størrelsen endres.
        const max = Math.max(dimensions.width, dimensions.height, dimensions.depth);
        this.applyFocus(!this.hasModel || Math.abs(max - prevMax) > 0.01);
        this.hasModel = true;
    }

    /** Sidenavn som svever utenfor hver side. */
    updateLabels() {
        this.labels.clear();
        const { width: w, height: h, depth: d } = this.size;
        const gap = Math.max(w, h, d) * 0.28;
        const pos = {
            top: [0, 0, h + gap], bottom: [0, 0, -gap * 0.6],
            front: [0, -d / 2 - gap, h / 2], back: [0, d / 2 + gap, h / 2],
            right: [w / 2 + gap, 0, h / 2], left: [-w / 2 - gap, 0, h / 2],
        };
        const scale = Math.max(w, h, d) * 0.3;
        for (const [face, p] of Object.entries(pos)) {
            const sprite = makeTextSprite(FACE_NAMES[face]);
            sprite.position.set(...p);
            sprite.scale.set(scale * 2, scale * 0.5, 1);
            this.labels.add(sprite);
        }
        this.labels.visible = this.showNames;
    }

    setShowNames(show) {
        this.showNames = show;
        this.labels.visible = show;
    }
}

export function makeTextSprite(text) {
    const canvas = document.createElement('canvas');
    canvas.width = 256;
    canvas.height = 64;
    const ctx = canvas.getContext('2d');
    ctx.font = '600 32px Figtree, system-ui, Segoe UI, Arial, sans-serif';
    const w = Math.min(248, ctx.measureText(text).width + 28);
    ctx.fillStyle = 'rgba(255,253,249,0.94)';
    roundRect(ctx, (256 - w) / 2, 8, w, 48, 4);
    ctx.fill();
    ctx.strokeStyle = '#d9d2c4';
    ctx.lineWidth = 2;
    ctx.stroke();
    ctx.fillStyle = '#161616';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(text, 128, 33);
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    return new THREE.Sprite(new THREE.SpriteMaterial({ map: texture, transparent: true }));
}

function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}
