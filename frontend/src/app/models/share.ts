import {ElectricalSymbols} from '@app/models/electrical-schedule';
import {Scene, SunTime} from '@app/models/scene';

// Mirrors the backend DTOs (Izbik\Dto\Share*).

/** View modes a share link can offer (the viewer page's view names). */
export type ShareView = 'overview' | 'top' | 'walkthrough';

/** A camera point in plan mm: x east, y north, z elevation above the floor. */
export interface CameraPoint {
    x: number;
    y: number;
    z: number;
}

/** The lenses a camera may have, focal lengths in mm on 35 mm film (`ShareCameraDto`). */
export const MinFocalLength = 10;
export const MaxFocalLength = 200;

/** 3D camera: where it stands, the point it looks at and its lens. */
export interface CameraPose {
    position: CameraPoint;
    target: CameraPoint;
    /** Focal length in mm on 35 mm film across the view's longer side; null or missing is the standard view. */
    focalLength?: number | null;
}

/** What a share link's guests may do with the floor's review comments. */
export type ShareComments = 'hidden' | 'read' | 'write';

export interface ShareSettings {
    views: ShareView[];
    initialView: ShareView;
    /** Start of the 3D view; null = the default overview framing. Only with 'overview' allowed. */
    camera: CameraPose | null;
    ceilings: boolean;
    /** The roofs; missing in links saved before them, which show them. */
    roofs?: boolean;
    labels: boolean;
    /** Wall cut in mm; null = none. */
    sectionHeight: number | null;
    /** Dimension lines of the rooms in the top view. */
    dimensions: boolean;
    /** Electrical symbols in the top view; missing in links saved before them, which hide them. */
    electrical?: boolean;
    /** Opens after dark, lit by the flat's lights; missing in links saved before the night view. */
    night?: boolean;
    /** The sun at a clock time on site; null or missing (older links): the default afternoon sun. */
    sun?: SunTime | null;
    /** Opens in the presentation quality; missing in older links. */
    presentation?: boolean;
    /**
     * The guest may download the plan (PDF, SVG), the wall elevations and the 3D model (glTF); missing in links saved
     * before the exports, which allow them.
     */
    exports?: boolean;
    /** The floor's open review comments: hidden, shown, or guests may add some; missing in older links (hidden). */
    comments?: ShareComments;
    /** The floors under the shared one are drawn below it (`SharedView.stack`); missing in older links. */
    allFloors?: boolean;
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
    /** With `settings.allFloors`: the scenes of the floors under the shared one, lowest first. Missing in older servers. */
    stack?: Scene[];
    /** With `settings.allFloors`, the floors above the shared one, for a walk over all floors; missing in older answers. */
    above?: Scene[];
    /** The project owner's symbol set for the electrical plan; missing in older answers. */
    electricalSymbols?: ElectricalSymbols;
}

export const ShareViewLabels: Record<ShareView, string> = {overview: '3D', top: 'Plan', walkthrough: 'Walk'};

export const shareUrl = (token: string): string => `${location.origin}/s/${token}`;
