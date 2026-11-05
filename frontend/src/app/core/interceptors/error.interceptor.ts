import {HttpContextToken, HttpErrorResponse, HttpEvent, HttpHandlerFn, HttpRequest} from '@angular/common/http';
import {inject} from '@angular/core';
import {AlertService} from '@app/services/alert.service';
import {environment} from '@environments/environment';
import {TranslateService} from '@ngx-translate/core';
import {catchError, Observable, throwError} from 'rxjs';

/** Set on requests whose caller shows the error itself (e.g. inline in a form). */
export const SkipErrorToast = new HttpContextToken<boolean>(() => false);

/**
 * Shows failed API calls as an error toast with the server's message (in the language shown, `languageInterceptor`).
 * An error without one (the server unreachable, a proxy's page) gets a translated message of the kind, so
 * `errorMessage()` reads one for the callers showing it themselves too. 401s are left to `jwtInterceptor`.
 */
export function errorInterceptor(req: HttpRequest<unknown>, next: HttpHandlerFn): Observable<HttpEvent<unknown>> {
    if (!req.url.startsWith(environment.apiUrl)) {
        return next(req);
    }
    const alerts = inject(AlertService);
    const translate = inject(TranslateService);
    const toast = !req.context.get(SkipErrorToast);

    return next(req).pipe(
        catchError((caught: unknown) => {
            let err = caught;
            if (err instanceof HttpErrorResponse && serverMessage(err) === null) {
                const message = translate.instant(err.status === 0 ? 'errors.unreachable' : 'errors.unexpected') as string;
                err = new HttpErrorResponse({
                    error: {code: err.status, message},
                    headers: err.headers,
                    status: err.status,
                    statusText: err.statusText,
                    url: err.url ?? undefined,
                });
            }
            if (toast && err instanceof HttpErrorResponse && err.status !== 401) {
                alerts.error(errorMessage(err));
            }
            return throwError(() => err);
        }),
    );
}

/** The message of a failed API call: the server's, or the one `errorInterceptor` put in its place. */
export function errorMessage(err: HttpErrorResponse): string {
    return serverMessage(err) ?? (err.status === 0 ? 'The server is not reachable.' : 'An unexpected error occurred.');
}

function serverMessage(err: HttpErrorResponse): string | null {
    const body: unknown = err.error;
    return typeof body === 'object' && body !== null && 'message' in body && typeof body.message === 'string' ? body.message : null;
}
