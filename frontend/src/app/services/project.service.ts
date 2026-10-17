import {HttpClient} from '@angular/common/http';
import {inject, Injectable} from '@angular/core';
import {FloorInput, Project, ProjectInput, ProjectVersion, ProjectVersionDetail} from '@app/models/project';
import {Floor, Scene} from '@app/models/scene';
import {environment} from '@environments/environment';
import {firstValueFrom} from 'rxjs';

const projectsUrl = `${environment.apiUrl}/projects`;

/** Projects, their floors (the working copy) and saved versions. */
@Injectable({providedIn: 'root'})
export class ProjectService {
    private readonly http = inject(HttpClient);

    public async list(): Promise<Project[]> {
        return firstValueFrom(this.http.get<Project[]>(projectsUrl));
    }

    public async get(projectId: number): Promise<Project> {
        return firstValueFrom(this.http.get<Project>(`${projectsUrl}/${projectId}`));
    }

    public async create(input: ProjectInput): Promise<Project> {
        return firstValueFrom(this.http.post<Project>(projectsUrl, input));
    }

    public async update(projectId: number, input: ProjectInput): Promise<Project> {
        return firstValueFrom(this.http.put<Project>(`${projectsUrl}/${projectId}`, input));
    }

    public async delete(projectId: number): Promise<void> {
        await firstValueFrom(this.http.delete<void>(`${projectsUrl}/${projectId}`));
    }

    public async createFloor(projectId: number, input: FloorInput): Promise<Floor> {
        return firstValueFrom(this.http.post<Floor>(`${projectsUrl}/${projectId}/floors`, input));
    }

    public async updateFloor(projectId: number, floorId: number, input: FloorInput): Promise<Floor> {
        return firstValueFrom(this.http.put<Floor>(`${projectsUrl}/${projectId}/floors/${floorId}`, input));
    }

    public async deleteFloor(projectId: number, floorId: number): Promise<void> {
        await firstValueFrom(this.http.delete<void>(`${projectsUrl}/${projectId}/floors/${floorId}`));
    }

    /** Scene of a floor of the working copy, or of a saved version when `versionId` is given. */
    public async getScene(projectId: number, floorId: number, versionId: number | null = null): Promise<Scene> {
        const base = versionId === null ? `${projectsUrl}/${projectId}` : `${projectsUrl}/${projectId}/versions/${versionId}`;
        return firstValueFrom(this.http.get<Scene>(`${base}/floors/${floorId}/scene`));
    }

    public async listVersions(projectId: number): Promise<ProjectVersion[]> {
        return firstValueFrom(this.http.get<ProjectVersion[]>(`${projectsUrl}/${projectId}/versions`));
    }

    public async getVersion(projectId: number, versionId: number): Promise<ProjectVersionDetail> {
        return firstValueFrom(this.http.get<ProjectVersionDetail>(`${projectsUrl}/${projectId}/versions/${versionId}`));
    }

    public async saveVersion(projectId: number, note: string | null): Promise<ProjectVersion> {
        return firstValueFrom(this.http.post<ProjectVersion>(`${projectsUrl}/${projectId}/versions`, {note}));
    }

    /** Replaces the working copy with the version; resolves to the version auto-saved just before. */
    public async restoreVersion(projectId: number, versionId: number): Promise<ProjectVersion> {
        return firstValueFrom(this.http.post<ProjectVersion>(`${projectsUrl}/${projectId}/versions/${versionId}/restore`, {}));
    }
}
