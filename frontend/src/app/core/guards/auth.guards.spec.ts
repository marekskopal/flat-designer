import {provideZonelessChangeDetection} from '@angular/core';
import {TestBed} from '@angular/core/testing';
import {ActivatedRouteSnapshot, provideRouter, Router, RouterStateSnapshot, UrlTree} from '@angular/router';
import {User} from '@app/models/user';
import {AuthenticationService} from '@app/services/authentication.service';
import {CurrentUserService} from '@app/services/current-user.service';
import {afterEach, describe, expect, it} from 'vitest';

import {adminGuard, authGuard, guestGuard} from './auth.guards';

const user: User = {id: 1, email: 'jane@example.com', name: 'Jane', role: 'User', lastLoginAt: null, createdAt: '2026-10-17T00:00:00+00:00'};

function setup(options: {loggedIn: boolean; user?: User | Error}): Router {
    TestBed.configureTestingModule({
        providers: [
            provideZonelessChangeDetection(),
            provideRouter([]),
            {provide: AuthenticationService, useValue: {isLoggedIn: (): boolean => options.loggedIn}},
            {
                provide: CurrentUserService,
                useValue: {
                    get: async (): Promise<User> => {
                        if (options.user instanceof Error || options.user === undefined) {
                            throw options.user ?? new Error('no user');
                        }
                        return options.user;
                    },
                },
            },
        ],
    });
    return TestBed.inject(Router);
}

async function run(guard: typeof authGuard, url = '/'): Promise<unknown> {
    return TestBed.runInInjectionContext(() => guard({} as ActivatedRouteSnapshot, {url} as RouterStateSnapshot));
}

function serialize(router: Router, result: unknown): string {
    expect(result).toBeInstanceOf(UrlTree);
    return router.serializeUrl(result as UrlTree);
}

describe('auth guards', () => {
    afterEach(() => TestBed.resetTestingModule());

    it('authGuard lets signed-in users through and sends others to login with a return URL', async () => {
        setup({loggedIn: true});
        expect(await run(authGuard)).toBe(true);
        TestBed.resetTestingModule();

        const router = setup({loggedIn: false});
        expect(serialize(router, await run(authGuard, '/account'))).toBe('/login?returnUrl=%2Faccount');
    });

    it('guestGuard sends signed-in users to the app', async () => {
        const router = setup({loggedIn: true});
        expect(serialize(router, await run(guestGuard))).toBe('/');
        TestBed.resetTestingModule();

        setup({loggedIn: false});
        expect(await run(guestGuard)).toBe(true);
    });

    it('adminGuard admits admins only', async () => {
        setup({loggedIn: true, user: {...user, role: 'Admin'}});
        expect(await run(adminGuard)).toBe(true);
        TestBed.resetTestingModule();

        const router = setup({loggedIn: true, user});
        expect(serialize(router, await run(adminGuard))).toBe('/');
        TestBed.resetTestingModule();

        const failing = setup({loggedIn: true, user: new Error('offline')});
        expect(serialize(failing, await run(adminGuard))).toBe('/');
    });
});
