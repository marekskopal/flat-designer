import {Floor} from '@app/models/scene';

// Mirrors the backend DTOs (Izbik\Dto\Project*).

/** An example project shipped with the app, which a user copies to start from. */
export interface Example {
    slug: string;
    name: string;
    description: string;
    /** Where it comes from, e.g. the building it is modelled after. */
    credit: string;
}

/** A project made from an example, with where to open it. */
export interface ExampleProject {
    project: Project;
    /** The floor to open; null only for a project without floors. */
    floorId: number | null;
    /** The saved view to open it at, if the example names one. */
    viewId: number | null;
}

export interface Project {
    id: number;
    name: string;
    address: string | null;
    description: string | null;
    /** Degrees clockwise from plan north (+y) to true north, 0–359. */
    northAngle: number;
    /** Where the building stands, degrees (north and east positive); both null when not set. */
    latitude: number | null;
    longitude: number | null;
    /** IANA name the sun's clock time is read in; null: local solar time. */
    timeZone: string | null;
    /** The façade of the exterior walls' outer faces nobody set; null: the walls' own material. */
    exteriorMaterialId?: number | null;
    ownerId: number | null;
    ownerName: string | null;
    /** Ordered by level. A project always has at least one. */
    floors: Floor[];
    /** Number of the newest saved version, null when none was saved yet. */
    latestVersion: number | null;
    /** Bumped by every change of the floors. */
    revision: number;
    createdAt: string;
    updatedAt: string;
}

/** An update replaces all fields; the site fields left out are 0 / none. */
export interface ProjectInput {
    name: string;
    address: string | null;
    description: string | null;
    northAngle?: number;
    latitude?: number | null;
    longitude?: number | null;
    timeZone?: string | null;
    exteriorMaterialId?: number | null;
}

export interface FloorInput {
    name: string;
    level: number;
    /** mm above ±0.000; null stacks a new floor on the one below (and keeps an edited floor's). */
    elevation: number | null;
    /** mm; null takes the default for a new floor (and keeps an edited floor's). */
    slabThickness: number | null;
}

export interface ProjectVersion {
    id: number;
    number: number;
    note: string | null;
    createdByName: string | null;
    createdAt: string;
}

/** A saved version with the floors stored in it. */
export interface ProjectVersionDetail {
    version: ProjectVersion;
    floors: Floor[];
}

/** Files the viewer can draw as an image: an underlay, or a picture of a `Custom` item. */
export const ImageMimeTypes = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

export interface ProjectFile {
    id: number;
    filename: string;
    /** Detected from the content by the server. */
    mimeType: string;
    /** Bytes. */
    size: number;
    uploadedByName: string | null;
    createdAt: string;
}
