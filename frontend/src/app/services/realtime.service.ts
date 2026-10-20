import {HttpClient} from '@angular/common/http';
import {inject, Injectable} from '@angular/core';
import {ProjectUpdate} from '@app/models/realtime';
import {environment} from '@environments/environment';
import {firstValueFrom, Observable} from 'rxjs';

interface Subscription {
    hubUrl: string;
    topicPrefix: string;
}

/** Delay before re-subscribing after the hub closed the stream (e.g. the cookie expired). */
const RetryDelay = 3000;

/**
 * Live updates of a project from the Mercure hub. Subscribing first asks the API for the hub cookie, then
 * listens with an EventSource; when the hub closes the stream (expired cookie, restart) it subscribes again.
 */
@Injectable({providedIn: 'root'})
export class RealtimeService {
    private readonly http = inject(HttpClient);

    public projectUpdates(projectId: number): Observable<ProjectUpdate> {
        return new Observable<ProjectUpdate>((subscriber) => {
            let source: EventSource | null = null;
            let retry: ReturnType<typeof setTimeout> | null = null;
            let closed = false;

            const connect = async (): Promise<void> => {
                try {
                    const subscription = await firstValueFrom(
                        this.http.post<Subscription>(`${environment.apiUrl}/realtime/subscription`, {}),
                    );
                    if (closed) {
                        return;
                    }
                    const topic = encodeURIComponent(`${subscription.topicPrefix}${projectId}`);
                    source = new EventSource(`${subscription.hubUrl}?match=${topic}`, {withCredentials: true});
                    source.onmessage = (event: MessageEvent<string>): void => {
                        const update = parseUpdate(event.data);
                        if (update !== null && update.projectId === projectId) {
                            subscriber.next(update);
                        }
                    };
                    source.onerror = (): void => {
                        // The browser retries on its own while CONNECTING; CLOSED needs a fresh cookie.
                        if (source?.readyState === EventSource.CLOSED) {
                            scheduleRetry();
                        }
                    };
                } catch {
                    scheduleRetry();
                }
            };

            const scheduleRetry = (): void => {
                source?.close();
                source = null;
                if (!closed && retry === null) {
                    retry = setTimeout(() => {
                        retry = null;
                        void connect();
                    }, RetryDelay);
                }
            };

            void connect();

            return (): void => {
                closed = true;
                source?.close();
                if (retry !== null) {
                    clearTimeout(retry);
                }
            };
        });
    }
}

const UpdateTypes: readonly string[] = ['projectChanged', 'definitionChanged', 'materialChanged', 'detailsChanged'] satisfies ProjectUpdate['type'][];

function parseUpdate(data: string): ProjectUpdate | null {
    try {
        const parsed: unknown = JSON.parse(data);
        const type = typeof parsed === 'object' && parsed !== null ? (parsed as ProjectUpdate).type : null;
        return type !== null && UpdateTypes.includes(type) ? (parsed as ProjectUpdate) : null;
    } catch {
        return null;
    }
}
