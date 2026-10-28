import {HttpClient, HttpHeaders} from '@angular/common/http';
import {inject, Injectable} from '@angular/core';
import {Change, EditResult, ItemKindInfo} from '@app/models/editor';
import {PlanPoint, Underlay, WallType} from '@app/models/scene';
import {ElementKind} from '@app/viewer/engine/selection';
import {environment} from '@environments/environment';
import {firstValueFrom} from 'rxjs';

const Segments: Record<ElementKind, string> = {
    item: 'items',
    wall: 'walls',
    opening: 'openings',
    room: 'rooms',
    plot: 'plots',
    stair: 'stairs',
    roof: 'roofs',
    group: 'groups',
    variantSet: 'variant-sets',
};

/** Fields shared by the walls of a drawn run. */
export interface WallDraft {
    type: WallType;
    thickness: number;
    height: number;
}

/** Shift (mm) and counter-clockwise turn (degrees, about the group's footprint centre) of a group. */
export interface GroupMove {
    dx?: number;
    dy?: number;
    rotation?: number;
}

/** A floor's underlay to set: the image and the placement fields that change (left out: kept). */
export interface UnderlayInput {
    fileId: number;
    x?: number;
    y?: number;
    scale?: number;
    rotation?: number;
    opacity?: number;
}

/** The editing endpoints. `baseRevision` goes out as `If-Match`, so a stale edit fails with 409. */
@Injectable({providedIn: 'root'})
export class EditorApiService {
    private readonly http = inject(HttpClient);

    public async create(
        projectId: number,
        floorId: number,
        kind: ElementKind,
        fields: Record<string, unknown>,
        baseRevision?: number,
    ): Promise<EditResult> {
        return firstValueFrom(this.http.post<EditResult>(this.url(projectId, floorId, kind), fields, {headers: ifMatch(baseRevision)}));
    }

    public async update(
        projectId: number,
        floorId: number,
        kind: ElementKind,
        id: number,
        fields: Record<string, unknown>,
        baseRevision?: number,
    ): Promise<EditResult> {
        return firstValueFrom(this.http.patch<EditResult>(`${this.url(projectId, floorId, kind)}/${id}`, fields, {headers: ifMatch(baseRevision)}));
    }

    public async delete(projectId: number, floorId: number, kind: ElementKind, id: number, baseRevision?: number): Promise<EditResult> {
        return firstValueFrom(this.http.delete<EditResult>(`${this.url(projectId, floorId, kind)}/${id}`, {headers: ifMatch(baseRevision)}));
    }

    public async duplicate(projectId: number, floorId: number, kind: ElementKind, id: number, baseRevision?: number): Promise<EditResult> {
        return firstValueFrom(
            this.http.post<EditResult>(`${this.url(projectId, floorId, kind)}/${id}/duplicate`, {}, {headers: ifMatch(baseRevision)}),
        );
    }

    public async createGroup(projectId: number, floorId: number, name: string, itemIds: number[], baseRevision?: number): Promise<EditResult> {
        return firstValueFrom(
            this.http.post<EditResult>(this.url(projectId, floorId, 'group'), {name, itemIds}, {headers: ifMatch(baseRevision)}),
        );
    }

    public async moveGroup(projectId: number, floorId: number, id: number, move: GroupMove, baseRevision?: number): Promise<EditResult> {
        return firstValueFrom(
            this.http.post<EditResult>(`${this.url(projectId, floorId, 'group')}/${id}/move`, move, {headers: ifMatch(baseRevision)}),
        );
    }

    public async ungroup(projectId: number, floorId: number, id: number, baseRevision?: number): Promise<EditResult> {
        return firstValueFrom(
            this.http.post<EditResult>(`${this.url(projectId, floorId, 'group')}/${id}/ungroup`, {}, {headers: ifMatch(baseRevision)}),
        );
    }

    public async createVariantSet(projectId: number, floorId: number, name: string, groupIds: number[], baseRevision?: number): Promise<EditResult> {
        return firstValueFrom(
            this.http.post<EditResult>(this.url(projectId, floorId, 'variantSet'), {name, groupIds}, {headers: ifMatch(baseRevision)}),
        );
    }

