import {HttpContextToken, HttpErrorResponse, HttpEvent, HttpHandlerFn, HttpRequest} from '@angular/common/http';
import {inject} from '@angular/core';
import {AlertService} from '@app/services/alert.service';
import {environment} from '@environments/environment';
import {catchError, Observable, throwError} from 'rxjs';

/** Set on requests whose caller shows the error itself (e.g. inline in a form). */
export const SkipErrorToast = new HttpContextToken<boolean>(() => false);

/** Shows failed API calls as an error toast with the server's message. 401s are left to `jwtInterceptor`. */
export function errorInterceptor(req: HttpRequest<unknown>, next: HttpHandlerFn): Observable<HttpEvent<unknown>> {
    const alerts = inject(AlertService);

    if (!req.url.startsWith(environment.apiUrl) || req.context.get(SkipErrorToast)) {
        return next(req);
    }

    return next(req).pipe(
        catchError((err: unknown) => {
            if (err instanceof HttpErrorResponse && err.status !== 401) {
                alerts.error(errorMessage(err));
            }
            return throwError(() => err);
        }),
    );
}

export function errorMessage(err: HttpErrorResponse): string {
    const body: unknown = err.error;
    if (typeof body === 'object' && body !== null && 'message' in body && typeof body.message === 'string') {
        return body.message;
    }
    return err.status === 0 ? 'The server is not reachable.' : 'An unexpected error occurred.';
}
