import {Scene} from '@app/models/scene';

// Mirrors the backend DTOs (FlatDesigner\Dto\Share*).

/** View modes a share link can offer (the viewer page's view names). */
export type ShareView = 'overview' | 'top' | 'walkthrough';

/** A camera point in plan mm: x east, y north, z elevation above the floor. */
export interface CameraPoint {
    x: number;
    y: number;
    z: number;
}

/** 3D camera: where it stands and the point it looks at. */
export interface CameraPose {
    position: CameraPoint;
    target: CameraPoint;
}

export interface ShareSettings {
    views: ShareView[];
    initialView: ShareView;
    /** Start of the 3D view; null = the default overview framing. Only with 'overview' allowed. */
    camera: CameraPose | null;
    ceilings: boolean;
    labels: boolean;
    /** Wall cut in mm; null = none. */
    sectionHeight: number | null;
    /** Dimension lines of the rooms in the top view. */
    dimensions: boolean;
}

export interface ShareLink {
    id: number;
    /** The link is `/s/{token}`. */
    token: string;
    name: string;
    /** Floor id inside the link's version. */
    floorId: number;
    floorName: string;
    versionId: number;
    versionNumber: number;
    settings: ShareSettings;
    expiresAt: string | null;
    expired: boolean;
    createdByName: string | null;
    createdAt: string;
    /** Full link, from the origin the request came through; the client builds its own with `shareUrl()`. */
    url: string | null;
}

export interface ShareLinkInput {
    /** Floor of the working copy to share; read on create only. */
    floorId?: number;
    name: string;
    settings: ShareSettings;
    /** ISO 8601; null = never. */
    expiresAt: string | null;
}

/** What a guest gets: the settings and the floor frozen in the link's version. */
export interface SharedView {
    name: string;
    projectName: string;
    settings: ShareSettings;
    scene: Scene;
}

export const ShareViewLabels: Record<ShareView, string> = {overview: '3D', top: 'Plan', walkthrough: 'Walk'};

export const shareUrl = (token: string): string => `${location.origin}/s/${token}`;
