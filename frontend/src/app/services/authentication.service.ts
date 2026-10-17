import {HttpClient, HttpContext} from '@angular/common/http';
import {computed, inject, Injectable, signal} from '@angular/core';
import {Router} from '@angular/router';
import {SkipErrorToast} from '@app/core/interceptors/error.interceptor';
import {Authentication} from '@app/models/authentication';
import {CurrentUserService} from '@app/services/current-user.service';
import {StorageService} from '@app/services/storage.service';
import {environment} from '@environments/environment';
import {firstValueFrom} from 'rxjs';

const StorageKey = 'authentication';

/** Sign-in, sign-up and password forms show their errors inline. */
const inlineErrors = (): {context: HttpContext} => ({context: new HttpContext().set(SkipErrorToast, true)});

/** Holds the JWT pair (persisted in localStorage) and signs in, up and out. */
@Injectable({providedIn: 'root'})
export class AuthenticationService {
    private readonly http = inject(HttpClient);
    private readonly router = inject(Router);
    private readonly storage = inject(StorageService);
    private readonly currentUser = inject(CurrentUserService);

    public readonly authentication = signal<Authentication | null>(this.storage.get<Authentication>(StorageKey));
    public readonly isLoggedIn = computed(() => this.authentication() !== null);

    public async login(email: string, password: string): Promise<void> {
        this.setAuthentication(
            await firstValueFrom(this.http.post<Authentication>(`${environment.apiUrl}/authentication/login`, {email, password}, inlineErrors())),
        );
    }

    /**
     * The sign-up response is the same whether or not the email was registered already, so the session
     * is established by the follow-up login.
     */
    public async signUp(email: string, password: string, name: string): Promise<void> {
        await firstValueFrom(this.http.post<void>(`${environment.apiUrl}/authentication/sign-up`, {email, password, name}, inlineErrors()));
        await this.login(email, password);
    }

    public async refreshToken(): Promise<Authentication> {
        const current = this.authentication();
        if (current === null) {
            throw new Error('Not authenticated.');
        }
        const auth = await firstValueFrom(
            this.http.post<Authentication>(`${environment.apiUrl}/authentication/refresh-token`, {refreshToken: current.refreshToken}),
        );
        this.setAuthentication(auth);
        return auth;
    }

    /** The server revokes all earlier tokens on a password change and returns a fresh pair for this session. */
    public async changePassword(currentPassword: string, newPassword: string): Promise<void> {
        this.setAuthentication(
            await firstValueFrom(
                this.http.post<Authentication>(`${environment.apiUrl}/current-user/password`, {currentPassword, newPassword}, inlineErrors()),
            ),
        );
    }

    /** Tokens are stateless, so signing out only forgets them locally. */
    public logout(): void {
        this.storage.remove(StorageKey);
        this.authentication.set(null);
        this.currentUser.clear();
        void this.router.navigate(['/login']);
    }

    private setAuthentication(auth: Authentication): void {
        this.storage.set(StorageKey, auth);
        this.authentication.set(auth);
    }
}
