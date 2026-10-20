import {Item, SceneModel} from '@app/models/scene';
import * as THREE from 'three';
import {MeshoptDecoder} from 'three/examples/jsm/libs/meshopt_decoder.module.js';
import {GLTFLoader} from 'three/examples/jsm/loaders/GLTFLoader.js';
import {clone as cloneWithSkeleton} from 'three/examples/jsm/utils/SkeletonUtils.js';

import {AssetLoader} from './asset-loader';
import {MM} from './plan-geometry';

/** Fetches the bytes of a model's `.glb` file (with the viewer's sign-in, or through a share link). */
export type ModelFetcher = (modelId: number) => Promise<ArrayBuffer>;

/**
 * The 3D models of `Model` items, loaded once per model and shared by its items: the template's geometries, materials and
 * textures belong to the library (its instances' meshes are tagged `userData.shared`, so disposing an item leaves them).
 * Framework-free; the host supplies the fetcher.
 */
export class ModelLibrary {
    private readonly gltf = new GLTFLoader().setMeshoptDecoder(MeshoptDecoder);
    /** Also told of a failed model: its items then show that it is missing. */
    private readonly templates: AssetLoader<THREE.Object3D>;

    public constructor(fetch: ModelFetcher) {
        this.templates = new AssetLoader(
            (modelId) => fetch(modelId).then((bytes) => this.gltf.parseAsync(bytes, '')).then((gltf) => centred(gltf.scene)),
            disposeTemplate,
            true,
        );
    }

    /** A model finished loading (or failed): rebuild the items that draw it. */
    public set onLoaded(listener: (modelId: number) => void) {
        this.templates.onLoaded = listener;
    }

    /** The model drawn at its native size (centred on its footprint, standing on y = 0), or null while it loads or after it failed. */
    public instance(model: SceneModel): THREE.Object3D | null {
        const template = this.templates.get(model.id);
        if (template === null) {
            return null;
        }
        // Not Object3D.clone(): a skinned mesh's clone has to get the cloned bones, not the template's.
        const instance = cloneWithSkeleton(template);
        instance.traverse((o) => {
            if (o instanceof THREE.Mesh) {
                o.userData['shared'] = true;
                o.castShadow = true;
                o.receiveShadow = true;
            }
        });
        return instance;
    }

    public hasFailed(modelId: number): boolean {
        return this.templates.hasFailed(modelId);
    }

    /** Follows the scene's models: templates no longer used are freed, a failed listed one loads again. Returns the ids changed. */
    public sync(modelIds: Iterable<number>): number[] {
        return this.templates.sync(modelIds);
    }

    /** Frees the templates; loads still under way are dropped when they finish. */
    public dispose(): void {
        this.templates.dispose();
    }
}

/** Frees a template's geometries, materials and textures (its instances only share them). */
function disposeTemplate(template: THREE.Object3D): void {
    template.traverse((o) => {
        if (o instanceof THREE.Mesh) {
            o.geometry.dispose();
            for (const material of Array.isArray(o.material) ? o.material : [o.material]) {
                for (const value of Object.values(material)) {
                    if (value instanceof THREE.Texture) {
                        value.dispose();
                    }
                }
                material.dispose();
            }
        }
    });
}

/** The scene wrapped so that its bounding box stands on y = 0, centred on x and z. */
function centred(scene: THREE.Object3D): THREE.Object3D {
    const box = new THREE.Box3().setFromObject(scene);
    const centre = box.getCenter(new THREE.Vector3());
    scene.position.sub(new THREE.Vector3(centre.x, box.min.y, centre.z));
    const holder = new THREE.Group();
    holder.add(scene);
    return holder;
}

/**
 * The body of a `Model` item in its local frame (origin at the footprint centre on the floor, +z the front): the model
 * scaled evenly to fit the item's box, standing on its bottom. While the model loads, or when it is missing or cannot be
 * read, a light box of the item's size stands in.
 */
export function buildModelItemBody(item: Item, models: ReadonlyMap<number, SceneModel>, library: ModelLibrary | null): THREE.Object3D | null {
    if (item.kind !== 'Model') {
        return null;
    }
    const model = item.modelId == null ? undefined : models.get(item.modelId);
    const instance = model === undefined || library === null ? null : library.instance(model);
    const group = new THREE.Group();
    if (model === undefined || instance === null) {
        const missing = model === undefined || library?.hasFailed(model.id) === true;
        group.add(placeholder(item, missing));
        group.userData['modelPending'] = !missing;
        return group;
    }
    const scale = Math.min(item.width / model.width, item.depth / model.depth, item.height / model.height);
    instance.scale.setScalar(scale);
    group.add(instance);
    return group;
}

function placeholder(item: Item, missing: boolean): THREE.Mesh {
    const material = new THREE.MeshStandardMaterial({
        color: missing ? '#c0504d' : '#b8b4ac',
        transparent: true,
        opacity: missing ? 0.6 : 0.35,
        roughness: 0.9,
    });
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(item.width * MM, item.height * MM, item.depth * MM), material);
    mesh.position.y = (item.height * MM) / 2;
    return mesh;
}
