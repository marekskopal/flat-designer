import {Room, Scene} from '@app/models/scene';
import * as THREE from 'three';
import {OrbitControls} from 'three/examples/jsm/controls/OrbitControls.js';
import {RoomEnvironment} from 'three/examples/jsm/environments/RoomEnvironment.js';
import {RectAreaLightUniformsLib} from 'three/examples/jsm/lights/RectAreaLightUniformsLib.js';
import {CSS2DObject, CSS2DRenderer} from 'three/examples/jsm/renderers/CSS2DRenderer.js';

import {buildBuilding} from './building';
import {buildItem} from './items';
import {MaterialLibrary} from './materials';
import {Bounds, entranceWalkStart, MM, polygonBounds, polygonCentroid, snapToGrid, WalkStart, wallColliders} from './plan-geometry';
import {Selection, selectionOf} from './selection';
import {Walkthrough, WalkthroughFov} from './walkthrough';

export type ViewPreset = 'overview' | 'top';

interface CameraTween {
    fromPosition: THREE.Vector3;
    fromTarget: THREE.Vector3;
    toPosition: THREE.Vector3;
    toTarget: THREE.Vector3;
    start: number;
    duration: number;
}

const BackgroundColor = '#e8e4dd';
const HighlightColor = '#d9822b';
const TweenDuration = 900;
/** Framing of a floor with nothing on it yet (plan mm). */
const EmptyFloorBounds: Bounds = {minX: 0, minY: 0, maxX: 10000, maxY: 10000};
const OrbitFov = 45;

/** Snap grid for dragged items, mm (Shift: whole mm). */
const DragSnap = 10;
/** Dragging works only while the camera looks down steeply (Plan view), where plan moves are unambiguous. */
const DragMaxCameraTilt = -0.9;

interface ItemDrag {
    itemId: number;
    object: THREE.Object3D;
    plane: THREE.Plane;
    startPoint: THREE.Vector3;
    startX: number;
    startY: number;
    x: number;
    y: number;
}

/**
 * Framework-free three.js viewer for one floor: owns the renderer, camera, controls and the scene graph
 * built from a `Scene`. The Angular component only forwards options and events.
 */
export class ViewerEngine {
    /** A click selected an element (or empty space: null). */
    public onSelect: (selection: Selection | null) => void = () => undefined;
    /** An item was dragged to a new footprint centre (plan mm), in edit mode. */
    public onItemMoved: (itemId: number, x: number, y: number) => void = () => undefined;
    /** The walkthrough ended (Esc); the camera is on its way back to the overview. */
    public onWalkthroughEnd: () => void = () => undefined;
    /** The walkthrough captured the mouse, or lost it / was refused it (the walker has to click the view). */
    public onWalkthroughLock: (locked: boolean) => void = () => undefined;

    private readonly renderer: THREE.WebGLRenderer;
    private readonly labelRenderer: CSS2DRenderer;
    private readonly scene = new THREE.Scene();
    private readonly camera = new THREE.PerspectiveCamera(OrbitFov, 1, 0.05, 300);
    private readonly controls: OrbitControls;
    private readonly sun = new THREE.DirectionalLight('#fff3e0', 2.4);
    private readonly sectionPlane = new THREE.Plane(new THREE.Vector3(0, -1, 0), 10);
    private readonly resizeObserver: ResizeObserver;
    private readonly raycaster = new THREE.Raycaster();
    private readonly pointerDown = new THREE.Vector2();

