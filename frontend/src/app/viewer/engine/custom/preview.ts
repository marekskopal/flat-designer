import {Item, Material, SceneDefinition} from '@app/models/scene';
import * as THREE from 'three';
import {OrbitControls} from 'three/examples/jsm/controls/OrbitControls.js';
import {RoomEnvironment} from 'three/examples/jsm/environments/RoomEnvironment.js';
import {RectAreaLightUniformsLib} from 'three/examples/jsm/lights/RectAreaLightUniformsLib.js';

import {buildItem} from '../items';
import {MaterialLibrary} from '../materials';
import {MM} from '../plan-geometry';
import {definitionLibrary} from './custom';

const BackgroundColor = '#efece6';

/** What the preview shows: a definition at a size with parameter values and slot materials. */
export interface PreviewItem {
    definition: SceneDefinition;
    width: number;
    depth: number;
    height: number;
    values: Record<string, unknown>;
    materials: Record<string, number>;
}

/**
 * One Custom item on a floor with an orbiting camera, for the furniture definition editor: the same builder as the
 * viewer (`buildItem`), so what shows here is what an item of the definition looks like on a plan. `show()` rebuilds it;
 * the camera frames it again only when its size changes, so editing parts keeps the view.
 */
export class FurniturePreview {
    private readonly renderer: THREE.WebGLRenderer;
    private readonly scene = new THREE.Scene();
    private readonly camera = new THREE.PerspectiveCamera(40, 1, 0.05, 100);
    private readonly controls: OrbitControls;
    private readonly resizeObserver: ResizeObserver;
    private materials: MaterialLibrary | null = null;
    private object: THREE.Object3D | null = null;
    private framedSize = '';

    public constructor(private readonly container: HTMLElement) {
        RectAreaLightUniformsLib.init();
        this.renderer = new THREE.WebGLRenderer({antialias: true});
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.renderer.outputColorSpace = THREE.SRGBColorSpace;
        this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
        this.renderer.shadowMap.enabled = true;
        container.appendChild(this.renderer.domElement);

        this.scene.background = new THREE.Color(BackgroundColor);
        const pmrem = new THREE.PMREMGenerator(this.renderer);
        this.scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
        this.scene.environmentIntensity = 0.45;
        pmrem.dispose();
        this.scene.add(new THREE.HemisphereLight('#fbf8f2', '#a9a196', 1.4));
        const sun = new THREE.DirectionalLight('#fff4e0', 2.2);
        sun.position.set(-3, 5, 4);
        sun.castShadow = true;
        sun.shadow.mapSize.set(2048, 2048);
        sun.shadow.bias = -0.0004;
        sun.shadow.normalBias = 0.02;
        this.scene.add(sun);
        const floor = new THREE.Mesh(new THREE.CircleGeometry(8, 48), new THREE.MeshStandardMaterial({color: '#d9d4ca', roughness: 1}));
        floor.rotation.x = -Math.PI / 2;
        floor.receiveShadow = true;
        this.scene.add(floor);
        this.scene.add(new THREE.GridHelper(8, 80, '#bdb6aa', '#cec8bd'));

        this.controls = new OrbitControls(this.camera, this.renderer.domElement);
        this.controls.enableDamping = true;
        this.controls.maxPolarAngle = THREE.MathUtils.degToRad(88);

        this.resizeObserver = new ResizeObserver(() => this.resize());
        this.resizeObserver.observe(container);
        this.resize();
        this.renderer.setAnimationLoop(() => {
            this.controls.update();
            this.renderer.render(this.scene, this.camera);
        });
    }

    public setMaterials(materials: Material[]): void {
        this.materials?.dispose();
        this.materials = new MaterialLibrary(materials, this.renderer.capabilities.getMaxAnisotropy());
    }

    /** Builds the item; the reason when it falls back to the red placeholder, otherwise null. */
    public show(preview: PreviewItem): string | null {
        if (this.materials === null) {
            return null;
        }
        this.clear();
        const item: Item = {
            id: 0,
            roomId: null,
            groupId: null,
            kind: 'Custom',
            definitionId: preview.definition.id,
            name: preview.definition.name,
            x: 0,
            y: 0,
            z: 0,
            width: preview.width,
            depth: preview.depth,
            height: preview.height,
            rotation: 0,
            materialId: null,
            params: {values: preview.values, materials: preview.materials},
        };
        this.object = buildItem(item, this.materials, undefined, definitionLibrary([preview.definition]));
        this.scene.add(this.object);

        const size = `${preview.width}×${preview.depth}×${preview.height}`;
        if (size !== this.framedSize) {
            this.framedSize = size;
            this.frame(preview.width * MM, preview.depth * MM, preview.height * MM);
        }
        const error: unknown = this.object.userData['definitionError'];
        return typeof error === 'string' ? error : null;
    }

    public dispose(): void {
        this.renderer.setAnimationLoop(null);
        this.resizeObserver.disconnect();
        this.clear();
        this.materials?.dispose();
        this.controls.dispose();
        this.renderer.dispose();
        this.renderer.domElement.remove();
    }

    /** Looks at the item from the front left, slightly above, far enough to see all of it. */
    private frame(width: number, depth: number, height: number): void {
        const radius = Math.max(Math.hypot(width, depth, height) / 2, 0.2);
        const distance = radius / Math.sin(THREE.MathUtils.degToRad(this.camera.fov / 2)) * 1.1;
        const target = new THREE.Vector3(0, height / 2, 0);
        const direction = new THREE.Vector3(-0.55, 0.45, 1).normalize();
        this.camera.position.copy(target).addScaledVector(direction, distance);
        this.controls.target.copy(target);
        this.controls.update();
    }

    private clear(): void {
        if (this.object === null) {
            return;
        }
        this.object.removeFromParent();
        this.object.traverse((o) => {
            if (o instanceof THREE.Mesh) {
                (o.geometry as THREE.BufferGeometry).dispose();
                for (const material of Array.isArray(o.material) ? o.material : [o.material]) {
                    if (this.materials?.owns(material) !== true) {
                        material.dispose();
                    }
                }
            }
        });
        this.object = null;
    }

    private resize(): void {
        const width = Math.max(this.container.clientWidth, 1);
        const height = Math.max(this.container.clientHeight, 1);
        this.renderer.setSize(width, height);
        this.camera.aspect = width / height;
        this.camera.updateProjectionMatrix();
    }
}
