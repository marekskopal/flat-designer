import {CameraPose} from './share';

/** How a saved view shows its floor (`SavedViewSettingsDto`). */
export interface SavedViewSettings {
    /** 3D or plan; an eye-level shot is a 3D camera at eye height. */
    view: 'overview' | 'top';
    /** Null: the default framing of the floor. */
    camera: CameraPose | null;
    ceilings: boolean;
    labels: boolean;
    sectionHeight: number | null;
    dimensions: boolean;
    night: boolean;
    /** Null: the lights follow the night. */
    lightsOn: boolean | null;
    presentation: boolean;
    /** The variant shown per variant set: set id → group id. */
    variants: Record<string, number>;
}

export interface SavedView {
    id: number;
    name: string;
    floorId: number;
    floorName: string;
    settings: SavedViewSettings;
    createdByName: string | null;
    updatedAt: string;
}

export interface SavedViewInput {
    floorId: number;
    name: string;
    settings: SavedViewSettings;
}
