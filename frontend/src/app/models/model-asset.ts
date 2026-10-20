import {FurnitureScope} from './furniture';

/** A 3D model of a bought product in the library: native size (mm), file and credits. */
export interface ModelAsset {
    id: number;
    name: string;
    description: string;
    scope: FurnitureScope;
    userId: number | null;
    projectId: number | null;
    filename: string;
    size: number;
    width: number;
    depth: number;
    height: number;
    license: string;
    author: string;
    sourceUrl: string;
    writable: boolean;
    updatedAt: string;
}

/** The describing fields of a model; scope and project only on upload. */
export interface ModelAssetInput {
    name: string;
    description?: string;
    license?: string;
    author?: string;
    sourceUrl?: string;
    scope?: FurnitureScope;
    projectId?: number;
}
