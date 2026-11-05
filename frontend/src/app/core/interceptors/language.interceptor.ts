import {HttpEvent, HttpHandlerFn, HttpRequest} from '@angular/common/http';
import {inject} from '@angular/core';
import {LanguageService} from '@app/services/language.service';
import {environment} from '@environments/environment';
import {Observable} from 'rxjs';

/** Asks the API for its messages in the language shown (the backend's `LocalizedErrorHandler`), not the browser's. */
export function languageInterceptor(req: HttpRequest<unknown>, next: HttpHandlerFn): Observable<HttpEvent<unknown>> {
    if (!req.url.startsWith(environment.apiUrl)) {
        return next(req);
    }
    return next(req.clone({setHeaders: {'Accept-Language': inject(LanguageService).current()}}));
}
