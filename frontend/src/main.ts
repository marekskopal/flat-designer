import {registerLocaleData} from '@angular/common';
import {provideHttpClient, withFetch, withInterceptors} from '@angular/common/http';
import localeCs from '@angular/common/locales/cs';
import {enableProdMode, inject, LOCALE_ID, provideAppInitializer, provideZonelessChangeDetection} from '@angular/core';
import {bootstrapApplication} from '@angular/platform-browser';
import {provideRouter, TitleStrategy, withComponentInputBinding} from '@angular/router';
import {AppComponent} from '@app/app.component';
import {appRoutes} from '@app/app.routes';
import {errorInterceptor} from '@app/core/interceptors/error.interceptor';
import {jwtInterceptor} from '@app/core/interceptors/jwt.interceptor';
import {languageInterceptor} from '@app/core/interceptors/language.interceptor';
import {originInterceptor} from '@app/core/interceptors/origin.interceptor';
import {initialLocale, LocaleStorageKey} from '@app/core/locale';
import {LanguageService} from '@app/services/language.service';
import {StorageService} from '@app/services/storage.service';
import {TranslatedTitleStrategy} from '@app/shared/i18n/translated-title.strategy';
import {environment} from '@environments/environment';
import {provideTranslateService} from '@ngx-translate/core';
import {provideTranslateHttpLoader} from '@ngx-translate/http-loader';

if (environment.production) {
    enableProdMode();
}

registerLocaleData(localeCs);

bootstrapApplication(AppComponent, {
    providers: [
        provideHttpClient(withFetch(), withInterceptors([originInterceptor, languageInterceptor, jwtInterceptor, errorInterceptor])),
        provideRouter(appRoutes, withComponentInputBinding()),
        {provide: TitleStrategy, useClass: TranslatedTitleStrategy},
        // Resolved against <base href>, so the texts load under /app/ too.
        provideTranslateService({loader: provideTranslateHttpLoader({prefix: new URL('i18n/', document.baseURI).href, suffix: '.json'})}),
        provideAppInitializer(() => inject(LanguageService).init()),
        {provide: LOCALE_ID, useFactory: (): string => initialLocale(inject(StorageService).get(LocaleStorageKey))},
        provideZonelessChangeDetection(),
    ],
}).catch((err: unknown) => console.error(err));
