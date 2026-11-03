import {NgOptimizedImage} from '@angular/common';
import {HttpErrorResponse} from '@angular/common/http';
import {ChangeDetectionStrategy, Component, inject, signal} from '@angular/core';
import {FormBuilder, ReactiveFormsModule, Validators} from '@angular/forms';
import {ActivatedRoute, Router, RouterLink} from '@angular/router';
import {GoogleSignInComponent} from '@app/authentication/google-sign-in.component';
import {errorMessage} from '@app/core/interceptors/error.interceptor';
import {AuthenticationOptions} from '@app/models/authentication';
import {AuthenticationService} from '@app/services/authentication.service';

@Component({
    selector: 'fd-login',
    imports: [GoogleSignInComponent, NgOptimizedImage, ReactiveFormsModule, RouterLink],
    templateUrl: './login.component.html',
    styleUrl: './auth-page.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LoginComponent {
    private readonly auth = inject(AuthenticationService);
    private readonly router = inject(Router);
    private readonly route = inject(ActivatedRoute);

    protected readonly saving = signal(false);
    protected readonly googleBusy = signal(false);
    protected readonly error = signal<string | null>(null);
    protected readonly options = signal<AuthenticationOptions | null>(null);
    protected readonly form = inject(FormBuilder).nonNullable.group({
        email: ['', [Validators.required, Validators.email]],
        password: ['', Validators.required],
    });

    public constructor() {
        void this.auth.options().then((options) => this.options.set(options));
    }

    protected async onSubmit(): Promise<void> {
        if (this.form.invalid) {
            return;
        }
        this.saving.set(true);
        this.error.set(null);
        try {
            const {email, password} = this.form.getRawValue();
            await this.auth.login(email, password);
            await this.router.navigateByUrl(this.returnUrl());
        } catch (e: unknown) {
            this.error.set(e instanceof HttpErrorResponse ? errorMessage(e) : 'Sign-in failed.');
        } finally {
            this.saving.set(false);
        }
    }

    protected async onGoogleCredential(idToken: string): Promise<void> {
        this.googleBusy.set(true);
        this.error.set(null);
        try {
            await this.auth.googleLogin(idToken);
            await this.router.navigateByUrl(this.returnUrl());
        } catch (e: unknown) {
            this.error.set(e instanceof HttpErrorResponse ? errorMessage(e) : 'Google sign-in failed.');
        } finally {
            this.googleBusy.set(false);
        }
    }

    protected onGoogleUnavailable(): void {
        this.options.update((options) => (options === null ? null : {...options, googleClientId: null}));
    }

    private returnUrl(): string {
        return this.route.snapshot.queryParamMap.get('returnUrl') ?? '/';
    }
}