    private content: THREE.Group | null = null;
    private materials: MaterialLibrary | null = null;
    private ceilings: THREE.Group | null = null;
    private floors = new Map<number, THREE.Mesh>();
    private labels = new Map<number, CSS2DObject>();
    private highlight: THREE.Mesh | null = null;
    /** Translucent box with edges around the selected item, wall or opening; follows it while dragged. */
    private outline: THREE.Mesh | null = null;
    private outlinedObject: THREE.Object3D | null = null;
    private items = new Map<number, THREE.Object3D>();
    private walls = new Map<number, THREE.Object3D>();
    private openings = new Map<number, THREE.Object3D>();
    private selection: Selection | null = null;
    private editMode = false;
    private drag: ItemDrag | null = null;
    private bounds: Bounds = {minX: 0, minY: 0, maxX: 1, maxY: 1};
    private insetLeft = 0;
    private tween: CameraTween | null = null;
    /** Floor currently shown; reloading the same floor (a live update) keeps the camera where it is. */
    private loadedFloorId: number | null = null;
    private sceneData: Scene | null = null;
    private walkthrough: Walkthrough | null = null;
    private lastFrame = performance.now();

    public constructor(private readonly container: HTMLElement) {
        RectAreaLightUniformsLib.init();

        this.renderer = new THREE.WebGLRenderer({antialias: true, powerPreference: 'high-performance'});
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.renderer.outputColorSpace = THREE.SRGBColorSpace;
        this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
        this.renderer.toneMappingExposure = 1.0;
        this.renderer.shadowMap.enabled = true;
        this.renderer.shadowMap.type = THREE.PCFShadowMap;
        container.appendChild(this.renderer.domElement);

        this.labelRenderer = new CSS2DRenderer();
        this.labelRenderer.domElement.classList.add('viewer-labels');
        container.appendChild(this.labelRenderer.domElement);

        this.controls = new OrbitControls(this.camera, this.renderer.domElement);
        this.controls.enableDamping = true;
        this.controls.dampingFactor = 0.08;
        this.controls.maxPolarAngle = THREE.MathUtils.degToRad(88);
        this.controls.minDistance = 0.5;
        this.controls.maxDistance = 60;
        this.controls.addEventListener('start', () => {
            this.tween = null;
            this.controls.enableDamping = true;
        });

        this.setupEnvironment();

        this.renderer.domElement.addEventListener('pointerdown', this.handlePointerDown);
        this.renderer.domElement.addEventListener('pointermove', this.handlePointerMove);
        this.renderer.domElement.addEventListener('pointerup', this.handlePointerUp);

        this.resizeObserver = new ResizeObserver(() => this.resize());
        this.resizeObserver.observe(container);
        this.resize();

        this.renderer.setAnimationLoop(() => this.frame());
    }

    public load(scene: Scene): void {
        this.clearContent();

        const anisotropy = this.renderer.capabilities.getMaxAnisotropy();
        this.materials = new MaterialLibrary(scene.materials, anisotropy);

        const content = new THREE.Group();
        content.name = `floor:${scene.floor.name}`;

        const building = buildBuilding(scene, this.materials);
        content.add(building.root);
        this.ceilings = building.ceilings;
        this.floors = building.floors;

        for (const item of scene.items) {
            const object = buildItem(item, this.materials);
            this.items.set(item.id, object);
            content.add(object);
        }
        content.traverse((object) => {
            const data = object.userData as Record<string, unknown>;
            if (typeof data['wallId'] === 'number') {
                this.walls.set(data['wallId'], object);
            }
            if (typeof data['openingId'] === 'number') {
                this.openings.set(data['openingId'], object);
            }
        });

        for (const room of scene.rooms) {
            const label = this.createLabel(room);
            this.labels.set(room.id, label);
            content.add(label);
        }

        this.content = content;
        this.scene.add(content);
        this.sceneData = scene;

        const wallPoints = scene.walls.flatMap((w) => [[w.startX, w.startY], [w.endX, w.endY]] as [number, number][]);
        const points = [...wallPoints, ...scene.rooms.flatMap((r) => r.polygon)];
        this.bounds = points.length > 0 ? polygonBounds(points) : EmptyFloorBounds;
        this.fitSunShadow();
        if (this.loadedFloorId !== scene.floor.id) {
            if (this.walkthrough !== null) {
                this.stopWalkthrough(false);
                this.onWalkthroughEnd();
            }
            this.setView('overview', false);
        }
        this.walkthrough?.setColliders(wallColliders(scene.walls));
        this.loadedFloorId = scene.floor.id;
        this.setSelection(this.selection);
    }

