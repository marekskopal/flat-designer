import {HttpClient} from '@angular/common/http';
import {inject, Injectable} from '@angular/core';
import {SavedView, SavedViewInput} from '@app/models/saved-view';
import {environment} from '@environments/environment';
import {firstValueFrom} from 'rxjs';

const projectsUrl = `${environment.apiUrl}/projects`;

/** The saved views of a project. */
@Injectable({providedIn: 'root'})
export class SavedViewService {
    private readonly http = inject(HttpClient);

    public async list(projectId: number): Promise<SavedView[]> {
        return firstValueFrom(this.http.get<SavedView[]>(`${projectsUrl}/${projectId}/views`));
    }

    public async create(projectId: number, input: SavedViewInput): Promise<SavedView> {
        return firstValueFrom(this.http.post<SavedView>(`${projectsUrl}/${projectId}/views`, input));
    }

    public async update(projectId: number, viewId: number, input: SavedViewInput): Promise<SavedView> {
        return firstValueFrom(this.http.put<SavedView>(`${projectsUrl}/${projectId}/views/${viewId}`, input));
    }

    public async delete(projectId: number, viewId: number): Promise<void> {
        await firstValueFrom(this.http.delete<void>(`${projectsUrl}/${projectId}/views/${viewId}`));
    }
}
