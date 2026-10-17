import {HttpClient, HttpHeaders} from '@angular/common/http';
import {inject, Injectable} from '@angular/core';
import {Change, EditResult, ItemKindInfo} from '@app/models/editor';
import {ElementKind} from '@app/viewer/engine/selection';
import {environment} from '@environments/environment';
import {firstValueFrom} from 'rxjs';

const Segments: Record<ElementKind, string> = {
    item: 'items',
    wall: 'walls',
    opening: 'openings',
    room: 'rooms',
    group: 'groups',
    variantSet: 'variant-sets',
};

/** Shift (mm) and counter-clockwise turn (degrees, about the group's footprint centre) of a group. */
export interface GroupMove {
    dx?: number;
    dy?: number;
    rotation?: number;
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

    public async undo(projectId: number): Promise<EditResult> {
        return firstValueFrom(this.http.post<EditResult>(`${environment.apiUrl}/projects/${projectId}/undo`, {}));
    }

    public async redo(projectId: number): Promise<EditResult> {
        return firstValueFrom(this.http.post<EditResult>(`${environment.apiUrl}/projects/${projectId}/redo`, {}));
    }

    public async changes(projectId: number, limit = 30): Promise<Change[]> {
        return firstValueFrom(this.http.get<Change[]>(`${environment.apiUrl}/projects/${projectId}/changes`, {params: {limit}}));
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