    /** Highlights the selected element: a room's floor is tinted, other elements get an outline box. */
    public setSelection(selection: Selection | null): void {
        this.selection = selection;
        this.setSelectedRoom(selection?.kind === 'room' ? selection.id : null);

        this.removeOutline();
        const object = this.objectOf(selection);
        if (object !== null) {
            this.outline = createOutline();
            this.outlinedObject = object;
            this.scene.add(this.outline);
            this.updateOutline();
        }
    }

    /** In edit mode items can be dragged in a top-down view. */
    public setEditMode(enabled: boolean): void {
        this.editMode = enabled;
        if (!enabled) {
            this.cancelDrag();
        }
    }

    /** The point the camera looks at, in plan mm: where new elements are placed. */
    public viewCenter(): {x: number; y: number} {
        return {x: Math.round(this.controls.target.x / MM), y: Math.round(-this.controls.target.z / MM)};
    }

    public setCeilingsVisible(visible: boolean): void {
        if (this.ceilings !== null) {
            this.ceilings.visible = visible;
        }
    }

    public setLabelsVisible(visible: boolean): void {
        this.labelRenderer.domElement.style.display = visible ? '' : 'none';
    }

    /** Cuts everything above `height` (mm) away; `null` shows the full height. */
    public setSectionHeight(height: number | null): void {
        if (height === null) {
            this.renderer.clippingPlanes = [];
            return;
        }
        this.sectionPlane.constant = height * MM;
        this.renderer.clippingPlanes = [this.sectionPlane];
    }

    /** Width (px) covered by UI on the left; the projection centre shifts right so the model is framed in the free area. */
    public setInsetLeft(pixels: number): void {
        this.insetLeft = pixels;
        this.resize();
    }

    public setView(preset: ViewPreset, animate = true): void {
        const center = this.planCenter();
        const width = (this.bounds.maxX - this.bounds.minX) * MM;
        const depth = (this.bounds.maxY - this.bounds.minY) * MM;

        switch (preset) {
            case 'overview':
                // From the south-west, above the living room, looking towards the kitchen corner.
                this.moveCamera(new THREE.Vector3(center.x - width * 1.1, Math.max(width, depth) * 0.95, center.z + depth * 0.7), center, animate);
                break;
            case 'top': {
                const halfFov = THREE.MathUtils.degToRad(this.camera.fov / 2);
                const distance = Math.max(depth / 2 / Math.tan(halfFov), width / 2 / (Math.tan(halfFov) * this.camera.aspect)) * 1.12;
                this.moveCamera(new THREE.Vector3(center.x, distance, center.z + 0.001), center, animate);
                break;
            }
        }
    }

    /**
     * Switches to a first-person walk at eye level, starting just inside the main door (or in the largest
     * indoor room, facing the way the camera does). Call it from a click: it captures the mouse.
     */
    public startWalkthrough(): void {
        if (this.walkthrough !== null || this.sceneData === null) {
            return;
        }
        this.cancelDrag();
        this.tween = null;
        this.controls.enabled = false;
        this.camera.fov = WalkthroughFov;
        this.camera.updateProjectionMatrix();

        const start = this.walkStart(this.sceneData);
        // The view looks along plan (−sin yaw, cos yaw).
        const yaw = Math.atan2(-start.direction.x, start.direction.y);
        const walkthrough = new Walkthrough(this.camera, this.renderer.domElement, wallColliders(this.sceneData.walls), start.position, yaw);
        walkthrough.onExit = (): void => {
            this.stopWalkthrough();
            this.onWalkthroughEnd();
        };
        walkthrough.onLockChange = (locked): void => this.onWalkthroughLock(locked);
        this.walkthrough = walkthrough;
        walkthrough.lock();
    }

