import {HttpEvent, HttpHandlerFn, HttpRequest} from '@angular/common/http';
import {environment} from '@environments/environment';
import {Observable} from 'rxjs';

/** Identifies this browser tab, so it can tell its own changes from others' in live updates. */
export const originClientId: string = crypto.randomUUID();

export function originInterceptor(req: HttpRequest<unknown>, next: HttpHandlerFn): Observable<HttpEvent<unknown>> {
    if (!req.url.startsWith(environment.apiUrl)) {
        return next(req);
    }
    return next(req.clone({setHeaders: {'X-Origin-Client-Id': originClientId}}));
}
