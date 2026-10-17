import {HttpErrorResponse} from '@angular/common/http';
import {ChangeDetectionStrategy, Component, inject, OnInit, signal} from '@angular/core';
import {FormBuilder, ReactiveFormsModule, Validators} from '@angular/forms';
import {ActivatedRoute} from '@angular/router';
import {errorMessage} from '@app/core/interceptors/error.interceptor';
import {AuthenticationService} from '@app/services/authentication.service';
import {CurrentUserService} from '@app/services/current-user.service';
import {AuthorizeRequest, OAuthService} from '@app/services/oauth.service';

/**
 * OAuth consent for MCP clients (the server's `authorization_endpoint`). The client sends the user here
 * with its PKCE parameters; after sign-in and approval the browser goes back to the client's redirect
 * URI with the authorization code.
 */
@Component({
    selector: 'fd-oauth-authorize',
    imports: [ReactiveFormsModule],
    templateUrl: './oauth-authorize.component.html',
    styleUrl: '../authentication/auth-page.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OAuthAuthorizeComponent implements OnInit {
    protected readonly auth = inject(AuthenticationService);
    protected readonly currentUser = inject(CurrentUserService);
    private readonly oauth = inject(OAuthService);
    private readonly query = inject(ActivatedRoute).snapshot.queryParamMap;

    protected readonly clientName = signal<string | null>(null);
    protected readonly error = signal<string | null>(null);
    protected readonly busy = signal(false);
    protected readonly done = signal<'approved' | 'denied' | null>(null);
    protected readonly loginForm = inject(FormBuilder).nonNullable.group({
        email: ['', [Validators.required, Validators.email]],
        password: ['', Validators.required],
    });

    private request: AuthorizeRequest | null = null;

    public async ngOnInit(): Promise<void> {
        const clientId = this.query.get('client_id') ?? '';
        const redirectUri = this.query.get('redirect_uri') ?? '';
        const codeChallenge = this.query.get('code_challenge') ?? '';
        if (this.query.get('response_type') !== 'code' || clientId === '' || redirectUri === '' || codeChallenge === '') {
            this.error.set('This authorization link is incomplete. Start the connection again from your MCP client.');
            return;
        }

        this.request = {
            clientId,
            redirectUri,
            codeChallenge,
            codeChallengeMethod: this.query.get('code_challenge_method') ?? '',
            state: this.query.get('state') ?? '',
            resource: this.query.get('resource') ?? undefined,
        };

        try {
            this.clientName.set(await this.oauth.clientName(clientId));
            if (this.auth.isLoggedIn()) {
                await this.currentUser.get();
            }
        } catch (e: unknown) {
            const unknownClient = e instanceof HttpErrorResponse && e.status === 404;
            this.error.set(unknownClient ? 'Unknown client. Start the connection again from your MCP client.' : 'Could not load the request.');
        }
    }

    protected async signIn(): Promise<void> {
        if (this.loginForm.invalid) {
            return;
        }
        this.busy.set(true);
        this.error.set(null);
        try {
            const {email, password} = this.loginForm.getRawValue();
            await this.auth.login(email, password);
            await this.currentUser.load();
        } catch (e: unknown) {
            this.error.set(e instanceof HttpErrorResponse ? errorMessage(e) : 'Sign-in failed.');
        } finally {
            this.busy.set(false);
        }
    }

    protected async approve(): Promise<void> {
        if (this.request === null) {
            return;
        }
        this.busy.set(true);
        this.error.set(null);
        try {
            const result = await this.oauth.authorize(this.request);
            const target = new URL(result.redirectUri);
            // The server only accepts https (or loopback http) redirect URIs; check again before leaving.
            if (target.protocol !== 'https:' && target.protocol !== 'http:') {
                throw new Error('Unsafe redirect.');
            }
            target.searchParams.set('code', result.code);
            if (result.state !== '') {
                target.searchParams.set('state', result.state);
            }
            this.done.set('approved');
            window.location.href = target.toString();
        } catch (e: unknown) {
            this.error.set(e instanceof HttpErrorResponse ? errorMessage(e) : 'Authorization failed.');
            this.busy.set(false);
        }
    }

    protected deny(): void {
        this.done.set('denied');
    }
}