    /** Back to the orbit controls; with `animate` the camera flies from the walker's eye to the overview. */
    public stopWalkthrough(animate = true): void {
        if (this.walkthrough === null) {
            return;
        }
        this.walkthrough.dispose();
        this.walkthrough = null;
        this.camera.fov = OrbitFov;
        this.camera.updateProjectionMatrix();
        // Orbit around a point ahead of the walker, so the hand-over does not jerk the view.
        const ahead = this.camera.getWorldDirection(new THREE.Vector3()).setY(0).normalize().multiplyScalar(2);
        this.controls.target.copy(this.camera.position).add(ahead);
        this.controls.enabled = true;
        this.setView('overview', animate);
    }

    /** Start of a walk: inside the entrance; without one, the middle of the largest indoor room (or the view centre). */
    private walkStart(scene: Scene): WalkStart {
        const entrance = entranceWalkStart(scene.walls, scene.rooms);
        if (entrance !== null) {
            return entrance;
        }
        const view = this.camera.getWorldDirection(new THREE.Vector3());
        const direction = Math.hypot(view.x, view.z) > 1e-3 ? {x: view.x, y: -view.z} : {x: 0, y: 1};
        const indoor = scene.rooms.filter((r) => r.type !== 'Terrace' && r.type !== 'Garden');
        if (indoor.length === 0) {
            return {position: this.viewCenter(), direction};
        }
        const largest = indoor.reduce((a, b) => (b.area > a.area ? b : a));
        return {position: polygonCentroid(largest.polygon), direction};
    }

    public focusRoom(room: Room): void {
        const c = polygonCentroid(room.polygon);
        const b = polygonBounds(room.polygon);
        const size = Math.max(b.maxX - b.minX, b.maxY - b.minY) * MM;
        const target = new THREE.Vector3(c.x * MM, 0.8, -c.y * MM);
        const offset = new THREE.Vector3(0.55, 1.1, 0.75).normalize().multiplyScalar(size * 1.4 + 3);
        this.moveCamera(target.clone().add(offset), target, true);
    }

    private setSelectedRoom(roomId: number | null): void {
        this.labels.forEach((label, id) => label.element.classList.toggle('selected', id === roomId));

        if (this.highlight !== null) {
            this.highlight.removeFromParent();
            (this.highlight.material as THREE.Material).dispose();
            this.highlight = null;
        }

        const floor = roomId === null ? undefined : this.floors.get(roomId);
        if (floor === undefined) {
            return;
        }
        const material = new THREE.MeshBasicMaterial({color: HighlightColor, transparent: true, opacity: 0.22, depthWrite: false});
        this.highlight = new THREE.Mesh(floor.geometry, material);
        this.highlight.position.y = floor.position.y + 0.002;
        this.highlight.renderOrder = 1;
        floor.parent?.add(this.highlight);
    }

    public dispose(): void {
        this.renderer.setAnimationLoop(null);
        this.resizeObserver.disconnect();
        this.renderer.domElement.removeEventListener('pointerdown', this.handlePointerDown);
        this.renderer.domElement.removeEventListener('pointermove', this.handlePointerMove);
        this.renderer.domElement.removeEventListener('pointerup', this.handlePointerUp);
        this.walkthrough?.dispose();
        this.walkthrough = null;
        this.clearContent();
        this.scene.environment?.dispose();
        this.controls.dispose();
        this.renderer.dispose();
        this.renderer.domElement.remove();
        this.labelRenderer.domElement.remove();
    }

