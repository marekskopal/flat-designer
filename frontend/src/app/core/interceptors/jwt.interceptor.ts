import {HttpErrorResponse, HttpEvent, HttpHandlerFn, HttpRequest} from '@angular/common/http';
import {inject} from '@angular/core';
import {Authentication} from '@app/models/authentication';
import {AuthenticationService} from '@app/services/authentication.service';
import {environment} from '@environments/environment';
import {catchError, from, Observable, shareReplay, switchMap, throwError} from 'rxjs';

const refreshTokenUrl = `${environment.apiUrl}/authentication/refresh-token`;

/** Concurrent 401s share one refresh call. */
let refreshInFlight: Observable<Authentication> | null = null;

/**
 * Adds the access token to API calls. On 401 it refreshes the token pair once and retries; when the
 * refresh fails too, the user is signed out.
 */
export function jwtInterceptor(req: HttpRequest<unknown>, next: HttpHandlerFn): Observable<HttpEvent<unknown>> {
    const auth = inject(AuthenticationService);

    return next(withAuthHeader(req, auth)).pipe(
        catchError((err: unknown) => {
            if (err instanceof HttpErrorResponse && err.status === 401 && auth.isLoggedIn() && req.url !== refreshTokenUrl) {
                return refreshAndRetry(req, next, auth);
            }
            return throwError(() => err);
        }),
    );
}

function withAuthHeader(req: HttpRequest<unknown>, auth: AuthenticationService): HttpRequest<unknown> {
    const token = auth.authentication()?.accessToken;
    if (token === undefined || !req.url.startsWith(environment.apiUrl)) {
        return req;
    }
    return req.clone({setHeaders: {Authorization: `Bearer ${token}`}});
}

function refreshAndRetry(req: HttpRequest<unknown>, next: HttpHandlerFn, auth: AuthenticationService): Observable<HttpEvent<unknown>> {
    if (refreshInFlight === null) {
        const refresh = from(auth.refreshToken()).pipe(shareReplay(1));
        refreshInFlight = refresh;
        refresh.subscribe({
            complete: () => (refreshInFlight = null),
            error: () => (refreshInFlight = null),
        });
    }

    return refreshInFlight.pipe(
        catchError((err: unknown) => {
            auth.logout();
            return throwError(() => err);
        }),
        switchMap(() => next(withAuthHeader(req, auth))),
    );
}
