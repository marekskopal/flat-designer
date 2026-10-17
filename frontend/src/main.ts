import {provideHttpClient, withFetch, withInterceptors} from '@angular/common/http';
import {enableProdMode, provideZonelessChangeDetection} from '@angular/core';
import {bootstrapApplication} from '@angular/platform-browser';
import {provideRouter, withComponentInputBinding} from '@angular/router';
import {AppComponent} from '@app/app.component';
import {appRoutes} from '@app/app.routes';
import {errorInterceptor} from '@app/core/interceptors/error.interceptor';
import {jwtInterceptor} from '@app/core/interceptors/jwt.interceptor';
import {originInterceptor} from '@app/core/interceptors/origin.interceptor';
import {environment} from '@environments/environment';

if (environment.production) {
    enableProdMode();
}

bootstrapApplication(AppComponent, {
    providers: [
        provideHttpClient(withFetch(), withInterceptors([originInterceptor, jwtInterceptor, errorInterceptor])),
        provideRouter(appRoutes, withComponentInputBinding()),
        provideZonelessChangeDetection(),
    ],
}).catch((err: unknown) => console.error(err));
