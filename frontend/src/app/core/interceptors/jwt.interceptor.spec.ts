import {HttpClient, HttpErrorResponse, provideHttpClient, withInterceptors} from '@angular/common/http';
import {HttpTestingController, provideHttpClientTesting} from '@angular/common/http/testing';
import {provideZonelessChangeDetection, signal} from '@angular/core';
import {TestBed} from '@angular/core/testing';
import {Authentication} from '@app/models/authentication';
import {AuthenticationService} from '@app/services/authentication.service';
import {firstValueFrom} from 'rxjs';
import {afterEach, describe, expect, it, vi} from 'vitest';

import {jwtInterceptor} from './jwt.interceptor';

const tokens: Authentication = {accessToken: 'access-1', refreshToken: 'refresh-1', userId: 1};

function setup(loggedIn: boolean, refreshResult: 'ok' | 'fail' = 'ok'): {
    http: HttpClient;
    httpMock: HttpTestingController;
    auth: {refreshToken: ReturnType<typeof vi.fn>; logout: ReturnType<typeof vi.fn>};
} {
    const authentication = signal<Authentication | null>(loggedIn ? tokens : null);
    const auth = {
        authentication,
        isLoggedIn: (): boolean => authentication() !== null,
        refreshToken: vi.fn(async () => {
            if (refreshResult === 'fail') {
                throw new HttpErrorResponse({status: 401});
            }
            const next = {...tokens, accessToken: 'access-2'};
            authentication.set(next);
            return next;
        }),
        logout: vi.fn(() => authentication.set(null)),
    };

    TestBed.configureTestingModule({
        providers: [
            provideZonelessChangeDetection(),
            provideHttpClient(withInterceptors([jwtInterceptor])),
            provideHttpClientTesting(),
            {provide: AuthenticationService, useValue: auth},
        ],
    });

    return {http: TestBed.inject(HttpClient), httpMock: TestBed.inject(HttpTestingController), auth};
}

async function flush(): Promise<void> {
    await new Promise<void>((resolve) => setTimeout(resolve, 0));
}

describe('jwtInterceptor', () => {
    afterEach(() => {
        TestBed.inject(HttpTestingController).verify();
        TestBed.resetTestingModule();
    });

    it('adds the bearer token to API requests only', () => {
        const {http, httpMock} = setup(true);

        http.get('/api/current-user').subscribe();
        http.get('/assets/textures/oak.jpg').subscribe();

        expect(httpMock.expectOne('/api/current-user').request.headers.get('Authorization')).toBe('Bearer access-1');
        expect(httpMock.expectOne('/assets/textures/oak.jpg').request.headers.has('Authorization')).toBe(false);
    });

    it('sends no header when signed out', () => {
        const {http, httpMock} = setup(false);

        http.get('/api/health').subscribe();

        expect(httpMock.expectOne('/api/health').request.headers.has('Authorization')).toBe(false);
    });

    it('refreshes once on concurrent 401s and retries with the new token', async () => {
        const {http, httpMock, auth} = setup(true);

        const first = firstValueFrom(http.get('/api/a'));
        const second = firstValueFrom(http.get('/api/b'));
        httpMock.expectOne('/api/a').flush(null, {status: 401, statusText: 'Unauthorized'});
        httpMock.expectOne('/api/b').flush(null, {status: 401, statusText: 'Unauthorized'});
        await flush();

        const retryA = httpMock.expectOne('/api/a');
        const retryB = httpMock.expectOne('/api/b');
        expect(retryA.request.headers.get('Authorization')).toBe('Bearer access-2');
        retryA.flush({ok: 'a'});
        retryB.flush({ok: 'b'});

        expect(await first).toEqual({ok: 'a'});
        expect(await second).toEqual({ok: 'b'});
        expect(auth.refreshToken).toHaveBeenCalledTimes(1);
    });

    it('signs out when the refresh fails', async () => {
        const {http, httpMock, auth} = setup(true, 'fail');

        const result = firstValueFrom(http.get('/api/a'));
        httpMock.expectOne('/api/a').flush(null, {status: 401, statusText: 'Unauthorized'});

        await expect(result).rejects.toBeInstanceOf(HttpErrorResponse);
        expect(auth.logout).toHaveBeenCalled();
    });

    it('passes other errors through without refreshing', async () => {
        const {http, httpMock, auth} = setup(true);

        const result = firstValueFrom(http.get('/api/a'));
        httpMock.expectOne('/api/a').flush(null, {status: 403, statusText: 'Forbidden'});

        await expect(result).rejects.toMatchObject({status: 403});
        expect(auth.refreshToken).not.toHaveBeenCalled();
    });
});