    private setupEnvironment(): void {
        this.scene.background = new THREE.Color(BackgroundColor);

        // Soft image-based lighting for reflections on glass, steel and glossy fronts.
        const pmrem = new THREE.PMREMGenerator(this.renderer);
        this.scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
        this.scene.environmentIntensity = 0.45;
        pmrem.dispose();

        this.scene.add(new THREE.HemisphereLight('#fbf8f2', '#a9a196', 1.4));

        // Afternoon sun from the south-west, entering through the living-room windows.
        this.sun.castShadow = true;
        this.sun.shadow.mapSize.set(4096, 4096);
        this.sun.shadow.bias = -0.0004;
        this.sun.shadow.normalBias = 0.02;
        this.sun.shadow.radius = 3;
        this.scene.add(this.sun, this.sun.target);

        const ground = new THREE.Mesh(
            new THREE.CircleGeometry(60, 64),
            new THREE.MeshStandardMaterial({color: '#cfd2c4', roughness: 1}),
        );
        ground.rotation.x = -Math.PI / 2;
        // Just below the floor level: the gardens and the terrace lie on it, the slab is buried.
        ground.position.y = -0.02;
        ground.receiveShadow = true;
        ground.name = 'ground';
        this.scene.add(ground);
    }

    private fitSunShadow(): void {
        const center = this.planCenter();
        const radius = Math.hypot(this.bounds.maxX - this.bounds.minX, this.bounds.maxY - this.bounds.minY) * MM * 0.6 + 2;
        this.sun.position.set(center.x - 9, 14, center.z + 12);
        this.sun.target.position.copy(center);
        const cam = this.sun.shadow.camera;
        cam.left = -radius;
        cam.right = radius;
        cam.top = radius;
        cam.bottom = -radius;
        cam.near = 1;
        cam.far = 60;
        cam.updateProjectionMatrix();
    }

    private createLabel(room: Room): CSS2DObject {
        const element = document.createElement('div');
        element.className = 'room-label';
        const name = document.createElement('span');
        name.className = 'room-label__name';
        name.textContent = room.name;
        const meta = document.createElement('span');
        meta.className = 'room-label__meta';
        meta.textContent = `${room.code.replace('H1/', '')} · ${room.area.toFixed(1)} m²`;
        element.append(name, meta);

        const label = new CSS2DObject(element);
        const c = polygonCentroid(room.polygon);
        label.position.set(c.x * MM, 0.05, -c.y * MM);
        return label;
    }

    private planCenter(): THREE.Vector3 {
        return new THREE.Vector3(((this.bounds.minX + this.bounds.maxX) / 2) * MM, 0, (-(this.bounds.minY + this.bounds.maxY) / 2) * MM);
    }

    private moveCamera(position: THREE.Vector3, target: THREE.Vector3, animate: boolean): void {
        // Without damping, update() also discards any rotation momentum left from earlier input, which
        // would otherwise keep turning the camera away from the requested view.
        this.controls.enableDamping = false;
        if (!animate) {
            this.tween = null;
            this.camera.position.copy(position);
            this.controls.target.copy(target);
            this.controls.update();
            this.controls.enableDamping = true;
            return;
        }
        this.tween = {
            fromPosition: this.camera.position.clone(),
            fromTarget: this.controls.target.clone(),
            toPosition: position,
            toTarget: target,
            start: performance.now(),
            duration: TweenDuration,
        };
    }

    private frame(): void {
        const now = performance.now();
        const dt = (now - this.lastFrame) / 1000;
        this.lastFrame = now;
        if (this.walkthrough !== null) {
            this.walkthrough.update(dt);
            this.renderer.render(this.scene, this.camera);
            return;
        }

        if (this.tween !== null) {
            // performance.now() rather than the loop's timestamp: the two need not share a time origin.
            const t = THREE.MathUtils.clamp((performance.now() - this.tween.start) / this.tween.duration, 0, 1);
            const k = t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
            this.camera.position.lerpVectors(this.tween.fromPosition, this.tween.toPosition, k);
            this.controls.target.lerpVectors(this.tween.fromTarget, this.tween.toTarget, k);
            if (t >= 1) {
                this.tween = null;
                this.controls.enableDamping = true;
            }
        }
        this.controls.update();
        this.updateOutline();
        this.renderer.render(this.scene, this.camera);
        this.labelRenderer.render(this.scene, this.camera);
    }