    /** A copy of the variant `fromGroupId` (null: an empty variant), shown from then on. */
    public async addVariant(
        projectId: number,
        floorId: number,
        setId: number,
        fromGroupId: number | null,
        baseRevision?: number,
    ): Promise<EditResult> {
        const url = `${this.url(projectId, floorId, 'variantSet')}/${setId}/variants`;
        return firstValueFrom(this.http.post<EditResult>(url, {fromGroupId}, {headers: ifMatch(baseRevision)}));
    }

    public async switchVariant(projectId: number, floorId: number, setId: number, groupId: number, baseRevision?: number): Promise<EditResult> {
        return firstValueFrom(
            this.http.post<EditResult>(`${this.url(projectId, floorId, 'variantSet')}/${setId}/switch`, {groupId}, {headers: ifMatch(baseRevision)}),
        );
    }

    /** A run of walls through centre-line points, curved by `sagittas` (one per wall; null: straight), as one change listing the batch. */
    public async drawWalls(
        projectId: number,
        floorId: number,
        points: PlanPoint[],
        closed: boolean,
        fields: Partial<WallDraft>,
        baseRevision?: number,
        sagittas: number[] | null = null,
    ): Promise<EditResult> {
        const url = `${this.url(projectId, floorId, 'wall')}/polyline`;
        const body = {...fields, points, closed, ...(sagittas === null ? {} : {sagittas})};
        return firstValueFrom(this.http.post<EditResult>(url, body, {headers: ifMatch(baseRevision)}));
    }

    /** A room outlined by the walls around the point; the other fields as for any new room. */
    public async detectRoom(
        projectId: number,
        floorId: number,
        point: PlanPoint,
        fields: Record<string, unknown>,
        baseRevision?: number,
    ): Promise<EditResult> {
        const url = `${this.url(projectId, floorId, 'room')}/detect`;
        return firstValueFrom(this.http.post<EditResult>(url, {...fields, x: point[0], y: point[1]}, {headers: ifMatch(baseRevision)}));
    }

    public async undo(projectId: number): Promise<EditResult> {
        return firstValueFrom(this.http.post<EditResult>(`${environment.apiUrl}/projects/${projectId}/undo`, {}));
    }

    public async redo(projectId: number): Promise<EditResult> {
        return firstValueFrom(this.http.post<EditResult>(`${environment.apiUrl}/projects/${projectId}/redo`, {}));
    }

    public async changes(projectId: number, limit = 30): Promise<Change[]> {
        return firstValueFrom(this.http.get<Change[]>(`${environment.apiUrl}/projects/${projectId}/changes`, {params: {limit}}));
    }

    /** Sets the plan image under the floor's top view (not an element: no undo, no `If-Match`). */
    public async setUnderlay(
        projectId: number,
        floorId: number,
        input: UnderlayInput,
        baseRevision?: number,
    ): Promise<{underlay: Underlay; revision: number}> {
        return firstValueFrom(
            this.http.put<{underlay: Underlay; revision: number}>(this.underlayUrl(projectId, floorId), input, {headers: ifMatch(baseRevision)}),
        );
    }

    public async removeUnderlay(projectId: number, floorId: number, baseRevision?: number): Promise<{revision: number}> {
        return firstValueFrom(this.http.delete<{revision: number}>(this.underlayUrl(projectId, floorId), {headers: ifMatch(baseRevision)}));
    }

    private underlayUrl(projectId: number, floorId: number): string {
        return `${environment.apiUrl}/projects/${projectId}/floors/${floorId}/underlay`;
    }

    public async itemKinds(): Promise<ItemKindInfo[]> {
        return firstValueFrom(this.http.get<ItemKindInfo[]>(`${environment.apiUrl}/item-kinds`));
    }

    private url(projectId: number, floorId: number, kind: ElementKind): string {
        return `${environment.apiUrl}/projects/${projectId}/floors/${floorId}/${Segments[kind]}`;
    }
}

function ifMatch(revision: number | undefined): HttpHeaders {
    return revision === undefined ? new HttpHeaders() : new HttpHeaders({'If-Match': String(revision)});
}
