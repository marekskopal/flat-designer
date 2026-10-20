import {HttpClient, HttpContext} from '@angular/common/http';
import {inject, Injectable} from '@angular/core';
import {SkipErrorToast} from '@app/core/interceptors/error.interceptor';
import {SharedView, ShareLink, ShareLinkInput} from '@app/models/share';
import {environment} from '@environments/environment';
import {firstValueFrom} from 'rxjs';

const projectsUrl = `${environment.apiUrl}/projects`;
/** Public endpoints, called without the access token (see `jwtInterceptor`). */
const sharesUrl = `${environment.apiUrl}/shares`;

/** Share links of a project (owner side) and the guest's read of one. */
@Injectable({providedIn: 'root'})
export class ShareService {
    private readonly http = inject(HttpClient);

    public async list(projectId: number): Promise<ShareLink[]> {
        return firstValueFrom(this.http.get<ShareLink[]>(`${projectsUrl}/${projectId}/share-links`));
    }

    /** Also saves a version of the project, which the link shows. */
    public async create(projectId: number, input: ShareLinkInput): Promise<ShareLink> {
        return firstValueFrom(this.http.post<ShareLink>(`${projectsUrl}/${projectId}/share-links`, input));
    }

    public async update(projectId: number, shareLinkId: number, input: ShareLinkInput): Promise<ShareLink> {
        return firstValueFrom(this.http.put<ShareLink>(`${projectsUrl}/${projectId}/share-links/${shareLinkId}`, input));
    }

    /** Saves a new version of the project and points the link at it; the URL and settings stay. */
    public async refresh(projectId: number, shareLinkId: number): Promise<ShareLink> {
        return firstValueFrom(this.http.post<ShareLink>(`${projectsUrl}/${projectId}/share-links/${shareLinkId}/refresh`, {}));
    }

    public async delete(projectId: number, shareLinkId: number): Promise<void> {
        await firstValueFrom(this.http.delete<void>(`${projectsUrl}/${projectId}/share-links/${shareLinkId}`));
    }

    /** An image a share link's scene shows as a picture, for its guests (no sign-in); quiet when it is gone. */
    public async sharedPicture(token: string, fileId: number): Promise<Blob> {
        return firstValueFrom(
            this.http.get(`${sharesUrl}/${token}/pictures/${fileId}/content`, {
                responseType: 'blob',
                context: new HttpContext().set(SkipErrorToast, true),
            }),
        );
    }

    /** The guest page shows its own error (unknown or expired link), so no toast. */
    public async getShared(token: string): Promise<SharedView> {
        return firstValueFrom(this.http.get<SharedView>(`${sharesUrl}/${token}`, {context: new HttpContext().set(SkipErrorToast, true)}));
    }
}