    private resize(): void {
        const width = Math.max(this.container.clientWidth, 1);
        const height = Math.max(this.container.clientHeight, 1);
        this.camera.aspect = width / height;
        const inset = width > 2 * this.insetLeft ? this.insetLeft : 0;
        if (inset > 0) {
            this.camera.setViewOffset(width + inset, height, 0, 0, width, height);
        } else {
            this.camera.clearViewOffset();
        }
        this.camera.updateProjectionMatrix();
        this.renderer.setSize(width, height);
        this.labelRenderer.setSize(width, height);
    }

    private readonly handlePointerDown = (event: PointerEvent): void => {
        this.pointerDown.set(event.clientX, event.clientY);
        if (this.walkthrough !== null) {
            // The lock was refused or lost without ending the walk: a click captures the mouse again.
            this.walkthrough.lock();
            return;
        }
        if (!this.editMode || event.button !== 0 || this.camera.getWorldDirection(new THREE.Vector3()).y > DragMaxCameraTilt) {
            return;
        }

        const hit = this.pick(event);
        const selection = selectionOf(hit?.object ?? null);
        const object = selection?.kind === 'item' ? this.items.get(selection.id) : undefined;
        if (hit === undefined || selection === null || object === undefined) {
            return;
        }

        // Drag in the horizontal plane through the grabbed point; the item keeps its elevation.
        const plane = new THREE.Plane(new THREE.Vector3(0, 1, 0), -hit.point.y);
        this.drag = {
            itemId: selection.id,
            object,
            plane,
            startPoint: hit.point.clone(),
            startX: Math.round(object.position.x / MM),
            startY: Math.round(-object.position.z / MM),
            x: Math.round(object.position.x / MM),
            y: Math.round(-object.position.z / MM),
        };
        this.controls.enabled = false;
        this.renderer.domElement.setPointerCapture(event.pointerId);
        this.onSelect(selection);
    };

    private readonly handlePointerMove = (event: PointerEvent): void => {
        if (this.drag === null) {
            return;
        }
        this.raycaster.setFromCamera(this.toNdc(event), this.camera);
        const point = this.raycaster.ray.intersectPlane(this.drag.plane, new THREE.Vector3());
        if (point === null) {
            return;
        }
        const step = event.shiftKey ? 1 : DragSnap;
        this.drag.x = snapToGrid(this.drag.startX + (point.x - this.drag.startPoint.x) / MM, step);
        this.drag.y = snapToGrid(this.drag.startY - (point.z - this.drag.startPoint.z) / MM, step);
        this.drag.object.position.x = this.drag.x * MM;
        this.drag.object.position.z = -this.drag.y * MM;
    };

    /** A click (not a drag) selects the element under the pointer; a click on empty space clears it. */
    private readonly handlePointerUp = (event: PointerEvent): void => {
        if (this.drag !== null) {
            const drag = this.drag;
            this.cancelDrag(false);
            if (drag.x !== drag.startX || drag.y !== drag.startY) {
                this.onItemMoved(drag.itemId, drag.x, drag.y);
            }
            return;
        }
        if (this.walkthrough !== null || this.pointerDown.distanceTo(new THREE.Vector2(event.clientX, event.clientY)) > 4) {
            return;
        }
        this.onSelect(selectionOf(this.pick(event)?.object ?? null));
    };

    /** Ends a drag; unless the move is being committed, the item goes back to where it was. */
    private cancelDrag(restore = true): void {
        if (this.drag === null) {
            return;
        }
        if (restore) {
            this.drag.object.position.x = this.drag.startX * MM;
            this.drag.object.position.z = -this.drag.startY * MM;
        }
        this.drag = null;
        this.controls.enabled = true;
    }

