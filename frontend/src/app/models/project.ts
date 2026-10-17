import {Floor} from '@app/models/scene';

// Mirrors the backend DTOs (FlatDesigner\Dto\Project*).

export interface Project {
    id: number;
    name: string;
    address: string | null;
    description: string | null;
    ownerId: number | null;
    ownerName: string | null;
    /** Ordered by level. A project always has at least one. */
    floors: Floor[];
    /** Number of the newest saved version, null when none was saved yet. */
    latestVersion: number | null;
    createdAt: string;
    updatedAt: string;
}

export interface ProjectInput {
    name: string;
    address: string | null;
    description: string | null;
}

export interface FloorInput {
    name: string;
    level: number;
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
