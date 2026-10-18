import {HttpClient} from '@angular/common/http';
import {inject, Injectable} from '@angular/core';
import {CutList} from '@app/models/cut-list';
import {FloorInput, Project, ProjectFile, ProjectInput, ProjectVersion, ProjectVersionDetail} from '@app/models/project';
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

    /** The cut list of the project's Custom furniture, or of one floor. */
    public async cutList(projectId: number, floorId?: number): Promise<CutList> {
        const path = floorId === undefined ? `${projectsUrl}/${projectId}/cut-list` : `${projectsUrl}/${projectId}/floors/${floorId}/cut-list`;
        return firstValueFrom(this.http.get<CutList>(path));
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

    public async listFiles(projectId: number): Promise<ProjectFile[]> {
        return firstValueFrom(this.http.get<ProjectFile[]>(`${projectsUrl}/${projectId}/files`));
    }

    public async uploadFile(projectId: number, file: File): Promise<ProjectFile> {
        const body = new FormData();
        body.append('file', file, file.name);
        return firstValueFrom(this.http.post<ProjectFile>(`${projectsUrl}/${projectId}/files`, body));
    }

    /** Fetched with the auth header, so it can't be a plain link; use an object URL to show or save it. */
    public async downloadFile(projectId: number, fileId: number): Promise<Blob> {
        return firstValueFrom(this.http.get(`${projectsUrl}/${projectId}/files/${fileId}/content`, {responseType: 'blob'}));
    }

    public async deleteFile(projectId: number, fileId: number): Promise<void> {
        await firstValueFrom(this.http.delete<void>(`${projectsUrl}/${projectId}/files/${fileId}`));
    }
}
