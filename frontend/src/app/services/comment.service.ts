import {HttpClient} from '@angular/common/http';
import {inject, Injectable} from '@angular/core';
import {Comment, CommentInput} from '@app/models/comment';
import {environment} from '@environments/environment';
import {firstValueFrom} from 'rxjs';

const projectsUrl = `${environment.apiUrl}/projects`;
/** Public endpoints, called without the access token (see `jwtInterceptor`). */
const sharesUrl = `${environment.apiUrl}/shares`;

/** The review comments of a project, and those a share link's guests read and write. */
@Injectable({providedIn: 'root'})
export class CommentService {
    private readonly http = inject(HttpClient);

    /** A floor's threads (oldest first, each followed by its replies), the resolved ones included. */
    public async list(projectId: number, floorId: number): Promise<Comment[]> {
        return firstValueFrom(this.http.get<Comment[]>(`${projectsUrl}/${projectId}/comments`, {params: {floorId}}));
    }

    public async create(projectId: number, input: CommentInput): Promise<Comment> {
        return firstValueFrom(this.http.post<Comment>(`${projectsUrl}/${projectId}/comments`, input));
    }

    /** Changes the text (the author only) and/or resolves or reopens a thread. */
    public async update(projectId: number, commentId: number, change: {body?: string; resolved?: boolean}): Promise<Comment> {
        return firstValueFrom(this.http.put<Comment>(`${projectsUrl}/${projectId}/comments/${commentId}`, change));
    }

    public async delete(projectId: number, commentId: number): Promise<void> {
        await firstValueFrom(this.http.delete<void>(`${projectsUrl}/${projectId}/comments/${commentId}`));
    }

    /** The open threads of a share link's floor, when the link shows them (empty otherwise). */
    public async listShared(token: string): Promise<Comment[]> {
        return firstValueFrom(this.http.get<Comment[]>(`${sharesUrl}/${token}/comments`));
    }

    /** A guest's comment, when the link takes them. */
    public async createShared(token: string, input: CommentInput): Promise<Comment> {
        return firstValueFrom(this.http.post<Comment>(`${sharesUrl}/${token}/comments`, input));
    }
}
