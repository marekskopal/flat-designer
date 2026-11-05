import {HttpClient, HttpContext, HttpErrorResponse, provideHttpClient, withInterceptors} from '@angular/common/http';
import {HttpTestingController, provideHttpClientTesting} from '@angular/common/http/testing';
import {LOCALE_ID, provideZonelessChangeDetection} from '@angular/core';
import {TestBed} from '@angular/core/testing';
import {AlertService} from '@app/services/alert.service';
import {LanguageService} from '@app/services/language.service';
import {firstValueFrom} from 'rxjs';

import {provideTestTranslations} from '../../../test-translations';
import {errorInterceptor, errorMessage, SkipErrorToast} from './error.interceptor';
import {languageInterceptor} from './language.interceptor';

describe('errorInterceptor and languageInterceptor', () => {
    let http: HttpClient;
    let httpMock: HttpTestingController;
    let alerts: {error: ReturnType<typeof vi.fn>};

    beforeEach(async () => {
        alerts = {error: vi.fn()};
        TestBed.configureTestingModule({
            providers: [
                provideZonelessChangeDetection(),
                provideHttpClient(withInterceptors([languageInterceptor, errorInterceptor])),
                provideHttpClientTesting(),
                provideTestTranslations(),
                {provide: LOCALE_ID, useValue: 'cs'},
                {provide: AlertService, useValue: alerts},
            ],
        });
        http = TestBed.inject(HttpClient);
        httpMock = TestBed.inject(HttpTestingController);
        await TestBed.inject(LanguageService).init();
    });

    afterEach(() => {
        httpMock.verify();
        localStorage.clear();
    });

    async function fail(request: Promise<unknown>): Promise<HttpErrorResponse> {
        return request.then(() => {
            throw new Error('The request succeeded.');
        }, (err: unknown) => err as HttpErrorResponse);
    }

    it('asks the API in the language shown and toasts its message', async () => {
        const request = fail(firstValueFrom(http.get('/api/projects')));
        const sent = httpMock.expectOne('/api/projects');
        expect(sent.request.headers.get('Accept-Language')).toBe('cs');
        sent.flush({code: 404, message: 'Projekt nebyl nalezen.'}, {status: 404, statusText: 'Not Found'});

        expect(errorMessage(await request)).toBe('Projekt nebyl nalezen.');
        expect(alerts.error).toHaveBeenCalledWith('Projekt nebyl nalezen.');
    });

    it('gives an error without a message a translated one, also where the caller shows it', async () => {
        const request = fail(firstValueFrom(http.get('/api/projects', {context: new HttpContext().set(SkipErrorToast, true)})));
        httpMock.expectOne('/api/projects').error(new ProgressEvent('error'), {status: 0});

        expect(errorMessage(await request)).toBe('Server není dostupný.');
        expect(alerts.error).not.toHaveBeenCalled();
    });

    it('leaves other requests alone', async () => {
        const request = firstValueFrom(http.get('i18n/x.json'));
        const sent = httpMock.expectOne('i18n/x.json');
        expect(sent.request.headers.has('Accept-Language')).toBe(false);
        sent.flush({});
        await request;
    });
});