    private pick(event: PointerEvent): THREE.Intersection | undefined {
        this.raycaster.setFromCamera(this.toNdc(event), this.camera);
        const hits = this.content === null ? [] : this.raycaster.intersectObject(this.content, true);
        const clip = this.renderer.clippingPlanes[0];

        return hits.find((h) => isVisible(h.object) && (clip === undefined || clip.distanceToPoint(h.point) >= 0));
    }

    private toNdc(event: PointerEvent): THREE.Vector2 {
        const rect = this.renderer.domElement.getBoundingClientRect();
        return new THREE.Vector2(((event.clientX - rect.left) / rect.width) * 2 - 1, -((event.clientY - rect.top) / rect.height) * 2 + 1);
    }

    private objectOf(selection: Selection | null): THREE.Object3D | null {
        switch (selection?.kind) {
            case 'item':
                return this.items.get(selection.id) ?? null;
            case 'wall':
                return this.walls.get(selection.id) ?? null;
            case 'opening':
                return this.openings.get(selection.id) ?? null;
            default:
                return null;
        }
    }

    /** Fits the outline box to the selected object's current bounds (cheap: a few small objects). */
    private updateOutline(): void {
        if (this.outline === null || this.outlinedObject === null) {
            return;
        }
        const bounds = new THREE.Box3().setFromObject(this.outlinedObject);
        if (bounds.isEmpty()) {
            return;
        }
        // A few mm larger, so the box does not z-fight with the faces it wraps.
        const size = bounds.getSize(new THREE.Vector3()).addScalar(0.01);
        bounds.getCenter(this.outline.position);
        this.outline.scale.copy(size);
    }

    private removeOutline(): void {
        if (this.outline !== null) {
            this.outline.removeFromParent();
            this.outline.traverse((o) => {
                if (o instanceof THREE.Mesh || o instanceof THREE.LineSegments) {
                    o.geometry.dispose();
                    (o.material as THREE.Material).dispose();
                }
            });
            this.outline = null;
        }
        this.outlinedObject = null;
    }

    private clearContent(): void {
        this.cancelDrag();
        this.setSelectedRoom(null);
        this.removeOutline();
        if (this.content !== null) {
            const geometries = new Set<THREE.BufferGeometry>();
            const materials = new Set<THREE.Material>();
            this.content.traverse((object) => {
                if (object instanceof THREE.Mesh) {
                    geometries.add(object.geometry);
                    (Array.isArray(object.material) ? object.material : [object.material]).forEach((m) => materials.add(m));
                }
                if (object instanceof CSS2DObject) {
                    object.element.remove();
                }
            });
            geometries.forEach((g) => g.dispose());
            materials.forEach((m) => m.dispose());
            this.content.removeFromParent();
            this.content = null;
        }
        this.materials?.dispose();
        this.materials = null;
        this.labels.clear();
        this.floors.clear();
        this.items.clear();
        this.walls.clear();
        this.openings.clear();
        this.ceilings = null;
    }
}

/** Unit box (scaled to the selection's bounds each frame): translucent faces plus edges, drawn on top. */
function createOutline(): THREE.Mesh {
    const geometry = new THREE.BoxGeometry(1, 1, 1);
    const box = new THREE.Mesh(
        geometry,
        new THREE.MeshBasicMaterial({color: HighlightColor, transparent: true, opacity: 0.18, depthTest: false, depthWrite: false}),
    );
    box.renderOrder = 2;
    const edges = new THREE.LineSegments(
        new THREE.EdgesGeometry(geometry),
        new THREE.LineBasicMaterial({color: HighlightColor, depthTest: false, transparent: true}),
    );
    edges.renderOrder = 3;
    box.add(edges);
    return box;
}

/** Visible itself and in every ancestor (hidden ceilings must not catch clicks). */
function isVisible(object: THREE.Object3D): boolean {
    for (let o: THREE.Object3D | null = object; o !== null; o = o.parent) {
        if (!o.visible) {
            return false;
        }
    }
    return true;
}
