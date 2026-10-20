import {HttpClient} from '@angular/common/http';
import {inject, Injectable} from '@angular/core';
import {ModelAsset, ModelAssetInput} from '@app/models/model-asset';
import {environment} from '@environments/environment';
import {firstValueFrom} from 'rxjs';

const modelsUrl = `${environment.apiUrl}/models`;

/** The 3D model library (global, my library, a project's) and the models' files for the viewer. */
@Injectable({providedIn: 'root'})
export class ModelAssetService {
    private readonly http = inject(HttpClient);

    /** Global ones and my library; with a project, what its items may use (global, the project's, its owner's library). */
    public async list(projectId?: number): Promise<ModelAsset[]> {
        const params: Record<string, number> = projectId === undefined ? {} : {projectId};
        return firstValueFrom(this.http.get<ModelAsset[]>(modelsUrl, {params}));
    }

    /** Uploads a `.glb` file with its describing fields. */
    public async upload(file: File, input: ModelAssetInput): Promise<ModelAsset> {
        const body = new FormData();
        body.append('file', file, file.name);
        for (const [key, value] of Object.entries(input)) {
            if (value !== undefined && value !== null) {
                body.append(key, String(value));
            }
        }
        return firstValueFrom(this.http.post<ModelAsset>(modelsUrl, body));
    }

    public async update(modelId: number, input: Omit<ModelAssetInput, 'scope' | 'projectId'>): Promise<ModelAsset> {
        return firstValueFrom(this.http.put<ModelAsset>(`${modelsUrl}/${modelId}`, input));
    }

    public async delete(modelId: number): Promise<void> {
        await firstValueFrom(this.http.delete<void>(`${modelsUrl}/${modelId}`));
    }

    /** A model's `.glb` with the user's sign-in. */
    public async content(modelId: number): Promise<ArrayBuffer> {
        return firstValueFrom(this.http.get(`${modelsUrl}/${modelId}/content`, {responseType: 'arraybuffer'}));
    }

    /** A model drawn in a share link's scene, for its guests (no sign-in). */
    public async sharedContent(token: string, modelId: number): Promise<ArrayBuffer> {
        return firstValueFrom(this.http.get(`${environment.apiUrl}/shares/${token}/models/${modelId}/content`, {responseType: 'arraybuffer'}));
    }
}
