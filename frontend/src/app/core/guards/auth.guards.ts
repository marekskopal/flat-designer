import {inject} from '@angular/core';
import {CanActivateFn, Router} from '@angular/router';
import {AuthenticationService} from '@app/services/authentication.service';
import {CurrentUserService} from '@app/services/current-user.service';

/** Signed-in users only; others go to the login page and come back afterwards. */
export const authGuard: CanActivateFn = (_route, state) => {
    if (inject(AuthenticationService).isLoggedIn()) {
        return true;
    }
    return inject(Router).createUrlTree(['/login'], {queryParams: {returnUrl: state.url}});
};

/** Login and sign-up pages: a signed-in user is sent to the app. */
export const guestGuard: CanActivateFn = () => {
    if (inject(AuthenticationService).isLoggedIn()) {
        return inject(Router).createUrlTree(['/']);
    }
    return true;
};

export const adminGuard: CanActivateFn = async () => {
    const router = inject(Router);
    try {
        const user = await inject(CurrentUserService).get();
        return user.role === 'Admin' ? true : router.createUrlTree(['/']);
    } catch {
        return router.createUrlTree(['/']);
    }
};
